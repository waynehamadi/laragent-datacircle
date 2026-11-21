<?php

declare(strict_types=1);

namespace Datacircle\LarAgent\Tests;

use Datacircle\LarAgent\Datacircle;
use Datacircle\LarAgent\Tools\BestPlans;
use Datacircle\LarAgent\Tools\ListApis;
use Datacircle\LarAgent\Tools\Provider;
use Datacircle\LarAgent\Tools\SearchPlans;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LarAgent\Agent;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Drivers\Anthropic\ClaudeDriver;
use LarAgent\Drivers\OpenAi\OpenAiCompatible;

class ToolsTest extends TestCase
{
    public function test_four_tools_with_their_names_and_schemas(): void
    {
        $tools = Datacircle::tools();

        $this->assertContainsOnlyInstancesOf(ToolInterface::class, $tools);
        $this->assertSame(
            ['datacircle_list_apis', 'datacircle_best_plans', 'datacircle_search_plans', 'datacircle_provider'],
            array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools),
        );
        $this->assertSame(['slug'], (new BestPlans)->getRequired());
        $this->assertSame([], (new SearchPlans)->getRequired());
        $this->assertSame(['score', 'provider', 'price'], (new SearchPlans)->getProperties()['sort']['enum']);
    }

    public function test_an_agent_takes_the_classes_and_drivers_send_their_schemas(): void
    {
        $agent = new class('t') extends Agent
        {
            protected $tools = Datacircle::TOOLS;
        };

        $tools = $agent->getTools();
        $this->assertSame(['datacircle_list_apis', 'datacircle_best_plans', 'datacircle_search_plans', 'datacircle_provider'],
            array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools));

        $openAi = (new OpenAiCompatible(['apiKey' => 'x']))->formatToolForPayload($tools[1]);
        $this->assertSame('datacircle_best_plans', $openAi['function']['name']);
        $this->assertSame(['slug'], $openAi['function']['parameters']['required']);
        $this->assertSame(50, $openAi['function']['parameters']['properties']['limit']['maximum']);
        $claude = (new ClaudeDriver(['apiKey' => 'x']))->formatToolForPayload($tools[3]);
        $this->assertSame('string', $claude['input_schema']['properties']['slug']['type']);
    }

    public function test_list_apis_puts_the_most_words_matched_first(): void
    {
        $client = $this->client(self::answer([
            ['api' => 'Employee phone', 'slug' => 'phone-finder', 'plans' => 3, 'providers' => 2, 'terms' => 'dropped'],
            ['api' => 'Employee email', 'slug' => 'email-finder', 'plans' => 5, 'providers' => 4],
            ['api' => 'Email validation', 'slug' => 'email-verification', 'plans' => 2, 'providers' => 2],
        ]));

        $result = json_decode((new ListApis($client))->execute(['query' => 'email finder']), true);

        $this->assertSame('https://api.datacircle.dev/v1/apis', $this->url());
        $this->assertSame(3, $result['total']);
        $this->assertSame(['email-finder', 'phone-finder', 'email-verification'], array_column($result['apis'], 'slug'));
        $this->assertArrayNotHasKey('terms', $result['apis'][1]);
    }

    public function test_best_plans_sends_the_slug_and_limit_and_briefs_each_plan(): void
    {
        $client = $this->client(self::answer([
            'api' => 'Employee email', 'slug' => 'email-finder', 'total' => 1,
            'plans' => [['rank' => 1, 'provider' => 'A', 'plan' => 'Pro', 'score' => 80.5, 'perThousand' => 2.5, 'aggregators' => ['x']]],
        ]));

        $result = json_decode((new BestPlans($client))->execute(['slug' => 'Email Finder', 'limit' => '70']), true);

        $this->assertSame('https://api.datacircle.dev/v1/apis/email-finder/best?limit=50', $this->url());
        $this->assertStringStartsWith('laragent-datacircle/', $this->sent[0]['request']->getHeaderLine('User-Agent'));
        $this->assertSame(['rank' => 1, 'provider' => 'A', 'plan' => 'Pro', 'score' => 80.5, 'perThousand' => 2.5], $result['plans'][0]);
    }

    public function test_a_missing_slug_asks_for_one_without_a_call(): void
    {
        $client = $this->client();

        $this->assertStringContainsString('Give an API\'s slug', (new BestPlans($client))->execute([]));
        $this->assertStringContainsString('Give a provider\'s slug', (new Provider($client))->execute(['slug' => ['x']]));
        $this->assertSame([], $this->sent);
    }

    public function test_an_answer_of_4xx_is_the_apis_words_for_the_model(): void
    {
        $client = $this->client(
            self::answer(['error' => 'no API has the slug nope'], 404),
            self::answer(['error' => 'no provider has the slug nope'], 404),
        );

        $best = json_decode((new BestPlans($client))->execute(['slug' => 'nope']), true);
        $provider = json_decode((new Provider($client))->execute(['slug' => 'nope']), true);

        $this->assertStringStartsWith('no API has the slug nope. datacircle_list_apis gives', $best['error']);
        $this->assertSame('no provider has the slug nope', $provider['error']);
    }

    public function test_a_network_failure_is_an_error_for_the_model_never_thrown(): void
    {
        $client = $this->client(new ConnectException('could not resolve host', new Request('GET', 'https://api.datacircle.dev/v1/apis')));

        $result = json_decode((new ListApis($client))->execute([]), true);

        $this->assertSame('datacircle: could not resolve host', $result['error']);
    }

    public function test_a_5xx_is_an_error_for_the_model(): void
    {
        $client = $this->client(self::answer(['error' => 'down'], 503));

        $this->assertSame(['error' => 'datacircle: 503 Service Unavailable'], json_decode((new Provider($client))->execute(['slug' => 'x']), true));
    }

    public function test_search_plans_takes_a_providers_name_sent_as_the_api(): void
    {
        $client = $this->client(
            self::answer(['error' => 'api must be one of: Employee profile, Employee email'], 400),
            self::answer(['total' => 1, 'offset' => 0, 'plans' => [['provider' => 'Bright Data', 'plan' => 'Pay as you go', 'pitch' => 'dropped']]]),
        );

        $result = json_decode((new SearchPlans($client))->execute(['api' => 'Bright Data', 'sort' => 'price', 'limit' => 5.0]), true);

        $this->assertSame('https://api.datacircle.dev/v1/plans?api=Bright%20Data&sort=price&limit=5', $this->url(0));
        $this->assertSame('https://api.datacircle.dev/v1/plans?query=Bright%20Data&sort=price&limit=5', $this->url(1));
        $this->assertStringStartsWith('No API is named "Bright Data"', $result['note']);
        $this->assertSame([['provider' => 'Bright Data', 'plan' => 'Pay as you go']], $result['plans']);
    }

    public function test_search_plans_reads_a_slug_sent_as_a_name_as_its_words(): void
    {
        $client = $this->client(
            self::answer(['total' => 0, 'offset' => 0, 'plans' => []]),
            self::answer(['total' => 2, 'offset' => 0, 'plans' => [['provider' => 'Bright Data'], ['provider' => 'Bright Data']]]),
        );

        $result = json_decode((new SearchPlans($client))->execute(['query' => 'bright-data', 'sort' => 'nope', 'offset' => 0]), true);

        $this->assertSame('https://api.datacircle.dev/v1/plans?query=bright%20data', $this->url(1));
        $this->assertSame(2, $result['total']);
        $this->assertStringContainsString('"bright data"', $result['note']);
    }

    public function test_provider_puts_its_best_rank_first_and_counts_before_the_list(): void
    {
        $client = $this->client(self::answer(['provider' => 'Bright Data', 'slug' => 'bright-data', 'apis' => [
            ['api' => 'X profile', 'slug' => 'x-profile', 'rank' => null, 'plans' => [['plan' => 'A']]],
            ['api' => 'Employee profile', 'slug' => 'linkedin-profile', 'rank' => 7, 'plans' => [
                ['plan' => 'B', 'rank' => 9, 'score' => 50], ['plan' => 'C', 'rank' => 7, 'score' => 60, 'perThousand' => 1.5, 'pitch' => 'dropped'],
            ]],
        ]]));

        $result = json_decode((new Provider($client))->execute(['slug' => 'Bright Data']), true);

        $this->assertSame('https://api.datacircle.dev/v1/providers/bright-data', $this->url());
        $this->assertSame(['provider', 'slug', 'total', 'ranked', 'apis'], array_keys($result));
        $this->assertSame([2, 1], [$result['total'], $result['ranked']]);
        $this->assertSame('linkedin-profile', $result['apis'][0]['slug']);
        $this->assertSame(['plan' => 'C', 'score' => 60, 'perThousand' => 1.5], $result['apis'][0]['best']);
        $this->assertSame(['plan' => 'A'], $result['apis'][1]['best']);
    }

    public function test_a_429_is_waited_for_once(): void
    {
        $client = $this->client(
            new Response(429, ['Retry-After' => '1'], '{"error":"slow down"}'),
            self::answer([]),
        );

        $this->assertSame(['total' => 0, 'apis' => []], json_decode((new ListApis($client))->execute([]), true));
        $this->assertCount(2, $this->sent);
    }
}

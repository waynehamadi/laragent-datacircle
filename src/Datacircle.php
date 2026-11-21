<?php

declare(strict_types=1);

namespace Datacircle\LarAgent;

use Datacircle\LarAgent\Tools\BestPlans;
use Datacircle\LarAgent\Tools\DatacircleTool;
use Datacircle\LarAgent\Tools\ListApis;
use Datacircle\LarAgent\Tools\Provider;
use Datacircle\LarAgent\Tools\SearchPlans;

/**
 * Datacircle's four LarAgent tools: find the best GTM APIs. https://datacircle.dev
 */
final class Datacircle
{
    /** The four tools' classes, for an agent's $tools. */
    public const TOOLS = [ListApis::class, BestPlans::class, SearchPlans::class, Provider::class];

    /**
     * datacircle_list_apis, datacircle_best_plans, datacircle_search_plans and datacircle_provider, for an agent's
     * registerTools().
     *
     * @return list<DatacircleTool>
     */
    public static function tools(?Client $client = null): array
    {
        $client ??= new Client;

        return array_map(fn (string $tool): DatacircleTool => new $tool($client), self::TOOLS);
    }
}

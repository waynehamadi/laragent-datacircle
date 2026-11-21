# Datacircle tools for LarAgent

Find the best GTM APIs. We benchmark all the GTM APIs and we put the results on our website.

https://datacircle.dev

This package gives [LarAgent](https://github.com/MaestroError/LarAgent) agents four tools that call Datacircle's public API, https://api.datacircle.dev/v1. You need no key and no account.

| Tool | What the tool gives | Call |
| --- | --- | --- |
| `datacircle_list_apis` | Every GTM API on Datacircle, with the API's slug, number of plans, number of providers and best plan | `GET /apis` |
| `datacircle_best_plans` | One API's plans, sorted by score out of 100, best first. Each plan comes with a rank and a price per 1,000 calls | `GET /apis/{slug}/best` |
| `datacircle_search_plans` | Plans that match a provider name or plan name. Search all APIs or one API. Sort the plans by score, provider or price | `GET /plans` |
| `datacircle_provider` | The APIs a provider sells, and the provider's rank on each API | `GET /providers/{slug}` |

## Install

```bash
composer require datacircle/laragent-tools
```

The package needs PHP 8.3 or later and LarAgent 1.0 or later.

LarAgent needs Guzzle 7. A new Laravel 13 app comes with Guzzle 8. In a new Laravel 13 app, run the command with `-W` so Composer can install Guzzle 7:

```bash
composer require datacircle/laragent-tools -W
```

## Give the tools to an agent

`Datacircle::TOOLS` lists the four tool classes. Put them in your agent's `$tools`:

```php
namespace App\AiAgents;

use Datacircle\LarAgent\Datacircle;
use LarAgent\Agent;

class GtmAnalyst extends Agent
{
    protected $model = 'gpt-4.1-nano';

    protected $tools = Datacircle::TOOLS;

    public function instructions()
    {
        return 'You find the best GTM APIs. Answer from the Datacircle tools.';
    }
}
```

The agent calls the Datacircle tools to answer a question:

```php
use App\AiAgents\GtmAnalyst;

echo GtmAnalyst::for('demo')->respond('Who are the 3 best providers for the email-finder API?');
```

The tools work the same with `respondStreamed()` and with `php artisan agent:chat GtmAnalyst`.

To give the agent only some tools, list their classes yourself:

```php
use Datacircle\LarAgent\Tools\BestPlans;
use Datacircle\LarAgent\Tools\Provider;

protected $tools = [BestPlans::class, Provider::class];
```

`Datacircle::tools()` returns the four tools as objects. Return these tool objects from your agent's `registerTools()`:

```php
use Datacircle\LarAgent\Datacircle;

public function registerTools()
{
    return Datacircle::tools();
}
```

Or pass each tool object to an agent's `withTool()`.

## Run a tool yourself

```php
use Datacircle\LarAgent\Tools\BestPlans;

echo (new BestPlans)->execute(['slug' => 'email-finder', 'limit' => 3]);
```

Each tool returns a JSON object. `datacircle_best_plans` and `datacircle_search_plans` return plans. Each plan has a rank, provider, plan name, score, price per 1,000 (`perThousand`, in dollars) and a page on datacircle.dev. The page on datacircle.dev lists every field.

On a failure, the tool returns `{"error": "..."}`. For an unknown slug, you get the API's error message. For a network failure, you get the cause of the failure. The model reads the error and can call the tool again. LarAgent counts an exception from a tool as the model failing, so the tools return the error instead.

`new Client(baseUrl: ..., timeout: ...)` sets the API address and the timeout in seconds. The timeout is 30 seconds by default. Pass the client to `Datacircle::tools($client)` or to each tool's constructor. `new Client` also takes your own Guzzle client as `http:`.

## Network

Each tool call makes one HTTPS GET request to https://api.datacircle.dev/v1 and nothing else. The request holds only the tool's own arguments: no key and nothing from your chats or config. The API takes up to 60 calls a minute from one IP address. The tool waits once, for a minute at most, when the API asks the tool to wait. The package stores nothing.

## Links

- Datacircle: https://datacircle.dev
- The API: https://datacircle.dev/api
- The API's spec: https://api.datacircle.dev/v1/openapi.json
- What each key means: https://datacircle.dev/fields

MIT license.

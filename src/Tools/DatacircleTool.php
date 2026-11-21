<?php

declare(strict_types=1);

namespace Datacircle\LarAgent\Tools;

use Datacircle\LarAgent\ApiError;
use Datacircle\LarAgent\Client;
use LarAgent\Tool;
use Throwable;

/**
 * A LarAgent tool on Datacircle's open API. Its result is a JSON object. Every failure is {"error": "..."}, an answer of
 * 4xx with the API's own words, so that the model reads it and can call again: LarAgent takes a tool that throws for a
 * failed model, runs the turn again on the agent's next provider and then throws.
 */
abstract class DatacircleTool extends Tool
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        parent::__construct();
        $this->client = $client ?? new Client;
    }

    /**
     * @param  array<string, mixed>  $input  The arguments as the model sent them.
     */
    public function execute(array $input): mixed
    {
        try {
            return $this->answer($input);
        } catch (ApiError $e) {
            return self::error($e->getMessage());
        } catch (Throwable $e) {
            return self::error(str_starts_with($e->getMessage(), 'datacircle: ') ? $e->getMessage() : 'datacircle: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiError
     */
    abstract protected function answer(array $input): string;

    /**
     * @param  array<string, mixed>  $result
     */
    protected static function json(array $result): string
    {
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    protected static function error(string $message): string
    {
        return self::json(['error' => $message]);
    }

    /**
     * A string argument as the model sent it: trimmed, '' when it sent none or something else.
     */
    protected static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

<?php

declare(strict_types=1);

namespace Datacircle\LarAgent\Tests;

use Datacircle\LarAgent\Client;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LarAgent\LarAgentServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends Testbench
{
    /** @var list<array{request: RequestInterface}> */
    protected array $sent = [];

    protected function getPackageProviders($app): array
    {
        return [LarAgentServiceProvider::class];
    }

    /**
     * A client whose calls get these answers in turn, each call kept in $sent.
     */
    protected function client(Response|\Throwable ...$answers): Client
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->sent));

        return new Client(http: new Guzzle(['handler' => $stack]));
    }

    /**
     * @param  array<mixed>  $body
     */
    protected static function answer(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function url(int $call = 0): string
    {
        return (string) $this->sent[$call]['request']->getUri();
    }
}

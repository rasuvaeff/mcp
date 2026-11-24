<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Benchmarks;

use Mcp\Server\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\Tests\Support\GreetingTool;
use Testo\Bench;
use Yiisoft\Test\Support\Container\SimpleContainer;

final class McpServerFactoryBench
{
    public static function createServerWithTool(): Server
    {
        return self::factory()->create([GreetingTool::class]);
    }

    #[Bench(
        callables: [
            'with a tool' => [self::class, 'createServerWithTool'],
        ],
        calls: 500,
        iterations: 5,
    )]
    public static function createEmptyServer(): Server
    {
        return self::factory()->create([]);
    }

    private static function factory(): McpServerFactory
    {
        return new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
        );
    }
}

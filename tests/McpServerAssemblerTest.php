<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests;

use Mcp\Server\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Mcp\McpServerAssembler;
use Rasuvaeff\Mcp\McpServerComponents;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\ServerConfiguratorInterface;
use Rasuvaeff\Mcp\Testing\McpTester;
use Rasuvaeff\Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Mcp\Tests\Support\LinkingDecorator;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\verify;

#[Test]
#[Covers(McpServerAssembler::class)]
#[Covers(McpServerComponents::class)]
final class McpServerAssemblerTest
{
    public function assemblerBuildsFromResolvedComponentsWithoutAContainerDependency(): void
    {
        $configurator = Understudy::for(ServerConfiguratorInterface::class);
        $components = new McpServerComponents(
            tools: [GreetingTool::class],
            configurators: [$configurator],
            interceptors: [],
            visibility: null,
            promptInterceptors: [],
            resourceInterceptors: [],
            promptVisibility: null,
            resourceVisibility: null,
        );
        $assembler = new McpServerAssembler(
            factory: new McpServerFactory(
                container: new SimpleContainer([]),
                sessionStore: new InMemorySessionStore(),
            ),
            components: $components,
        );

        $server = $assembler->create();

        Assert::instanceOf($server, Server::class);
        verify(fn() => $configurator->configure(Arg::any()));

        $psr17 = new Psr17Factory();
        $tester = new McpTester(
            server: $server,
            requestFactory: $psr17,
            responseFactory: $psr17,
            streamFactory: $psr17,
        );
        $names = array_column($tester->listTools(), 'name');
        sort($names);

        Assert::same($names, ['explode', 'greet']);
    }

    public function assemblerHandsResultDecoratorsToTheServer(): void
    {
        $components = new McpServerComponents(
            tools: [GreetingTool::class],
            configurators: [],
            interceptors: [],
            visibility: null,
            promptInterceptors: [],
            resourceInterceptors: [],
            promptVisibility: null,
            resourceVisibility: null,
            resultDecorators: [new LinkingDecorator()],
        );
        $server = (new McpServerAssembler(
            factory: new McpServerFactory(
                container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hi')]),
                sessionStore: new InMemorySessionStore(),
            ),
            components: $components,
        ))->create();
        $psr17 = new Psr17Factory();

        $result = (new McpTester(server: $server, requestFactory: $psr17, responseFactory: $psr17, streamFactory: $psr17))->callTool('greet', ['name' => 'Yii']);

        Assert::same($result['content'][1]['uri'] ?? null, 'app://link');
    }
}

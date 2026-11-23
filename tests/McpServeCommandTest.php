<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests;

use Mcp\Server\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\InMemoryTransport;
use Rasuvaeff\Mcp\McpServeCommand;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Understudy\Understudy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\verify;

#[Test]
#[Covers(McpServeCommand::class)]
final class McpServeCommandTest
{
    public function runsTheServerOverTheInjectedTransportAndSucceeds(): void
    {
        // a forwarding double over the real transport: identical stdio
        // behavior, with the call log proving the command served through it
        $transport = Understudy::delegate(InMemoryTransport::class, new InMemoryTransport());
        $tester = new CommandTester(new McpServeCommand(
            server: $this->server(),
            transport: $transport,
        ));

        Assert::same($tester->execute([]), Command::SUCCESS);
        verify(fn() => $transport->listen());
    }

    public function registersUnderTheServeName(): void
    {
        $command = new McpServeCommand($this->server());

        Assert::same($command->getName(), 'mcp:serve');
        Assert::string($command->getDescription())->contains('stdio');
    }

    private function server(): Server
    {
        return (new McpServerFactory(
            container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
            sessionStore: new InMemorySessionStore(),
            name: 'serve-suite',
            version: '1.0.0',
        ))->create([GreetingTool::class]);
    }
}

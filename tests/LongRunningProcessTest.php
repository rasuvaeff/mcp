<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests;

use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\RequestEnvelope;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Mcp\Interceptor\ResponseSizeLimitInterceptor;
use Rasuvaeff\Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\SharedSecretMiddleware;
use Rasuvaeff\Mcp\Tests\Support\GreetingTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * One worker process serving many calls: a failed, cancelled or truncated call
 * leaves nothing for the next one, and servers can be rebuilt in the process.
 */
#[Test]
#[Covers(McpAction::class)]
#[Covers(McpServerFactory::class)]
final class LongRunningProcessTest
{
    public function failedCallsLeaveNothingForTheNextOne(): void
    {
        $seen = new \ArrayObject();
        $action = $this->action($seen);

        for ($i = 0; $i < 1_000; ++$i) {
            $clientId = $i % 3 === 0 ? null : 'client-' . $i;
            $name = match ($i % 10) {
                3 => 'cancel-' . $i,
                7 => str_repeat('x', 500) . $i,
                default => 'call-' . $i,
            };
            $body = $this->call($action, $clientId, $name);
            $failed = ($body['result']['isError'] ?? false) === true || isset($body['error']);

            Assert::same($failed, $i % 10 === 3);
            Assert::same($seen[$i], [$clientId, $name]);

            if ($failed) {
                continue;
            }

            $text = $body['result']['content'][0]['text'];

            if ($i % 10 === 7) {
                // a string result is truncated with a marker, not refused
                Assert::true(str_starts_with($text, 'Hello, xxx') && strlen($text) < 500);

                continue;
            }

            Assert::same($text, 'Hello, ' . $name . '!');
        }

        Assert::same(count($seen), 1_000);
    }

    public function anUnreadableRequestDoesNotBreakTheNextCall(): void
    {
        $seen = new \ArrayObject();
        $action = $this->action($seen);
        $broken = new ServerRequest('POST', 'http://localhost/mcp', ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'], '{"jsonrpc":');

        for ($i = 0; $i < 10; ++$i) {
            Assert::true($action->handle($broken)->getStatusCode() >= 400);
            Assert::same($this->call($action, 'client', 'A')['result']['content'][0]['text'], 'Hello, A!');
        }
    }

    public function rebuildingInOneProcessDoesNotAccumulateMemory(): void
    {
        $build = function (): void {
            $this->call($this->action(new \ArrayObject()), 'client', 'A');
        };

        for ($i = 0; $i < 20; ++$i) {
            $build();
        }

        gc_collect_cycles();
        $base = memory_get_usage();

        for ($i = 0; $i < 100; ++$i) {
            $build();
        }

        gc_collect_cycles();
        Assert::true(memory_get_usage() - $base < 1024 * 1024);
    }

    private function action(\ArrayObject $seen): McpAction
    {
        $factory = new Psr17Factory();
        $recorder = new readonly class ($seen) implements ToolCallInterceptorInterface {
            public function __construct(private \ArrayObject $seen) {}

            #[\Override]
            public function intercept(ToolCallContext $context, callable $next): mixed
            {
                $this->seen[] = [$context->clientId, $context->arguments['name']];

                if (str_starts_with((string) $context->arguments['name'], 'cancel')) {
                    throw new \RuntimeException('Cancelled');
                }

                return $next();
            }
        };

        return new McpAction(
            server: (new McpServerFactory(
                container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
                sessionStore: new InMemorySessionStore(),
            ))->create([GreetingTool::class], [], [$recorder, new ResponseSizeLimitInterceptor(maxBytes: 300)]),
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function call(McpAction $action, ?string $clientId, string $name): array
    {
        $version = ProtocolVersion::V2026_07_28;
        $payload = (new RequestEnvelope(
            $version,
            new ClientCapabilities(),
            new Implementation(name: 'long-running', version: '1.0'),
        ))->stamp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'greet', 'arguments' => ['name' => $name]]]);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = new ServerRequest('POST', 'http://localhost/mcp', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            ...(new HeaderFactory(new ToolCatalog()))->forMessage(json_decode($json, associative: true), $version),
        ], $json);

        if ($clientId !== null) {
            $request = $request->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, $clientId);
        }

        $body = (string) $action->handle($request)->getBody();

        return json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}

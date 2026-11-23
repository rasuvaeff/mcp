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
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\SharedSecretMiddleware;
use Rasuvaeff\Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Mcp\Tests\Support\SuspendingStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * Two requests of one process, interleaved on bare Fibers: the first is
 * suspended while its body is read, the second runs to completion, the first
 * resumes. The stateless era has no session owner to fall back on, so the
 * client id must travel with the request itself.
 */
#[Test]
#[Covers(McpAction::class)]
final class InterleavedIdentityTest
{
    private McpAction $action;

    /** @var \ArrayObject<string, ?string> tool argument => observed client id */
    private \ArrayObject $seen;

    #[BeforeTest]
    public function setUp(): void
    {
        $factory = new Psr17Factory();
        $seen = $this->seen = new \ArrayObject();
        $recorder = new readonly class ($seen) implements ToolCallInterceptorInterface {
            public function __construct(private \ArrayObject $seen) {}

            #[\Override]
            public function intercept(ToolCallContext $context, callable $next): mixed
            {
                $this->seen[$context->arguments['name']] = $context->clientId;

                return $next();
            }
        };
        $this->action = new McpAction(
            server: (new McpServerFactory(
                container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
                sessionStore: new InMemorySessionStore(),
            ))->create([GreetingTool::class], [], [$recorder]),
            responseFactory: $factory,
            streamFactory: $factory,
        );
    }

    public function aSuspendedRequestKeepsItsOwnClientId(): void
    {
        $first = new \Fiber(fn(): ResponseInterface => $this->call('client-a', 'A', suspends: true));
        $first->start();
        Assert::true($first->isSuspended());

        $this->call('client-b', 'B');
        $first->resume();

        Assert::true($first->isTerminated());
        Assert::same((array) $this->seen, ['B' => 'client-b', 'A' => 'client-a']);
    }

    public function anAnonymousRequestDoesNotInheritASuspendedClientId(): void
    {
        $first = new \Fiber(fn(): ResponseInterface => $this->call('client-a', 'A', suspends: true));
        $first->start();

        $this->call(null, 'B');
        $first->resume();

        Assert::same((array) $this->seen, ['B' => null, 'A' => 'client-a']);
    }

    public function sequentialCallsOfOneProcessNeverShareAClientId(): void
    {
        for ($i = 0; $i < 1_000; ++$i) {
            $this->call($i % 10 === 0 ? null : 'client-' . $i, 'call-' . $i);
        }

        $seen = (array) $this->seen;
        Assert::same(count($seen), 1_000);

        foreach ($seen as $name => $clientId) {
            $i = (int) substr((string) $name, 5);
            Assert::same($clientId, $i % 10 === 0 ? null : 'client-' . $i);
        }
    }

    public function noClassKeepsMutableStaticState(): void
    {
        $source = __DIR__ . '/../src';
        $checked = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var class-string $class */
            $class = 'Rasuvaeff\\Mcp\\' . strtr(substr($file->getPathname(), strlen($source) + 1, -4), ['/' => '\\', '\\' => '\\']);
            Assert::same((new \ReflectionClass($class))->getStaticProperties(), []);
            ++$checked;
        }

        Assert::true($checked > 50);
    }

    private function call(?string $clientId, string $name, bool $suspends = false): ResponseInterface
    {
        $version = ProtocolVersion::V2026_07_28;
        $payload = (new RequestEnvelope(
            $version,
            new ClientCapabilities(),
            new Implementation(name: 'interleaved', version: '1.0'),
        ))->stamp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'greet', 'arguments' => ['name' => $name]]]);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $body = (new Psr17Factory())->createStream($json);
        $request = new ServerRequest('POST', 'http://localhost/mcp', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            ...(new HeaderFactory(new ToolCatalog()))->forMessage(json_decode($json, associative: true), $version),
        ]);

        if ($clientId !== null) {
            $request = $request->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, $clientId);
        }

        return $this->action->handle($request->withBody($suspends ? new SuspendingStream($body) : $body));
    }
}

<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests;

use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Mcp\Identity\StaticSecretResolver;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\SharedSecretMiddleware;
use Rasuvaeff\Mcp\Testing\AdapterContract;
use Rasuvaeff\Mcp\Testing\AdapterContractDependency;
use Rasuvaeff\Mcp\Testing\AdapterContractTool;
use Rasuvaeff\Mcp\Testing\AdapterContractViolation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * The contract passes on the runtime itself, and every check catches the
 * breakage it is there for.
 */
#[Test]
#[Covers(AdapterContract::class)]
#[Covers(AdapterContractTool::class)]
#[Covers(AdapterContractDependency::class)]
final class AdapterContractTest
{
    private const array SECRETS = ['alpha' => 'secret-a', 'beta' => 'secret-b'];

    public function theRuntimeKeepsItsOwnContract(): void
    {
        $checks = $this->contract($this->application())->checks();
        Assert::same(count($checks), 8);

        foreach ($checks as $check) {
            $check();
        }
    }

    /**
     * @param \Closure(\Closure(ServerRequestInterface): ResponseInterface): (\Closure(ServerRequestInterface): ResponseInterface) $break
     */
    #[DataProvider('breakageProvider')]
    public function aBrokenAdapterFailsItsCheck(string $check, \Closure $break): void
    {
        $violation = null;

        try {
            $this->contract($break($this->application()))->checks()[$check]();
        } catch (AdapterContractViolation $violation) {
        }

        Assert::notNull($violation);
    }

    public static function breakageProvider(): iterable
    {
        $withSecret = static fn(string $secret): \Closure => static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => $handle($request->withHeader('X-Mcp-Secret', $secret));
        $answer = static fn(int $status, string $body): \Closure => static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => new Response($status, ['Content-Type' => 'application/json'], $body);

        yield 'no secret check' => ['a request without a secret is refused', $withSecret('secret-a')];
        yield 'any secret accepted' => ['a request with a wrong secret is refused', $withSecret('secret-a')];
        yield 'no session header' => ['the handshake era serves tools', static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => $handle($request)->withoutHeader('Mcp-Session-Id')];
        yield 'session lost' => ['the handshake era serves tools', static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => $handle($request->withoutHeader('Mcp-Session-Id'))];
        yield 'body lost' => ['the stateless era serves tools', static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => $handle($request->withBody((new Psr17Factory())->createStream('')))];
        yield 'headers lost' => ['the stateless era serves tools', static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => $handle($request->withoutHeader('Mcp-Method'))];
        yield 'not json' => ['the stateless era serves tools', $answer(200, '<html>')];
        yield 'one client for all' => ['a session belongs to the client that opened it', $withSecret('secret-a')];
        yield 'clients mixed' => ['every call sees its own client', $withSecret('secret-a')];
        yield 'internals disclosed' => ['a failure inside a tool is not disclosed', $answer(500, AdapterContractTool::SECRET_FAILURE)];
        yield 'failure reported as success' => ['a failure inside a tool is not disclosed', $answer(200, '{"result":{"content":[]}}')];
        yield 'malformed answered with 500' => ['a malformed request does not break the next one', $answer(500, '')];
        yield 'malformed accepted' => ['a malformed request does not break the next one', $answer(200, '{}')];
        yield 'broken after a malformed request' => ['a malformed request does not break the next one', $answer(400, '{}')];
    }

    public function oneClientIsNotEnough(): void
    {
        $caught = null;

        try {
            new AdapterContract($this->application(), new Psr17Factory(), new Psr17Factory(), 'http://localhost/mcp', ['only' => 's']);
        } catch (\InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
    }

    private function contract(\Closure $handle): AdapterContract
    {
        $factory = new Psr17Factory();

        return new AdapterContract($handle, $factory, $factory, 'http://localhost/mcp', self::SECRETS);
    }

    /**
     * @return \Closure(ServerRequestInterface): ResponseInterface
     */
    private function application(): \Closure
    {
        $factory = new Psr17Factory();
        $store = new InMemorySessionStore();
        $action = new McpAction(
            server: (new McpServerFactory(
                container: new SimpleContainer([AdapterContractTool::class => new AdapterContractTool(new AdapterContractDependency())]),
                sessionStore: $store,
            ))->create([AdapterContractTool::class]),
            responseFactory: $factory,
            streamFactory: $factory,
            sessionStore: $store,
        );
        $guard = new SharedSecretMiddleware(secret: '', responseFactory: $factory, resolver: new StaticSecretResolver(self::SECRETS));

        return static fn(ServerRequestInterface $request): ResponseInterface => $guard->process($request, $action);
    }
}

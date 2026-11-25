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
    public function aBrokenAdapterFailsItsCheck(string $check, \Closure $break, string $message): void
    {
        $violation = null;

        try {
            $this->contract($break($this->application()))->checks()[$check]();
        } catch (AdapterContractViolation $violation) {
        }

        Assert::notNull($violation);
        Assert::string($violation->getMessage())->contains($message);
    }

    public static function breakageProvider(): iterable
    {
        $withSecret = static fn(string $secret): \Closure => static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => $handle($request->withHeader('X-Mcp-Secret', $secret));
        $answer = static fn(int $status, string $body): \Closure => static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => new Response($status, ['Content-Type' => 'application/json'], $body);

        $response = static fn(\Closure $change): \Closure => static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => $change($handle($request), $request);
        $request = static fn(\Closure $change): \Closure => static fn(\Closure $handle): \Closure => static fn(ServerRequestInterface $request): ResponseInterface => $handle($change($request));
        $tool = static fn(string $name, int $status, string $body): \Closure => $response(static fn(ResponseInterface $answer, ServerRequestInterface $request): ResponseInterface => str_contains((string) $request->getBody(), '"' . $name . '"') ? new Response($status, ['Content-Type' => 'application/json'], $body) : $answer);

        yield 'no secret check' => ['a request without a secret is refused', $withSecret('secret-a'), 'Unexpected status: expected 401, got 200'];
        yield 'any secret accepted' => ['a request with a wrong secret is refused', $withSecret('secret-a'), 'Unexpected status: expected 401, got 200'];
        yield 'no session header' => ['the handshake era serves tools', $response(static fn(ResponseInterface $answer): ResponseInterface => $answer->withoutHeader('Mcp-Session-Id')), 'without an Mcp-Session-Id header'];
        yield 'initialize refused' => ['the handshake era serves tools', $response(static fn(ResponseInterface $answer): ResponseInterface => $answer->withStatus(201)), 'Unexpected status: expected 200, got 201'];
        yield 'another tool list' => ['the handshake era serves tools', $response(static fn(ResponseInterface $answer, ServerRequestInterface $request): ResponseInterface => str_contains((string) $request->getBody(), 'tools\/list') ? new Response(200, [], '{"result":{"tools":[{"name":"b"},{"name":"a"}]}}') : $answer), 'Unexpected tools: expected ["contract.client","contract.fail","contract.greet"], got ["a","b"]'];
        yield 'no tool list' => ['the handshake era serves tools', $response(static fn(ResponseInterface $answer, ServerRequestInterface $request): ResponseInterface => str_contains((string) $request->getBody(), 'tools\/list') ? new Response(200, [], '{}') : $answer), 'got []'];
        yield 'another answer' => ['the handshake era serves tools', $tool('contract.greet', 200, '{"result":{"content":[{"text":"Bye"}]}}'), 'Unexpected result: expected "Hello, Ada!", got "Bye"'];
        yield 'another stateless answer' => ['the stateless era serves tools', $tool('contract.greet', 200, '{"result":{"content":[{"text":"Bye"}]}}'), 'Unexpected result: expected "Hello, Ada!", got "Bye"'];
        yield 'body lost' => ['the stateless era serves tools', $request(static fn(ServerRequestInterface $request): ServerRequestInterface => $request->withBody((new Psr17Factory())->createStream(''))), 'The call did not answer with text'];
        yield 'headers lost' => ['the stateless era serves tools', $request(static fn(ServerRequestInterface $request): ServerRequestInterface => $request->withoutHeader('Mcp-Method')), 'The call did not answer with text: {'];
        yield 'not json' => ['the stateless era serves tools', $answer(200, str_repeat('<', 300)), 'The answer is not a JSON-RPC message: ' . str_repeat('<', 200)];
        yield 'one client for all' => ['a session belongs to the client that opened it', $withSecret('secret-a'), 'Unexpected status: expected 404, got 200'];
        yield 'another owner' => ['a session belongs to the client that opened it', $response(static fn(ResponseInterface $answer): ResponseInterface => $answer->getStatusCode() === 200 && str_contains((string) $answer->getBody(), 'alpha') ? new Response(200, [], '{"result":{"content":[{"text":"beta"}]}}') : $answer), 'Unexpected owner: expected "alpha", got "beta"'];
        yield 'clients mixed' => ['every call sees its own client', $withSecret('secret-a'), 'Unexpected client: expected "beta", got "alpha"'];
        yield 'internals disclosed' => ['a failure inside a tool is not disclosed', $answer(500, '{"error":"' . AdapterContractTool::SECRET_FAILURE . '"}'), 'reached the client'];
        yield 'failure reported as success' => ['a failure inside a tool is not disclosed', $answer(200, '{"result":{"content":[]}}'), 'must answer with an error, got: {"result"'];
        yield 'malformed answered with 500' => ['a malformed request does not break the next one', $answer(500, ''), 'must be answered with a 4xx status, got 500'];
        yield 'malformed accepted' => ['a malformed request does not break the next one', $answer(399, '{}'), 'must be answered with a 4xx status, got 399'];
        yield 'broken after a malformed request' => ['a malformed request does not break the next one', $answer(499, '{}'), 'The call did not answer with text'];
    }

    public function anErrorOfAToolMayBeAResultOrAProtocolError(): void
    {
        foreach (['{"error":{"code":1}}', '{"result":{"isError":true,"content":[]}}'] as $body) {
            $this->contract(static fn(): ResponseInterface => new Response(200, [], $body))->checks()['a failure inside a tool is not disclosed']();
        }

        Assert::true(actual: true);
    }

    public function theRequestsAreTheOnesTheChecksDescribe(): void
    {
        $application = $this->application();
        $log = new \ArrayObject();
        $checks = $this->contract(static function (ServerRequestInterface $request) use ($application, $log): ResponseInterface {
            $message = json_decode((string) $request->getBody(), associative: true);
            $log[] = [
                $request->getHeaderLine('X-Mcp-Secret'),
                $message['id'] ?? null,
                $message['method'] ?? null,
                $message['params']['name'] ?? null,
                $request->hasHeader('Mcp-Session-Id'),
                $request->getHeaderLine('Accept'),
            ];

            return $application($request);
        })->checks();
        $accept = 'application/json, text/event-stream';
        $sent = static function (string $check) use ($checks, $log): array {
            $log->exchangeArray([]);
            $checks[$check]();

            return (array) $log;
        };

        Assert::same($sent('a request without a secret is refused'), [['', 1, 'initialize', null, false, $accept]]);
        Assert::same($sent('a request with a wrong secret is refused'), [['wrong-secret-a', 1, 'initialize', null, false, $accept]]);
        Assert::same($sent('the handshake era serves tools'), [
            ['secret-a', 1, 'initialize', null, false, $accept],
            ['secret-a', null, 'notifications/initialized', null, true, $accept],
            ['secret-a', 2, 'tools/list', null, true, $accept],
            ['secret-a', 3, 'tools/call', 'contract.greet', true, $accept],
        ]);
        Assert::same($sent('the stateless era serves tools'), [['secret-a', 3, 'tools/call', 'contract.greet', false, $accept]]);
        Assert::same(array_column($sent('a session belongs to the client that opened it'), column_key: 0), ['secret-a', 'secret-a', 'secret-b', 'secret-a']);
        Assert::same(array_column($sent('every call sees its own client'), column_key: 0), array_merge(...array_fill(0, 10, ['secret-a', 'secret-b'])));
        Assert::same($sent('a failure inside a tool is not disclosed'), [['secret-a', 3, 'tools/call', 'contract.fail', false, $accept]]);
        Assert::same(array_column($sent('a malformed request does not break the next one'), column_key: 0), ['secret-a', 'secret-a']);
    }

    public function theFailingToolThrows(): void
    {
        $caught = null;

        try {
            (new AdapterContractTool(new AdapterContractDependency()))->fail();
        } catch (\RuntimeException $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), AdapterContractTool::SECRET_FAILURE);
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

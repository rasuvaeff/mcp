<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Testing;

use Closure;
use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\RequestEnvelope;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The behaviour every framework adapter must keep, checked through the
 * framework's own HTTP kernel. The application under test serves
 * {@see AdapterContractTool} behind `SharedSecretMiddleware` with the two
 * given `client_secrets`, and one kernel instance answers every request: the
 * checks run in one process on purpose.
 *
 * ```php
 * foreach ($contract->checks() as $name => $check) {
 *     $check(); // throws AdapterContractViolation
 * }
 * ```
 *
 * @api
 */
final readonly class AdapterContract
{
    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $handle runs a request through the real kernel
     * @param string $uri the endpoint, e.g. "http://localhost/mcp"
     * @param non-empty-array<string, string> $secrets client id => secret; at least two clients
     */
    public function __construct(
        private Closure $handle,
        private ServerRequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private string $uri,
        private array $secrets,
        private string $secretHeader = 'X-Mcp-Secret',
    ) {
        if (count($secrets) < 2) {
            throw new \InvalidArgumentException('The adapter contract needs at least two clients');
        }
    }

    /**
     * @return array<non-empty-string, Closure(): void>
     */
    public function checks(): array
    {
        return [
            'a request without a secret is refused' => $this->refusesAMissingSecret(...),
            'a request with a wrong secret is refused' => $this->refusesAWrongSecret(...),
            'the handshake era serves tools' => $this->servesTheHandshakeEra(...),
            'the stateless era serves tools' => $this->servesTheStatelessEra(...),
            'a session belongs to the client that opened it' => $this->bindsASessionToItsClient(...),
            'every call sees its own client' => $this->keepsClientsApart(...),
            'a failure inside a tool is not disclosed' => $this->hidesInternalFailures(...),
            'a malformed request does not break the next one' => $this->survivesMalformedRequests(...),
        ];
    }

    private function refusesAMissingSecret(): void
    {
        $this->same('status', $this->post($this->initializeMessage(), null)->getStatusCode(), 401);
    }

    private function refusesAWrongSecret(): void
    {
        $response = ($this->handle)($this->request($this->initializeMessage())->withHeader($this->secretHeader, 'wrong-' . $this->secret(0)));
        $this->same('status', $response->getStatusCode(), 401);
    }

    private function servesTheHandshakeEra(): void
    {
        $session = $this->openSession(0);
        $list = $this->decode($this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], 0, $session));
        /** @var list<array{name: string}> $tools */
        $tools = $list['result']['tools'] ?? [];
        $names = array_column($tools, 'name');
        sort($names);
        $this->same('tools', $names, ['contract.client', 'contract.fail', 'contract.greet']);

        $call = $this->decode($this->post($this->callMessage('contract.greet', ['name' => 'Ada']), 0, $session));
        $this->same('result', $this->text($call), 'Hello, Ada!');
    }

    private function servesTheStatelessEra(): void
    {
        $this->same('result', $this->text($this->stateless(0, 'contract.greet', ['name' => 'Ada'])), 'Hello, Ada!');
    }

    private function bindsASessionToItsClient(): void
    {
        $session = $this->openSession(0);
        $this->same('status', $this->post($this->callMessage('contract.client', []), 1, $session)->getStatusCode(), 404);
        $this->same('owner', $this->text($this->decode($this->post($this->callMessage('contract.client', []), 0, $session))), $this->clientId(0));
    }

    private function keepsClientsApart(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->same('client', $this->text($this->stateless($i % 2, 'contract.client', [])), $this->clientId($i % 2));
        }
    }

    private function hidesInternalFailures(): void
    {
        $response = $this->postStateless(0, 'contract.fail', []);
        $body = (string) $response->getBody();

        if (str_contains($body, AdapterContractTool::SECRET_FAILURE)) {
            throw new AdapterContractViolation('The message of an exception thrown inside a tool reached the client');
        }

        $answer = $this->decodeBody($body);

        if (!isset($answer['error']) && ($answer['result']['isError'] ?? false) !== true) {
            throw new AdapterContractViolation(sprintf('A failing tool must answer with an error, got: %s', $this->excerpt($body)));
        }
    }

    private function survivesMalformedRequests(): void
    {
        $broken = $this->request([])->withBody($this->streamFactory->createStream('{"jsonrpc":'))->withHeader($this->secretHeader, $this->secret(0));
        $status = ($this->handle)($broken)->getStatusCode();

        if ($status < 400 || $status >= 500) {
            throw new AdapterContractViolation(sprintf('A malformed request must be answered with a 4xx status, got %d', $status));
        }

        $this->servesTheStatelessEra();
    }

    private function openSession(int $client): string
    {
        $response = $this->post($this->initializeMessage(), $client);
        $this->same('status', $response->getStatusCode(), 200);
        $session = $response->getHeaderLine('Mcp-Session-Id');

        if ($session === '') {
            throw new AdapterContractViolation('initialize answered without an Mcp-Session-Id header');
        }

        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $client, $session);

        return $session;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function post(array $message, ?int $client, string $session = ''): ResponseInterface
    {
        $request = $this->request($message);

        if ($client !== null) {
            $request = $request->withHeader($this->secretHeader, $this->secret($client));
        }

        return ($this->handle)($session === '' ? $request : $request->withHeader('Mcp-Session-Id', $session));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function postStateless(int $client, string $tool, array $arguments): ResponseInterface
    {
        $version = ProtocolVersion::V2026_07_28;
        $message = (new RequestEnvelope(
            $version,
            new ClientCapabilities(),
            new Implementation(name: 'adapter-contract', version: '1.0'),
        ))->stamp($this->callMessage($tool, $arguments));
        $request = $this->request($message);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($message, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR);

        foreach ((new HeaderFactory(new ToolCatalog()))->forMessage($decoded, $version) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return ($this->handle)($request->withHeader($this->secretHeader, $this->secret($client)));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function stateless(int $client, string $tool, array $arguments): array
    {
        return $this->decode($this->postStateless($client, $tool, $arguments));
    }

    /**
     * @param array<array-key, mixed> $message
     */
    private function request(array $message): ServerRequestInterface
    {
        return $this->requestFactory->createServerRequest('POST', $this->uri)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->streamFactory->createStream(json_encode($message, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeMessage(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::latestHandshake()->value,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'adapter-contract', 'version' => '1.0'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callMessage(string $tool, array $arguments): array
    {
        return ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        return $this->decodeBody((string) $response->getBody());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeBody(string $body): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode(SseFrame::payload($body), associative: true);

        if (!is_array($decoded)) {
            throw new AdapterContractViolation(sprintf('The answer is not a JSON-RPC message: %s', $this->excerpt($body)));
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $answer
     */
    private function text(array $answer): mixed
    {
        return $answer['result']['content'][0]['text']
            ?? throw new AdapterContractViolation(sprintf('The call did not answer with text: %s', $this->excerpt(json_encode($answer, JSON_THROW_ON_ERROR))));
    }

    private function same(string $what, mixed $actual, mixed $expected): void
    {
        if ($actual !== $expected) {
            throw new AdapterContractViolation(sprintf(
                'Unexpected %s: expected %s, got %s',
                $what,
                json_encode($expected, JSON_THROW_ON_ERROR),
                json_encode($actual, JSON_THROW_ON_ERROR),
            ));
        }
    }

    private function excerpt(string $body): string
    {
        return substr($body, offset: 0, length: 200);
    }

    private function clientId(int $client): string
    {
        return array_keys($this->secrets)[$client];
    }

    private function secret(int $client): string
    {
        return array_values($this->secrets)[$client];
    }
}

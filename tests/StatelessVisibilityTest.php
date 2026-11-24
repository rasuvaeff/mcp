<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests;

use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\RequestEnvelope;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Mcp\Identity\SessionClient;
use Rasuvaeff\Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Mcp\McpAction;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\SharedSecretMiddleware;
use Rasuvaeff\Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Mcp\Visibility\FilteredCallToolHandler;
use Rasuvaeff\Mcp\Visibility\FilteredCompletionCompleteHandler;
use Rasuvaeff\Mcp\Visibility\FilteredListPromptsHandler;
use Rasuvaeff\Mcp\Visibility\FilteredListResourcesHandler;
use Rasuvaeff\Mcp\Visibility\FilteredListResourceTemplatesHandler;
use Rasuvaeff\Mcp\Visibility\FilteredListToolsHandler;
use Rasuvaeff\Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Mcp\Visibility\ResourceVisibilityInterface;
use Rasuvaeff\Mcp\Visibility\ToolVisibilityInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * Every visibility decision sees the client of the request, also on the
 * stateless era, where no session remembers it from an earlier request.
 */
#[Test]
#[Covers(SessionClient::class)]
#[Covers(FilteredListToolsHandler::class)]
#[Covers(FilteredCallToolHandler::class)]
#[Covers(FilteredListPromptsHandler::class)]
#[Covers(FilteredListResourcesHandler::class)]
#[Covers(FilteredListResourceTemplatesHandler::class)]
#[Covers(FilteredCompletionCompleteHandler::class)]
final class StatelessVisibilityTest
{
    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('methodProvider')]
    public function visibilitySeesTheClientOfTheRequest(string $method, array $params): void
    {
        foreach (['client-a', 'client-b', null] as $clientId) {
            $seen = new \ArrayObject();
            $this->call($seen, $clientId, $method, $params);

            Assert::true(count($seen) > 0);
            Assert::same(array_values(array_unique((array) $seen)), [$clientId]);
        }
    }

    public static function methodProvider(): iterable
    {
        yield 'tools/list' => ['tools/list', []];
        yield 'tools/call' => ['tools/call', ['name' => 'greet', 'arguments' => ['name' => 'A']]];
        yield 'prompts/list' => ['prompts/list', []];
        yield 'resources/list' => ['resources/list', []];
        yield 'resources/templates/list' => ['resources/templates/list', []];
        yield 'completion/complete for a prompt' => ['completion/complete', ['ref' => ['type' => 'ref/prompt', 'name' => 'greeting-style'], 'argument' => ['name' => 'x', 'value' => '']]];
    }

    public function aFilterOnTheClientHidesTheToolFromTheOtherClientOnly(): void
    {
        $call = ['name' => 'greet', 'arguments' => ['name' => 'A']];

        Assert::same($this->call(new \ArrayObject(), 'client-a', 'tools/call', $call, allowed: 'client-a')['result']['content'][0]['text'], 'Hello, A!');
        Assert::same($this->call(new \ArrayObject(), 'client-b', 'tools/call', $call, allowed: 'client-a')['error']['code'], -32602);
        Assert::same($this->call(new \ArrayObject(), 'client-b', 'tools/list', [], allowed: 'client-a')['result']['tools'], []);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(\ArrayObject $seen, ?string $clientId, string $method, array $params, ?string $allowed = null): array
    {
        $visibility = new readonly class ($seen, $allowed) implements ToolVisibilityInterface, PromptVisibilityInterface, ResourceVisibilityInterface {
            public function __construct(private \ArrayObject $seen, private ?string $allowed) {}

            #[\Override]
            public function isVisible(Tool|Prompt|ResourceDefinition $capability, ?SessionInterface $session): bool
            {
                return $this->decide($session);
            }

            #[\Override]
            public function isTemplateVisible(ResourceTemplate $template, ?SessionInterface $session): bool
            {
                return $this->decide($session);
            }

            private function decide(?SessionInterface $session): bool
            {
                $clientId = $session?->get(InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY);
                $this->seen[] = $clientId;

                return $this->allowed === null || $clientId === $this->allowed;
            }
        };
        $factory = new Psr17Factory();
        $action = new McpAction(
            server: (new McpServerFactory(
                container: new SimpleContainer([GreetingTool::class => new GreetingTool(prefix: 'Hello')]),
                sessionStore: new InMemorySessionStore(),
            ))->create(
                [GreetingTool::class],
                toolVisibility: $visibility,
                promptVisibility: $visibility,
                resourceVisibility: $visibility,
            ),
            responseFactory: $factory,
            streamFactory: $factory,
        );
        $version = ProtocolVersion::V2026_07_28;
        $json = json_encode((new RequestEnvelope(
            $version,
            new ClientCapabilities(),
            new Implementation(name: 'visibility', version: '1.0'),
        ))->stamp(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]), JSON_THROW_ON_ERROR);
        $request = new ServerRequest('POST', 'http://localhost/mcp', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            ...(new HeaderFactory(new ToolCatalog()))->forMessage(json_decode($json, associative: true), $version),
        ], $json);

        if ($clientId !== null) {
            $request = $request->withAttribute(SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE, $clientId);
        }

        return json_decode((string) $action->handle($request)->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
    }
}

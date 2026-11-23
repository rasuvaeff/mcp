<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Session\InMemorySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Mcp\Interceptor\InterceptingReferenceHandler;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\Testing\McpTester;
use Rasuvaeff\Mcp\Tests\Support\TokenTool;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

/**
 * The client id is carried by the request's AccessToken; a handler must never
 * see that carrier, or "has a token" would stop meaning OAuth.
 */
#[Test]
#[Covers(InterceptingReferenceHandler::class)]
#[Covers(McpServerFactory::class)]
final class AccessTokenVisibilityTest
{
    #[DataProvider('revisionProvider')]
    public function aClientWithoutOAuthHasNoToken(ProtocolVersion $revision): void
    {
        Assert::same($this->call($revision, null), 'no token');
    }

    #[DataProvider('revisionProvider')]
    public function anOAuthClientHasExactlyItsOwnToken(ProtocolVersion $revision): void
    {
        Assert::same($this->call($revision, new AccessToken([], ['sub' => 'user-1'])), 'subject user-1, claims sub');
    }

    public static function revisionProvider(): iterable
    {
        yield 'handshake' => [ProtocolVersion::latestHandshake()];
        yield 'stateless' => [ProtocolVersion::V2026_07_28];
    }

    private function call(ProtocolVersion $revision, ?AccessToken $token): string
    {
        $factory = new Psr17Factory();
        $server = (new McpServerFactory(
            container: new SimpleContainer([TokenTool::class => new TokenTool()]),
            sessionStore: new InMemorySessionStore(),
        ))->create([TokenTool::class]);
        $requests = new readonly class ($token) implements \Psr\Http\Message\ServerRequestFactoryInterface {
            public function __construct(private ?AccessToken $token) {}

            #[\Override]
            public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequest
            {
                $request = new ServerRequest($method, $uri);

                return $this->token instanceof \Mcp\Server\Authorization\AccessToken ? $request->withAttribute(AccessToken::class, $this->token) : $request;
            }
        };

        return (string) (new McpTester($server, $requests, $factory, $factory, $revision, clientId: 'claude'))
            ->callTool('token.subject')['content'][0]['text'];
    }
}

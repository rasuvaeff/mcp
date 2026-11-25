<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Configuration;

use InvalidArgumentException;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Subscription\Psr6NotificationBus;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Mcp\CachePolicyParams;
use Rasuvaeff\Mcp\Configuration\ServerOptions;
use Rasuvaeff\Mcp\Identity\StaticSecretResolver;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\Session\PrivateFileSessionStore;
use Rasuvaeff\Mcp\Session\SessionDirectory;
use Rasuvaeff\Mcp\SharedSecretMiddleware;
use Rasuvaeff\Mcp\Tests\Support\ArrayCachePool;
use Rasuvaeff\Mcp\Tests\Support\FakeCache;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(ServerOptions::class)]
final class ServerOptionsTest
{
    public function emptyParamsUseTheDocumentedDefaults(): void
    {
        $options = ServerOptions::fromParams([]);

        Assert::same($options->serverName, 'mcp');
        Assert::same($options->notificationBus, '');
        Assert::same($options->allowedHosts(), []);
        Assert::same($options->factoryArguments(), [
            'name' => 'mcp',
            'version' => 'dev',
            'instructions' => '',
            'paginationLimit' => McpServerFactory::DEFAULT_PAGINATION_LIMIT,
            'protocolVersion' => null,
            'compactToolResults' => true,
            'modernEra' => true,
            'headerValidation' => true,
            'cachePolicy' => null,
            'requestStateKey' => '',
            'requestStateTtl' => 600,
            'previousRequestStateKeys' => [],
            'bindRequestState' => true,
            'subscriptionLifetime' => McpServerFactory::DEFAULT_SUBSCRIPTION_LIFETIME,
        ]);
    }

    public function everyParamReachesTheFactory(): void
    {
        $policy = ['ttl_ms' => 1000, 'scope' => 'private'];
        $options = ServerOptions::fromParams([
            'server_name' => 'app',
            'server_version' => '1.2',
            'instructions' => 'read me',
            'pagination_limit' => 7,
            'protocol_version' => ProtocolVersion::latestHandshake()->value,
            'result_json' => 'pretty',
            'modern_era' => false,
            'header_validation' => false,
            'cache_policy' => $policy,
            'request_state' => ['key' => 'k', 'ttl' => 5, 'previous_keys' => ['old' => 'p'], 'bind' => false],
            'notifications' => ['bus' => 'memory', 'subscription_lifetime' => 3],
            'allowed_hosts' => ['mcp.test'],
        ]);

        Assert::equals($options->factoryArguments(), [
            'name' => 'app',
            'version' => '1.2',
            'instructions' => 'read me',
            'paginationLimit' => 7,
            'protocolVersion' => ProtocolVersion::latestHandshake(),
            'compactToolResults' => false,
            'modernEra' => false,
            'headerValidation' => false,
            'cachePolicy' => CachePolicyParams::parse($policy),
            'requestStateKey' => 'k',
            'requestStateTtl' => 5,
            'previousRequestStateKeys' => ['p'],
            'bindRequestState' => false,
            'subscriptionLifetime' => 3.0,
        ]);
        Assert::same($options->allowedHosts(), ['mcp.test']);
    }

    public function theFactoryIsBuiltFromEveryArgument(): void
    {
        $container = new SimpleContainer([]);
        $store = new InMemorySessionStore();
        $logger = new NullLogger();
        $subscriptions = new SessionSubscriptionManager();
        $bus = new InMemoryNotificationBus();
        $options = ServerOptions::fromParams([
            'server_name' => 'app',
            'server_version' => '1.2',
            'instructions' => 'read me',
            'pagination_limit' => 7,
            'protocol_version' => ProtocolVersion::latestHandshake()->value,
            'result_json' => 'pretty',
            'modern_era' => false,
            'header_validation' => false,
            'cache_policy' => ['ttl_ms' => 1000],
            'request_state' => ['key' => str_repeat('k', 32), 'ttl' => 5, 'previous_keys' => ['p'], 'bind' => false],
            'notifications' => ['subscription_lifetime' => 3],
        ]);

        Assert::equals(
            $options->serverFactory($container, $store, $logger, $subscriptions, $bus),
            new McpServerFactory(...$options->factoryArguments(), container: $container, sessionStore: $store, logger: $logger, subscriptionManager: $subscriptions, notificationBus: $bus),
        );
    }

    public function numericConfigurationStringsAreAccepted(): void
    {
        $arguments = ServerOptions::fromParams(['pagination_limit' => '9', 'server_version' => 3, 'modern_era' => 0, 'header_validation' => '', 'notifications' => ['subscription_lifetime' => '4']])->factoryArguments();

        Assert::same($arguments['paginationLimit'], 9);
        Assert::same($arguments['version'], '3');
        Assert::false($arguments['modernEra']);
        Assert::false($arguments['headerValidation']);
        Assert::same($arguments['subscriptionLifetime'], 4.0);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('invalidProvider')]
    public function anUnsupportedValueFailsNamingTheParam(array $params, string $message): void
    {
        $caught = null;

        try {
            ServerOptions::fromParams($params);
        } catch (InvalidArgumentException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains($message);
    }

    public static function invalidProvider(): iterable
    {
        yield 'unknown protocol' => [['protocol_version' => '1999-01-01'], 'Unsupported MCP handshake protocol version "1999-01-01"; supported: 2024-11-05'];
        yield 'stateless revision' => [['protocol_version' => ProtocolVersion::V2026_07_28->value], '(the stateless era is "modern_era")'];
        yield 'result json' => [['result_json' => 'compct'], 'Unsupported result_json "compct"; supported: pretty, compact'];
        yield 'bus' => [['notifications' => ['bus' => 'redis']], 'Unsupported notifications.bus "redis"'];
    }

    public function theBusFollowsItsKind(): void
    {
        $container = new SimpleContainer([CacheInterface::class => new FakeCache(), CacheItemPoolInterface::class => new ArrayCachePool()]);
        $bus = static fn(string $kind): ?object => ServerOptions::fromParams(['notifications' => ['bus' => $kind]])->notificationBus($container);

        Assert::null($bus(''));
        Assert::instanceOf($bus('memory'), InMemoryNotificationBus::class);
        Assert::instanceOf($bus('psr16'), Psr16NotificationBus::class);
        Assert::instanceOf($bus('psr6'), Psr6NotificationBus::class);
    }

    public function busEntriesOfTwoServersNeverMeet(): void
    {
        $cache = new FakeCache();
        $bus = static fn(string $name): object => ServerOptions::fromParams(['server_name' => $name])->psr16NotificationBus($cache);

        Assert::equals($bus('a'), new Psr16NotificationBus(cache: $cache, prefix: 'mcp.n.' . substr(hash('sha256', 'a'), 0, 12) . '.'));
        Assert::notEquals($bus('a'), $bus('b'));
    }

    public function theSessionStoreIsPrivateAndPerApplication(): void
    {
        $directory = sys_get_temp_dir() . '/mcp-options-' . bin2hex(random_bytes(4));

        Assert::equals(
            ServerOptions::fromParams(['session' => ['dir' => $directory, 'ttl' => 5]])->sessionStore(),
            new PrivateFileSessionStore(directory: $directory, ttl: 5),
        );
        Assert::equals(
            ServerOptions::fromParams(['server_name' => 'mcp-options-test'])->sessionStore(),
            new PrivateFileSessionStore(directory: SessionDirectory::resolve('', 'mcp-options-test'), ttl: 3600),
        );
    }

    public function theSecretMiddlewareFollowsTheParams(): void
    {
        $factory = new Psr17Factory();

        Assert::equals(ServerOptions::fromParams([])->sharedSecretMiddleware($factory), new SharedSecretMiddleware(secret: '', responseFactory: $factory, headerName: 'X-Mcp-Secret'));
        Assert::equals(
            ServerOptions::fromParams(['endpoint_secret' => 's', 'secret_header' => 'X-Key'])->sharedSecretMiddleware($factory),
            new SharedSecretMiddleware(secret: 's', responseFactory: $factory, headerName: 'X-Key'),
        );
        Assert::equals(
            ServerOptions::fromParams(['client_secrets' => ['ci' => 's']])->sharedSecretMiddleware($factory),
            new SharedSecretMiddleware(secret: '', responseFactory: $factory, headerName: 'X-Mcp-Secret', resolver: new StaticSecretResolver(['ci' => 's'])),
        );
    }
}

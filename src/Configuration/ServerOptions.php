<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Configuration;

use InvalidArgumentException;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Fiber\FiberContextInterface;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Subscription\Psr6NotificationBus;
use Mcp\Server\Wire\CachePolicy;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Mcp\CachePolicyParams;
use Rasuvaeff\Mcp\Identity\StaticSecretResolver;
use Rasuvaeff\Mcp\McpServerFactory;
use Rasuvaeff\Mcp\Session\PrivateFileSessionStore;
use Rasuvaeff\Mcp\Session\SessionDirectory;
use Rasuvaeff\Mcp\SharedSecretMiddleware;

/**
 * The scalar server params, validated once: what a typo would otherwise turn
 * into a setting silently ignored fails here, with the param named. Shared by
 * the framework packages: the keys are the same in each of them.
 *
 * @api
 */
final readonly class ServerOptions
{
    /**
     * @param array<string, mixed> $params
     */
    private function __construct(
        private array $params,
        public string $serverName,
        public ?ProtocolVersion $protocolVersion,
        public bool $compactToolResults,
        public string $notificationBus,
        public ?CachePolicy $cachePolicy,
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params): self
    {
        $protocolVersionParam = (string) ($params['protocol_version'] ?? '');
        $handshakeVersion = ProtocolVersion::tryFrom($protocolVersionParam);
        $protocolVersion = $protocolVersionParam === ''
            ? null
            : ($handshakeVersion instanceof ProtocolVersion && !$handshakeVersion->isModern()
                ? $handshakeVersion
                : throw new InvalidArgumentException(sprintf(
                    'Unsupported MCP handshake protocol version "%s"; supported: %s (the stateless era is "modern_era")',
                    $protocolVersionParam,
                    implode(', ', array_map(static fn(ProtocolVersion $version): string => $version->value, ProtocolVersion::handshakeVersions())),
                )));

        // a typo like "compct" would otherwise silently serve one of the two forever
        $resultJson = (string) ($params['result_json'] ?? McpServerFactory::RESULT_JSON_COMPACT);

        if (!in_array($resultJson, [McpServerFactory::RESULT_JSON_PRETTY, McpServerFactory::RESULT_JSON_COMPACT], strict: true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported result_json "%s"; supported: %s, %s',
                $resultJson,
                McpServerFactory::RESULT_JSON_PRETTY,
                McpServerFactory::RESULT_JSON_COMPACT,
            ));
        }

        // an unknown kind would otherwise leave stateless listen streams silently empty
        /** @var array<string, mixed> $notifications */
        $notifications = $params['notifications'] ?? [];
        $bus = (string) ($notifications['bus'] ?? '');

        if (!in_array($bus, ['', 'psr16', 'psr6', 'memory'], strict: true)) {
            throw new InvalidArgumentException(sprintf('Unsupported notifications.bus "%s"; supported: "" (none), psr16, psr6, memory', $bus));
        }

        /** @var array<array-key, mixed> $cachePolicy */
        $cachePolicy = $params['cache_policy'] ?? [];

        return new self(
            params: $params,
            serverName: (string) ($params['server_name'] ?? 'mcp'),
            protocolVersion: $protocolVersion,
            compactToolResults: $resultJson === McpServerFactory::RESULT_JSON_COMPACT,
            notificationBus: $bus,
            // parsed here: a typo in a method name would be a hint never sent
            cachePolicy: CachePolicyParams::parse($cachePolicy),
        );
    }

    /**
     * Owner-only (0700 directory, 0600 files) and application-specific by
     * default: session JSON holds everything needed to replay a session id.
     */
    public function sessionStore(): SessionStoreInterface
    {
        /** @var array{dir?: string, ttl?: int} $session */
        $session = $this->params['session'] ?? [];

        return new PrivateFileSessionStore(
            directory: SessionDirectory::resolve($session['dir'] ?? '', $this->serverName),
            ttl: $session['ttl'] ?? 3600,
        );
    }

    /**
     * Null when no bus is configured. The factory and ResourceUpdateNotifier
     * must share ONE instance: a memory bus the notifier publishes to must be
     * the one listen streams read.
     */
    public function notificationBus(ContainerInterface $container): ?NotificationBusInterface
    {
        return match ($this->notificationBus) {
            'memory' => new InMemoryNotificationBus(),
            'psr16' => $this->psr16NotificationBus($this->service($container, CacheInterface::class)),
            'psr6' => $this->psr6NotificationBus($this->service($container, CacheItemPoolInterface::class)),
            default => null,
        };
    }

    public function psr16NotificationBus(CacheInterface $cache): NotificationBusInterface
    {
        return new Psr16NotificationBus(cache: $cache, prefix: $this->busPrefix());
    }

    public function psr6NotificationBus(CacheItemPoolInterface $cache): NotificationBusInterface
    {
        return new Psr6NotificationBus(cache: $cache, prefix: $this->busPrefix());
    }

    /**
     * Named constructor arguments of {@see McpServerFactory}: everything but
     * the services (container, session store, logger, subscription manager,
     * notification bus, fiber context).
     *
     * @return array{name: string, version: string, instructions: string, paginationLimit: int, protocolVersion: ?ProtocolVersion, compactToolResults: bool, modernEra: bool, headerValidation: bool, cachePolicy: ?CachePolicy, requestStateKey: string, requestStateTtl: int, previousRequestStateKeys: list<string>, bindRequestState: bool, subscriptionLifetime: float}
     */
    public function factoryArguments(): array
    {
        /** @var array{key?: string, ttl?: int, previous_keys?: array<array-key, string>, bind?: bool} $requestState */
        $requestState = $this->params['request_state'] ?? [];
        /** @var array{subscription_lifetime?: int|float} $notifications */
        $notifications = $this->params['notifications'] ?? [];

        return [
            'name' => $this->serverName,
            'version' => (string) ($this->params['server_version'] ?? 'dev'),
            'instructions' => (string) ($this->params['instructions'] ?? ''),
            'paginationLimit' => (int) ($this->params['pagination_limit'] ?? McpServerFactory::DEFAULT_PAGINATION_LIMIT),
            'protocolVersion' => $this->protocolVersion,
            'compactToolResults' => $this->compactToolResults,
            'modernEra' => (bool) ($this->params['modern_era'] ?? true),
            'headerValidation' => (bool) ($this->params['header_validation'] ?? true),
            'cachePolicy' => $this->cachePolicy,
            'requestStateKey' => $requestState['key'] ?? '',
            'requestStateTtl' => $requestState['ttl'] ?? 600,
            'previousRequestStateKeys' => array_values(array_map(strval(...), $requestState['previous_keys'] ?? [])),
            'bindRequestState' => $requestState['bind'] ?? true,
            'subscriptionLifetime' => (float) ($notifications['subscription_lifetime'] ?? McpServerFactory::DEFAULT_SUBSCRIPTION_LIFETIME),
        ];
    }

    public function serverFactory(
        ContainerInterface $container,
        SessionStoreInterface $sessionStore,
        ?LoggerInterface $logger = null,
        ?SubscriptionManagerInterface $subscriptionManager = null,
        ?NotificationBusInterface $notificationBus = null,
        ?FiberContextInterface $fiberContext = null,
    ): McpServerFactory {
        $arguments = $this->factoryArguments();

        return new McpServerFactory(
            container: $container,
            sessionStore: $sessionStore,
            name: $arguments['name'],
            version: $arguments['version'],
            logger: $logger,
            instructions: $arguments['instructions'],
            paginationLimit: $arguments['paginationLimit'],
            protocolVersion: $arguments['protocolVersion'],
            subscriptionManager: $subscriptionManager,
            compactToolResults: $arguments['compactToolResults'],
            modernEra: $arguments['modernEra'],
            headerValidation: $arguments['headerValidation'],
            notificationBus: $notificationBus,
            subscriptionLifetime: $arguments['subscriptionLifetime'],
            requestStateKey: $arguments['requestStateKey'],
            requestStateTtl: $arguments['requestStateTtl'],
            cachePolicy: $arguments['cachePolicy'],
            previousRequestStateKeys: $arguments['previousRequestStateKeys'],
            bindRequestState: $arguments['bindRequestState'],
            fiberContext: $fiberContext,
        );
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        /** @var list<string> */
        return $this->params['allowed_hosts'] ?? [];
    }

    public function sharedSecretMiddleware(ResponseFactoryInterface $responseFactory): SharedSecretMiddleware
    {
        /** @var array<string, string|list<string>> $clientSecrets */
        $clientSecrets = $this->params['client_secrets'] ?? [];

        return new SharedSecretMiddleware(
            secret: (string) ($this->params['endpoint_secret'] ?? ''),
            responseFactory: $responseFactory,
            headerName: (string) ($this->params['secret_header'] ?? 'X-Mcp-Secret'),
            resolver: $clientSecrets === [] ? null : new StaticSecretResolver($clientSecrets),
        );
    }

    /**
     * Per application and short: PSR-16 and PSR-6 guarantee 64-character keys
     * and reserve the same characters.
     */
    private function busPrefix(): string
    {
        return 'mcp.n.' . substr(hash('sha256', $this->serverName), 0, 12) . '.';
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(ContainerInterface $container, string $id): object
    {
        /** @var T */
        return $container->get($id);
    }
}

# rasuvaeff/mcp

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/mcp?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/mcp)](https://packagist.org/packages/rasuvaeff/mcp)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp/build.yml?branch=master)](https://github.com/rasuvaeff/mcp/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/mcp/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/mcp/blob/master/psalm.xml)
[![License](https://img.shields.io/packagist/l/rasuvaeff/mcp)](LICENSE.md)

[Русская версия](README.ru.md)

Framework-neutral [Model Context Protocol](https://modelcontextprotocol.io)
server runtime over the official [`mcp/sdk`](https://packagist.org/packages/mcp/sdk):
attribute tools resolved through any PSR-11 container, interceptors, visibility
filters, session ownership and OAuth, served over PSR-15 or stdio. Using Yii3?
Install [`rasuvaeff/yii3-mcp`](https://github.com/rasuvaeff/yii3-mcp), which wires
this package for you.

**[Documentation](https://rasuvaeff.github.io/mcp/)** — guide, Yii3 integration, bridges, cookbook and API reference.

> **Using an AI coding assistant?** [llms.txt](llms.txt) contains a compact API
> reference you can share with the model. Contributors: see [AGENTS.md](AGENTS.md).
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer
> plugin also get this package's agent skill synced into `.agents/skills/`
> automatically on install.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 – 8.6 |
| `mcp/sdk` | `~0.10.0` (experimental until 1.0, hence the tilde pin) |
| `ext-fileinfo` | optional: MIME detection of file content |
| PSR-7/PSR-17 implementation | any, for the HTTP endpoint |
| `firebase/php-jwt` | optional, `^7.0`: JWT validation for OAuth |

## Installation

```bash
composer require rasuvaeff/mcp
```

## Usage

### Declare a tool

Protocol attributes come from the SDK; the class is an ordinary service.

```php
use Mcp\Server\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->get($orderId)->status;
    }
}
```

### Serve it over HTTP

```php
use Rasuvaeff\Mcp\{McpAction, McpServerFactory, SharedSecretMiddleware};
use Rasuvaeff\Mcp\Session\PrivateFileSessionStore;

$store = new PrivateFileSessionStore(directory: '/var/lib/my-app/mcp-sessions', ttl: 3600);
$server = (new McpServerFactory(
    container: $container, // PSR-11
    sessionStore: $store,
    name: 'my-app',
    version: '1.0.0',
))->create([OrderTools::class]);

$action = new McpAction(
    server: $server,
    responseFactory: $psr17,
    streamFactory: $psr17,
    allowedHosts: ['mcp.example.com'],
    sessionStore: $store,
);
$guard = new SharedSecretMiddleware(secret: getenv('MCP_SECRET') ?: '', responseFactory: $psr17);

// route POST/GET/DELETE/OPTIONS /mcp through $guard -> $action
```

Pass the same session store to the factory and to `McpAction`: without it a
session is only as private as its id. For stdio add `McpServeCommand` to any
`Symfony\Component\Console\Application`.

### Guard and shape calls

The first interceptor is the outermost one.

```php
use Rasuvaeff\Mcp\Interceptor\{RateLimitInterceptor, ResponseSizeLimitInterceptor, ToolCallBudgetInterceptor};
use Rasuvaeff\Mcp\Visibility\DeclarativeToolVisibility;

$server = $factory->create(
    toolClasses: [OrderTools::class],
    configurators: [$configurator],
    interceptors: [
        new ToolCallBudgetInterceptor(budget: 50, cache: $psr16, window: 3600),
        new RateLimitInterceptor($limiter),
        new ResponseSizeLimitInterceptor(maxBytes: 65_536),
    ],
    toolVisibility: new DeclarativeToolVisibility(deny: ['admin.*']),
);
```

### Test it

```php
use Rasuvaeff\Mcp\Testing\{McpTester, SchemaSnapshot};

$tester = new McpTester($server, $psr17, $psr17, $psr17);
$tester->callTool('order.status', ['orderId' => '42']);
SchemaSnapshot::verify($tester, __DIR__ . '/mcp-schema.json');
```

### Components

| Class | Role |
|---|---|
| `McpServerFactory` | tool class names → configured SDK `Server` |
| `McpAction` | PSR-15 Streamable HTTP endpoint; binds a session to its client |
| `McpServeCommand`, `McpListCommand` | `symfony/console` commands `mcp:serve` (stdio) and `mcp:list` |
| `SharedSecretMiddleware` | fail-closed secret guard; an empty secret answers 503 |
| `OAuth\BearerTokenMiddleware` | OAuth 2.1 resource-server guard over the SDK `AuthorizationMiddleware` |
| `Identity\SecretResolverInterface`, `Identity\StaticSecretResolver` | several clients per endpoint and secret rotation |
| `Identity\ExecutionIdentity`, `Identity\ExecutionIdentityProviderInterface` | on whose behalf a call runs |
| `Session\PrivateFileSessionStore` | owner-only file sessions (`0700`/`0600`) |
| `ConditionalToolInterface` | opts a tool class out of registration |
| `ServerConfiguratorInterface`, `ReservedToolNamesAwareInterface` | contribute capabilities to the SDK builder |
| `Interceptor\ToolCallInterceptorInterface`, `Interceptor\ToolCallContext` | wrap every `tools/call` |
| `Interceptor\PromptGetInterceptorInterface`, `Interceptor\PromptGetContext` | wrap every `prompts/get` |
| `Interceptor\ResourceReadInterceptorInterface`, `Interceptor\ResourceReadContext` | wrap every `resources/read` |
| `Interceptor\ToolCallBudgetInterceptor` | call cap per session or per client and window |
| `Interceptor\RateLimitInterceptor`, `Interceptor\ToolCallLimiterInterface` | delegates limits to your limiter, fail-closed |
| `Interceptor\ResponseSizeLimitInterceptor` | caps the tool result size |
| `Interceptor\CachingToolCallInterceptor` | PSR-16 cache per client for opted-in tools |
| `Interceptor\ToolResultDecoratorInterface` | edits the formatted result of a successful call |
| `Interceptor\ArgumentMasker`, `Interceptor\CallOutcome` | masking and outcome vocabulary for audit and telemetry |
| `Interceptor\InterceptingReferenceHandler` | wires the chains into the SDK (used by the factory) |
| `Visibility\ToolVisibilityInterface`, `Visibility\DeclarativeToolVisibility` | tool filters, by session or by name pattern |
| `Visibility\PromptVisibilityInterface`, `Visibility\ResourceVisibilityInterface` | prompt and resource filters |
| `Resource\ResourceUpdateNotifier` | sends `notifications/resources/updated` |
| `Apps\McpAppsConfigurator`, `Apps\AppDefinition` | MCP Apps (`ui://` resources) |
| `Testing\McpTester`, `Testing\McpErrorException`, `Testing\SchemaSnapshot` | in-process client and contract snapshot |
| `Configuration\ServerOptions`, `Configuration\ServerComponentResolver` | for framework packages: server params → factory arguments and components |
| `Testing\AdapterContract`, `Testing\AdapterContractViolation`, `Testing\AdapterContractTool`, `Testing\AdapterContractDependency` | for framework packages: the behaviour every adapter must keep |
| `Exception\DuplicateCapabilityException` | two capabilities share one identity; the build fails |
| `Exception\InvalidToolClassException` | a tool class is missing or has no capability attributes |
| `Exception\SessionOwnershipException` | a call came from a client that does not own the session |

### Long-running workers

No class keeps mutable static state. The client id travels with the request (as
the SDK's per-request `AccessToken`), so interleaved requests of one process
(Fibers, Swoole coroutines) keep their own identity; this is covered by a test on
bare Fibers.

A `subscriptions/listen` stream is written with `echo` and `flush()` by the SDK. Before it starts,
the buffers PHP opened itself (`output_buffering`) are ended, so frames leave one by one under
PHP-FPM; a buffer with a handler of its own stays, because that is how a long-running server takes
the response. Swoole streams the frames live. Under RoadRunner the PSR-7 capture delivers the
stream in one piece when it ends. A runner that answers through STDOUT without such a buffer
(`yiisoft/yii-runner-roadrunner` may be one, not checked) is not supported for listen streams.

## Security

- The endpoint is trusted-only: put `SharedSecretMiddleware`,
  `OAuth\BearerTokenMiddleware` or a network ACL in front of `McpAction`.
- An empty secret rejects every request; a hidden capability answers exactly
  like a missing one; a limiter or identity failure rejects the call.
- Capability names are unique across the server, enforced at build time.
- Tool annotations are hints for the client, never authorization.

## Examples

See [examples/](examples/): 10 scripts, all run offline.

## Development

No PHP/Composer on the host, run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).

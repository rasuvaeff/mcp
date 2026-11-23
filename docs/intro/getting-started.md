---
title: "Getting started"
description: "Install rasuvaeff/mcp, declare your first MCP tool and serve it over Streamable HTTP or stdio from any PHP application with a PSR-11 container."
---

# Getting started

Using Yii3? Go to [Yii3: getting started](/yii3/getting-started), which does the
wiring below from params.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 – 8.6 |
| `mcp/sdk` | `~0.10.0` (experimental until 1.0 — hence the tilde pin) |
| MCP protocol | 2024-11-05 … 2025-11-25 (negotiated) and 2026-07-28 stateless |
| `ext-fileinfo` | required by the SDK |
| PSR-7/PSR-17 implementation | any, for the HTTP endpoint |

## Install

```bash
composer require rasuvaeff/mcp
```

## 1. Declare a tool

Tools are ordinary services; the attributes are the SDK's own:

```php
use Mcp\Server\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->get($orderId)->status->value;
    }
}
```

See [Capabilities](/capabilities) for resources, prompts and completions.

## 2. Serve it

```php
use Rasuvaeff\Mcp\{McpAction, McpServerFactory, SharedSecretMiddleware};
use Rasuvaeff\Mcp\Session\PrivateFileSessionStore;

// any PSR-11 container — Yii3's, PHP-DI, Laravel's, a hand-rolled one
$container = /* ... */;

$sessionStore = new PrivateFileSessionStore(directory: '/var/lib/my-app/mcp-sessions', ttl: 3600);
$factory = new McpServerFactory(container: $container, sessionStore: $sessionStore, name: 'my-app', version: '1.0.0');
$server = $factory->create([OrderTools::class]);

$psr17 = /* any PSR-17 factory, e.g. nyholm/psr7 or guzzlehttp/psr7 */;
$action = new McpAction(server: $server, responseFactory: $psr17, streamFactory: $psr17, sessionStore: $sessionStore);
$middleware = new SharedSecretMiddleware(secret: getenv('MCP_SECRET') ?: '', responseFactory: $psr17);

// route POST/GET/DELETE/OPTIONS /mcp through $middleware -> $action
// in whatever middleware-dispatch shape the framework's router expects
```

Pass the same session store to the factory and to `McpAction`: without it a session
is only as private as its id. An empty secret answers every request with 503; see
[Security](/security).

## 3. stdio for local development

`McpServeCommand` extends `Symfony\Component\Console\Command\Command`: add it to any
`Symfony\Component\Console\Application`.

## 4. Add what you need

Everything documented on this site is a constructor argument:

```php
$server = $factory->create(
    toolClasses: [OrderTools::class],
    configurators: [$openApiServerConfigurator],
    interceptors: [new ToolCallBudgetInterceptor(50), $tracingInterceptor],
    toolVisibility: $planBasedVisibility,
);
```

Where a page shows `'rasuvaeff/yii3-mcp' => [...]` params, that is the Yii3 spelling of
the same constructor argument.

## Next steps

- [Architecture](/intro/architecture) — how `McpServerFactory` assembles the server.
- [Security](/security), [Interceptors](/interceptors), [Visibility](/visibility).
- [OpenAPI bridge](/openapi-bridge) — needs `rasuvaeff/mcp-openapi`.

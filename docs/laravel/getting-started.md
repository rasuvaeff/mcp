---
title: "Laravel: getting started"
description: "Install rasuvaeff/laravel-mcp, declare an MCP tool as a Laravel service and serve it over Streamable HTTP or stdio — on FrankenPHP, Octane, Swoole or RoadRunner."
---

# Laravel: getting started

`rasuvaeff/laravel-mcp` wires the runtime into Laravel: a service provider, a published config
file, a route and the `mcp:list` / `mcp:serve` commands. The options are the ones
[Yii3 uses](/yii3/configuration), read from `config/rasuvaeff-mcp.php`.

::: tip Which package
[`laravel/mcp`](https://github.com/laravel/mcp) is the official package, with its own protocol
implementation, generators and a client. Choose this one for the same server code in several
frameworks, the [OpenAPI bridge](/openapi-bridge), or the [interceptors](/interceptors) and
[visibility](/visibility) of `rasuvaeff/mcp`.
:::

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 – 8.6 |
| `laravel/framework` | `^12.41 \|\| ^13.0` |
| `rasuvaeff/mcp` | `^0.1.4` |
| `firebase/php-jwt` | optional, `^7.0`: JWT validation for OAuth |

## Install

```bash
composer require rasuvaeff/laravel-mcp
php artisan vendor:publish --tag=rasuvaeff-mcp
```

## 1. Declare a tool

Tools are container services: constructor dependencies are autowired.

```php
use Mcp\Server\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->status($orderId);
    }
}
```

## 2. Configure

```php
// config/rasuvaeff-mcp.php
'tools' => [App\Mcp\OrderTools::class],
'endpoint_secret' => env('MCP_SECRET', ''),
'allowed_hosts' => ['mcp.example.com'],
```

The endpoint is `/mcp`. Without a secret it answers 503 to everything.

```bash
php artisan mcp:list    # what is served
php artisan mcp:serve   # stdio, for a local agent
```

## Configuration

`route.path` is the endpoint (`mcp` by default, `''` registers none) and `route.middleware`
runs before the guard. `guard` is `secret` or `oauth`. Every other key is the one
`rasuvaeff/yii3-mcp` uses; the published file documents each.

Serve `ProtectedResourceMetadataHandler` from a route of your own at
`/.well-known/oauth-protected-resource/<path>`:

```php
// routes/web.php
Route::get(
    '/.well-known/oauth-protected-resource/mcp',
    fn(ServerRequestInterface $request, ProtectedResourceMetadataHandler $handler) => $handler->handle($request),
);
```

The OpenAPI bridge and OAuth call out through the PSR-18 `ClientInterface`: Guzzle by default,
a binding of yours wins. For a timeout bind `new Client(['timeout' => 5])`.

## Several nodes

Stateless calls need nothing. For sessions bind a shared store; notifications and the call budget
follow the cache of the application, so point it at Redis:

```php
$this->app->singleton(SessionStoreInterface::class, fn($app) => new Psr16SessionStore($app->make(CacheInterface::class)));
```

The call budget counts over PSR-16, which has no atomic increment: calls sent at the same moment
may all pass. It stops a loop, it is not a quota.

Keycloak puts your endpoint in `aud` only through an audience mapper on the client; without it
every token is refused with 401.

While the session store is unavailable, `initialize` still answers with a session id: the SDK logs the failed save, and the next request with that id gets 404, after which the client opens a new session.

## Long-running workers

- Checked on FrankenPHP (classic and worker), Swoole and RoadRunner. Under RoadRunner a `subscriptions/listen` stream arrives in one piece when it ends: the PSR-7 bridge captures the whole body. Keep `notifications.subscription_lifetime` short there, or serve listen streams from another runtime.
- `guard` other than `secret` or `oauth`, an empty one included, fails with `Unknown MCP guard`.
- The route is outside the `web` group: no cookie session and no CSRF token.
- The server is `scoped`: under Octane it is built per request and dropped with it. Sessions on disk and the notification bus stay.
- `config:cache` works: the config holds no closures, `apps.definitions[].html` is a string.

## Components

| Class | Role |
|---|---|
| `McpServiceProvider` | bindings, config, route and commands |
| `HttpEndpoint` | route action: guard, then the MCP endpoint |
| `Guard` | `secret` or `oauth` |

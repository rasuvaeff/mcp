---
title: "rasuvaeff/laravel-mcp"
description: "rasuvaeff/laravel-mcp — Laravel integration of the rasuvaeff/mcp MCP server runtime. Requirements, key classes, and where to start."
---

# rasuvaeff/laravel-mcp

Laravel integration of the [`rasuvaeff/mcp`](/packages/mcp) runtime and the
[OpenAPI bridge](/packages/mcp-openapi): tools from the container, a Streamable HTTP endpoint,
console commands.

```bash
composer require rasuvaeff/laravel-mcp
```

| | |
|---|---|
| Namespace | `Rasuvaeff\LaravelMcp` (runtime classes: `Rasuvaeff\Mcp`) |
| Repository | [github.com/rasuvaeff/laravel-mcp](https://github.com/rasuvaeff/laravel-mcp) |
| Requirements | PHP 8.3 – 8.6, Laravel ^12.41 \|\| ^13.0 |

## Where to start

- [Laravel: getting started](/laravel/getting-started)
- [Capabilities](/capabilities), [Security](/security), [OAuth 2.1](/oauth), [Interceptors](/interceptors), [Visibility](/visibility)
- [OpenAPI bridge](/openapi-bridge), [Operations](/operations)

## Key classes

| Class | Role |
|---|---|
| `McpServiceProvider` | Bindings, config, route and commands. |
| `HttpEndpoint` | Route action: guard, then the MCP endpoint. |
| `Guard` | `secret` or `oauth`. |

Runtime classes are in [`rasuvaeff/mcp`](/packages/mcp).

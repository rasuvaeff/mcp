---
title: "rasuvaeff/symfony-mcp"
description: "rasuvaeff/symfony-mcp — Symfony integration of the rasuvaeff/mcp MCP server runtime. Requirements, key classes, and where to start."
---

# rasuvaeff/symfony-mcp

Symfony integration of the [`rasuvaeff/mcp`](/packages/mcp) runtime and the
[OpenAPI bridge](/packages/mcp-openapi): tools from the container, a Streamable HTTP endpoint,
console commands.

```bash
composer require rasuvaeff/symfony-mcp
```

| | |
|---|---|
| Namespace | `Rasuvaeff\SymfonyMcp` (runtime classes: `Rasuvaeff\Mcp`) |
| Repository | [github.com/rasuvaeff/symfony-mcp](https://github.com/rasuvaeff/symfony-mcp) |
| Requirements | PHP 8.3 – 8.6, Symfony ^7.4 \|\| ^8.0 |

## Where to start

- [Symfony: getting started](/symfony/getting-started)
- [Capabilities](/capabilities), [Security](/security), [OAuth 2.1](/oauth), [Interceptors](/interceptors), [Visibility](/visibility)
- [OpenAPI bridge](/openapi-bridge), [Operations](/operations)

## Key classes

| Class | Role |
|---|---|
| [`RasuvaeffMcpBundle`](/api/classes/RasuvaeffMcpBundle) | The bundle. |
| [`McpController`](/api/classes/McpController) | Route action: guard, then the MCP endpoint. |
| [`McpRuntime`](/api/classes/McpRuntime) | Builds and resets the server. |
| [`Guard`](/api/classes/SymfonyMcpGuard) | `secret` or `oauth`. |

Runtime classes are in [`rasuvaeff/mcp`](/packages/mcp).

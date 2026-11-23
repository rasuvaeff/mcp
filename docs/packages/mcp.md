---
title: "rasuvaeff/mcp"
description: "rasuvaeff/mcp — Framework-neutral MCP server runtime over the official mcp/sdk. Requirements and key classes."
---

# rasuvaeff/mcp

Framework-neutral MCP server runtime over the official mcp/sdk.

```bash
composer require rasuvaeff/mcp
```

| | |
|---|---|
| Namespace | `Rasuvaeff\Mcp` |
| Repository | [github.com/rasuvaeff/mcp](https://github.com/rasuvaeff/mcp) |
| Requirements | PHP 8.3 – 8.6, `mcp/sdk ~0.10.0`, `ext-fileinfo` |

## Key classes

| Class | Role |
|---|---|
| [`McpServerFactory`](/api/classes/McpServerFactory) | List of tool FQCNs → configured SDK `Server`. |
| [`McpAction`](/api/classes/McpAction) | PSR-15 handler running the Streamable HTTP transport; enforces session ownership. |
| [`SharedSecretMiddleware`](/api/classes/SharedSecretMiddleware) | Fail-closed shared-secret guard; resolves client identity. |
| [`McpServeCommand`](/api/classes/McpServeCommand) | `mcp:serve` — stdio transport. |
| [`McpListCommand`](/api/classes/McpListCommand) | `mcp:list` — introspection. |
| [`Testing\McpTester`](/api/classes/McpTester) | In-process test client. |
| [`Testing\SchemaSnapshot`](/api/classes/SchemaSnapshot) | Committed-snapshot contract-drift guard. |

See the [API reference](/api/index) for every `@api` class.

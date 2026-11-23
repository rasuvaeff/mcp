---
title: "rasuvaeff/mcp-openapi"
description: "rasuvaeff/mcp-openapi — Serve allow-listed OpenAPI operations as MCP tools. Requirements and key classes."
---

# rasuvaeff/mcp-openapi

Serve allow-listed OpenAPI operations as MCP tools.

```bash
composer require rasuvaeff/mcp-openapi
```

| | |
|---|---|
| Namespace | `Rasuvaeff\Mcp\OpenApi` |
| Repository | [github.com/rasuvaeff/mcp-openapi](https://github.com/rasuvaeff/mcp-openapi) |
| Requirements | PHP 8.3 – 8.6, `rasuvaeff/mcp ^0.1`, `rasuvaeff/openapi-contract ^0.19` |

## Key classes

| Class | Role |
|---|---|
| [`OpenApiBridgeFactory`](/openapi-bridge) | Builds the bridge from a spec and PSR services. |
| [`OpenApiServerConfigurator`](/api/classes/OpenApiServerConfigurator) | Registers the allow-listed operations. |
| [`OperationModifierInterface`](/api/classes/OperationModifierInterface) | Customizes a tool per operation. |
| [`SpecLoader`](/api/classes/SpecLoader) | Fetches a URL spec, with an optional PSR-16 cache. |

See the [API reference](/api/index) for every `@api` class.

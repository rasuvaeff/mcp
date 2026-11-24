---
title: "rasuvaeff/yii3-mcp"
description: "rasuvaeff/yii3-mcp — MCP server integration for Yii3 over the official mcp/sdk. Requirements, key classes, and where to start."
---

# rasuvaeff/yii3-mcp

The Yii3 package over the [`rasuvaeff/mcp`](/packages/mcp) runtime, the [OpenAPI bridge](/packages/mcp-openapi) and the official
[`mcp/sdk`](https://packagist.org/packages/mcp/sdk) — a PSR-15 Streamable
HTTP endpoint, a stdio transport, and a DI-resolved tool registry.

```bash
composer require rasuvaeff/yii3-mcp
```

| | |
|---|---|
| Namespace | `Rasuvaeff\Yii3Mcp` (runtime classes: `Rasuvaeff\Mcp`) |
| Repository | [github.com/rasuvaeff/yii3-mcp](https://github.com/rasuvaeff/yii3-mcp) |
| Requirements | PHP 8.3 – 8.6, `mcp/sdk ~0.10.0` |

## Where to start

- [What is MCP](/intro/what-is-mcp) and [Yii3: getting started](/yii3/getting-started)
- [Architecture](/intro/architecture) — how `McpServerFactory` assembles the server
- [Capabilities](/capabilities), [Security](/security), [Interceptors](/interceptors), [Visibility](/visibility)
- [OpenAPI bridge](/openapi-bridge), [MCP Apps](/apps), [Multi-tenant serving](/multi-tenant)
- [Operations](/operations) — `mcp:list` / `mcp:doctor`

## Key classes

| Class | Role |
|---|---|
| [`McpDoctorCommand`](/api/classes/McpDoctorCommand) | `mcp:doctor` — configuration health check. |
| [`Doctor\McpDoctor`](/api/classes/McpDoctor) | The diagnostics service behind it. |

Runtime classes are in [`rasuvaeff/mcp`](/packages/mcp).

## Bridges

Three companion packages extend this core without any special-casing —
see [Bridges: overview](/bridges/overview):
[audit log](/packages/audit-log-bridge),
[RBAC](/packages/rbac-bridge),
[telemetry](/packages/telemetry-bridge).

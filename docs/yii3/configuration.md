---
title: "Yii3: configuration"
description: "How rasuvaeff/yii3-mcp builds the MCP server from yiisoft/config params and DI definitions, and which PSR services it expects in the container."
---

# Yii3: configuration

## config/di.php and config/params.php

The server is driven from one params namespace,
`params['rasuvaeff/yii3-mcp']`, read once when the `Server` service is built.
The config file remains the composition root: its internal
`McpServerComponentResolver` resolves class-string extension points through
the application container, while `McpServerAssembler` receives only the
resulting typed components and has no container dependency.

- `SessionStoreInterface` is bound to `Session\PrivateFileSessionStore`
  (owner-only, FPM-safe) by default — see [Security](/security#sessions-on-disk).
- `McpServerFactory` receives `server_name`, `server_version`,
  `instructions`, `pagination_limit`, and a `protocol_version` resolved
  **at config-load time**: an unsupported value throws immediately, not on
  the first request.
- `McpServerComponentResolver` assembles, in order: the OpenAPI bridge configurator
  (if `openapi.spec_path` and `openapi.operations` are both non-empty), the
  MCP Apps configurator (if `apps.enable` or `apps.definitions` is set), then
  every FQCN in `configurators`; the interceptor list (tool-call budget →
  `interceptors` → caching → size limit, see [Interceptors](/interceptors));
  and the visibility bindings (`tool_visibility` or declarative
  `visibility`, `prompt_visibility`, `resource_visibility`). The internal
  assembler then passes those ready components to `McpServerFactory`.
- `McpAction` receives the built `Server`, PSR-17 factories, `allowed_hosts`
  (for the transport's DNS-rebinding protection), and the same
  `SessionStoreInterface` — required for session-ownership enforcement (see
  [Security](/security#session-ownership)).
- `SharedSecretMiddleware` receives `endpoint_secret` (or a
  `StaticSecretResolver` built from `client_secrets`) and `secret_header`.
- `McpDoctor` (behind `mcp:doctor`) receives enough of the same config to
  diagnose it independently — see [Operations](/operations).

Everything is resolved through the container **inside**
`McpServerComponentResolver::resolve()` (`$container->get($interceptorClass)`),
not eagerly at config-parse time — so an interceptor, visibility class, or
identity provider is only instantiated when it is actually configured.

`mcp:serve`, `mcp:list` and `mcp:doctor` are registered through the same params; see
[Operations](/operations). `McpServerComponentResolver`, `McpDoctorCommand` and
`Doctor\*` live in `rasuvaeff/yii3-mcp`; everything else here is the runtime.

## Server-wide knobs

```php
'rasuvaeff/yii3-mcp' => [
    // free-form "how to use this server" text served in the initialize result —
    // the agent reads it before its first call. Empty = omitted.
    'instructions' => 'Prefer order.status over reading app://orders/{id}.',
    // page size for tools/resources/templates/prompts lists. Applies to the
    // SDK's handlers AND this package's filtering ones, so they can never page
    // differently depending on whether visibility is configured.
    'pagination_limit' => 50,
    // pins the initialize handshake to one revision, e.g. '2025-06-18'; empty
    // negotiates (the client's revision when supported, else 2025-11-25).
    // Handshake revisions only; anything else fails at config load.
    'protocol_version' => '',
    // serve the stateless 2026-07-28 era too (see "Protocol revisions")
    'modern_era' => true,
    // stateless era: reject requests whose standard headers contradict the body
    'header_validation' => true,
    // how array/object tool results are encoded into the text the agent
    // reads: 'compact' (default, the SDK's own encoding since 0.10) or
    // 'pretty' — indented, ~3x more bytes; structuredContent is unaffected.
    // An unsupported value fails at config load.
    'result_json' => 'compact',
],
```

A container binding of `Mcp\Server\Fiber\FiberContextInterface` is
autowired into `McpServerFactory` and handed to the SDK's
`Builder::setFiberContext()`: it carries execution context — the active
OpenTelemetry context, a correlation id — into the fibers tool, prompt and
resource handlers run in. Nothing is bound by default.

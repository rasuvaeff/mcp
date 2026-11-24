---
title: "Protocol"
description: "The MCP protocol in rasuvaeff/mcp: Streamable HTTP and stdio transports, session handling under PHP-FPM, and the served protocol version."
---

# Protocol

## Transports

| Transport | Entry point | Use case |
|---|---|---|
| Streamable HTTP | `McpAction` (PSR-15) | Production: an MCP client connects over the network with a shared-secret header. |
| stdio | `McpServeCommand` (`mcp:serve`) | Local development: the client spawns the command as a subprocess and speaks JSON-RPC over stdin/stdout. |

Both are served from the same `Mcp\Server\Server` instance built by
`McpServerFactory` — a tool class registers identically for either
transport.

### Streamable HTTP

`McpAction::handle()` runs the SDK's `StreamableHttpTransport` against the
current PSR-7 request. Requests are `POST`/`GET`/`DELETE`/`OPTIONS`; a session
is created on `initialize` and identified afterward by the `Mcp-Session-Id`
response header, which the client echoes back on every subsequent call.
Behind that:

- **CORS** and **DNS-rebinding protection** middleware wrap every call — the SDK's default host
  allow-list (`localhost`, `127.0.0.1`, `[::1]`) always applies; production
  deployments behind a real domain add it to `allowed_hosts`.
- The response may switch to **Server-Sent Events** framing mid-call whenever
  the handler suspends to send a progress/log notification or a sampling/
  elicitation round trip — clients must handle both plain-JSON and SSE-framed
  bodies.

### stdio

`McpServeCommand` extends `Symfony\Component\Console\Command\Command`
directly and runs the SDK's stdio transport over the process's own stdin/
stdout. There is no HTTP request, so `SharedSecretMiddleware` never runs and
the resolved client id is always `null` for interceptors and caching — see
[Security](/security#client-identity-and-secret-rotation).

## Sessions

An MCP Streamable HTTP session spans several HTTP requests: `initialize`
first, then `tools/call` (and friends) carrying the returned
`Mcp-Session-Id`. The SDK's default in-memory session store would lose the
session between PHP-FPM workers, so `rasuvaeff/yii3-mcp` binds
`Session\PrivateFileSessionStore` by default (elsewhere pass it to
`McpServerFactory` and `McpAction` yourself) — file-based, owner-only
(`0700` directory, `0600` files), under an application-specific directory
derived from `server_name`. For multi-host deployments, rebind
`SessionStoreInterface` to a shared PSR-16-backed store
(`Mcp\Server\Session\Psr16SessionStore`).

Sessions are also **bound to the client that created them** when a client
identity is configured — see [Security: session ownership](/security#session-ownership)
for the exact enforcement.

## Capabilities

The SDK negotiates capabilities during `initialize` based on what the built
`Server` actually has: tools, resources (with `resources.subscribe`
whenever any resource exists), prompts, and completion. See
[Capabilities](/capabilities) for how each is declared and served.

## Protocol revisions

One endpoint serves both protocol eras; each request is classified by the SDK.

| Era | Revisions | Lifecycle | State |
|---|---|---|---|
| handshake | 2024-11-05 … 2025-11-25 | `initialize` → `Mcp-Session-Id` on every request | in the session store (`session.*`) |
| stateless | 2026-07-28 | none: every request carries its own `_meta` (revision, client info, capabilities) and `Mcp-Method`/`Mcp-Name` headers | none — the SDK hands each request a throwaway session |

- `initialize` negotiates: the client's revision when supported, otherwise
  2025-11-25. `protocol_version` pins the handshake to one revision (handshake
  revisions only).
- `modern_era` (default `true`) serves the stateless era. Switched off, its
  requests get `Unsupported protocol version` — and a client speaking only
  2026-07-28 does not fall back to `initialize` by itself.
- `header_validation` (default `true`) rejects a stateless request whose
  standard headers contradict its body (`-32020`), so a proxy can route and
  authorize on the headers alone.
- Anything that keeps state "per session" means per request on the stateless
  era. The package's own session-bound guards are era-aware: the tool-call
  budget counts per client there, session ownership has nothing to bind (the
  client id of each request is the identity), and
  `clientInfo()` reads the request's `_meta`.
- Not found is `-32602` for tools, prompts and completion refs on every
  revision, and for resources from 2026-07-28 on (`-32002` before) — hidden
  capabilities answer exactly the same.

Do not hardcode a revision in application code that talks to the server: read it from
the SDK's `ProtocolVersion` enum, as `Testing\McpTester` does.

## Caching hints (stateless era)

Stateless answers to `server/discover`, `tools/list`, `prompts/list`,
`resources/list`, `resources/templates/list` and `resources/read` carry
SEP-2549 caching hints. The default is the SDK's: nothing fresh
(`ttlMs: 0`), nothing shared (`private`).

```php
'rasuvaeff/yii3-mcp' => [
    'cache_policy' => [
        'ttl_ms' => 0,
        'scope' => 'private',
        'methods' => [
            // the tool list changes only with a deploy
            'tools/list' => ['ttl_ms' => 300000, 'scope' => 'private'],
        ],
    ],
],
```

`public` lets a **shared** cache (a proxy) serve one caller's answer to
another — an operator decision. With any visibility filter configured, a
`public` hint on a list or `resources/read` fails the build: those answers are
per caller. A method that carries no hint, a misspelled one, or an unknown
scope fails at config load instead of being ignored.

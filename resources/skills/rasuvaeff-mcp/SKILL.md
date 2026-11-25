---
name: rasuvaeff-mcp
description: >-
  Serve application services as MCP tools/resources/prompts from any PHP
  framework over the official mcp/sdk with rasuvaeff/mcp — McpServerFactory,
  McpAction (PSR-15 Streamable HTTP), McpServeCommand (stdio),
  SharedSecretMiddleware, OAuth\BearerTokenMiddleware, interceptors
  (ToolCallBudgetInterceptor, RateLimitInterceptor, ResponseSizeLimitInterceptor,
  CachingToolCallInterceptor, ArgumentMasker), result decorators, visibility
  filters, Testing\McpTester + SchemaSnapshot. Use when writing, reviewing or
  debugging MCP server code in a project that has this package installed.
---

# rasuvaeff/mcp

Framework-neutral MCP server runtime, namespace `Rasuvaeff\Mcp\`. Protocol
structures (attributes, JSON-RPC, sessions, OAuth validation) come from `mcp/sdk`
(`~0.10.0`; minor = breaking): never invent them. In a Yii3 application use
`rasuvaeff/yii3-mcp`, which wires this package from params.

One endpoint serves two protocol eras (`modernEra`, default on): the
handshake era (`initialize`, `Mcp-Session-Id`, state in the session) and the
stateless 2026-07-28 era (no initialize, no session — the SDK hands every
request a THROWAWAY session; `_meta` and `Mcp-Method`/`Mcp-Name` headers per
request). Anything that keeps state "per session" does nothing on the
stateless era: read the era with care, never assume a session persists.

## Safety rules — verify these on every change

1. **The endpoint is trusted-only.** Put `SharedSecretMiddleware`,
   `OAuth\BearerTokenMiddleware` or a network ACL in front of `McpAction`. An empty
   secret answers every request with 503: never turn that into a pass-through, and
   never put the raw secret in logs or responses. A token without the configured
   identity claim is refused (401), never served anonymously.

2. **Tool visibility is fail-closed on both paths, and hidden ≡ missing.** A
   hidden tool is omitted from `tools/list` AND answered on `tools/call`
   with exactly the JSON-RPC error a missing tool gets (`-32602`,
   `Tool not found: "x".`), before argument validation, interceptors or the
   tool run. Same for hidden prompts, resources and completion refs. Compare
   whole envelopes (code + message + data), never only the text — a
   different code alone is an existence oracle.

3. **Set a tool-call budget against agent loops.**
   `new ToolCallBudgetInterceptor(budget: N, cache: $psr16, window: 3600)` rejects
   calls past N: per session on the handshake era, per client id and fixed window
   on the stateless era (counted in the PSR-16 cache; anonymous callers share one
   budget; a cache outage or a failed write rejects). It is anti-loop, NOT a
   client quota; for real quotas implement `ToolCallLimiterInterface` +
   `RateLimitInterceptor` (limiter failure is fail-closed).

4. **Mask arguments before logging/tracing/auditing.** Never log
   `ToolCallContext::$arguments` raw — pass them through `ArgumentMasker`
   (`password`, `secret`, `token`, `api_key`, `credit_card` by default; every
   nesting level, arrays and the `\stdClass` objects SDK 0.10 passes for
   nested JSON objects).

5. **Sessions must stay FPM-safe and owned.** Use `Session\PrivateFileSessionStore`
   (or another persistent store), never an in-memory one under FPM or several
   workers, and pass the SAME store to `McpServerFactory` and `McpAction`: without
   it a session is only as private as its id.

6. **Caching must never bypass access checks.** First interceptor = outermost. Keep
   RBAC, audit and limits before `CachingToolCallInterceptor`, the size limit last.
   With delegated credentials pass an `ExecutionIdentityProviderInterface` to the
   cache: results can be identity-specific, finer than the client id.

7. **Never truncate with `substr()` on a path that reaches the client.**
   Every truncated string ends up in a JSON-RPC response the SDK encodes
   with `JSON_THROW_ON_ERROR`; a split multi-byte character makes the encode
   fail and the Streamable HTTP transport then drops the response silently.
   Use `Utf8::cut()`; validate foreign bytes (an upstream error body)
   separately with `preg_match('//u', …)`.

8. **Add content to a tool result with a decorator, not an interceptor.**
   Interceptors see the RAW result; returning a hand-built `CallToolResult`
   from one re-implements formatting and the per-revision `structuredContent`
   rule, and drifts on the next SDK bump. Decorators
   (`ToolResultDecoratorInterface::decorate(CallToolResult, ToolCallContext)`, the
   `resultDecorators` argument of `create()`)
   get the formatted result after the whole chain — cache hits included,
   never an `input_required` ask, never a `ToolCallException`. Keep
   `structuredContent` unless the tool's `outputSchema` still describes it;
   when building a new `CallToolResult` pass
   `$result->hasStructuredContent() ? $result->structuredContent : StructuredContent::Absent`
   (an explicit `null` is sent).

9. **Keep no request state in static or shared properties.** The client id travels
   with the request; read it from `ToolCallContext::$clientId`. Interleaved requests
   (Fibers, Swoole) share every service instance.

## Canonical usage

```php
use Mcp\Server\Capability\Attribute\McpTool;
use Rasuvaeff\Mcp\{McpServerFactory, McpAction, SharedSecretMiddleware};

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {} // DI works

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string { ... }
}

$factory = new McpServerFactory(container: $c, sessionStore: $store, name: 'my-app', version: '1.0.0');
$server = $factory->create([OrderTools::class]);
// route POST/GET/DELETE/OPTIONS /mcp:
// SharedSecretMiddleware(secret: ..., responseFactory: $psr17) -> McpAction(server: ..., sessionStore: $store, ...)
```

Asking the user mid-call (`ClientGateway::elicit()`): on the stateless era
the request ends with `input_required` and the handler RUNS AGAIN FROM THE TOP
on the retry — keep side effects after the last ask. Two or more asks per call
need `McpServerFactory(requestStateKey:)` (>= 32 bytes, from env); rotate by moving the old key
to `previousRequestStateKeys`; keep `bindRequestState` on (a state is tied
to its request and client). Each round is a tools/call:
interceptors and the budget count every round. `sample()`/`listRoots()` throw
on 2026-07-28.

Resource updates: `ResourceUpdateNotifier::notify($uri, ?$context)` publishes
to the notification bus (a shared one under PHP-FPM, in-memory for one process) for stateless listen streams, and notifies the calling
handshake session if subscribed. Without a bus stateless subscribers get
nothing.

`$context->isStateless()` marks the stateless era: never log
`$context->session` ids or keep state in the session there.
Interceptors get `$context->requestContext` (SDK `RequestContext`): ask the
user before a call with `getClientGateway()->elicit()`, join the caller's
trace with `getTraceContext()`. Caching hints (`cachePolicy`) stay `private`
for anything a visibility filter makes per caller — the build enforces it.

Testing without HTTP: `Testing\McpTester` (`callTool`, `listTools`, ...);
without one it speaks the newest handshake revision
(`ProtocolVersion::latestHandshake()`); a fifth argument picks the revision —
`ProtocolVersion::V2026_07_28` drives
the stateless era (test both eras for anything session- or identity-related);
JSON-RPC errors throw `Testing\McpErrorException` (`errorCode`,
`errorMessage`, `errorData`). Interceptor contexts expose
`clientInfo(): ?Mcp\Schema\Implementation` for both eras. Schema drift gate:
`Testing\SchemaSnapshot::verify($tester, $file)` in CI.

## Full API

The complete reference ships with the package: read
`vendor/rasuvaeff/mcp/llms.txt` before guessing a class, argument or method name.

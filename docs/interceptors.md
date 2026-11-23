---
title: "Interceptors"
description: "Wrapping every MCP tools/call with interceptors: tool-call budget, argument masking, result caching, rate limiting, and the fixed interceptor chain order."
---

# Interceptors

::: tip Yii3 params
`'rasuvaeff/yii3-mcp' => [...]` snippets are Yii3 configuration. Elsewhere pass the same
value as a constructor argument; see [Getting started](/intro/getting-started#_4-add-what-you-need).
:::

`Interceptor\ToolCallInterceptorInterface` is the package's public extension
point around tool execution. The chain wraps **every** registration path —
attribute tools, OpenAPI-bridged operations, configurator-registered
handlers — so tracing, rate limiting, ACL, or caching live in one place,
without touching the tools themselves:

```php
use Rasuvaeff\Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Mcp\Interceptor\ToolCallInterceptorInterface;

final readonly class TracingInterceptor implements ToolCallInterceptorInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        // $context->toolName, $context->arguments, $context->session,
        // $context->clientId, $context->getClientInfo()
        $this->logger->info('tools/call', ['tool' => $context->toolName]);

        return $next();   // skip $next() to short-circuit
    }
}
```

```php
// config/params.php — resolved through the container, first = outermost
'rasuvaeff/yii3-mcp' => [
    'interceptors' => [TracingInterceptor::class],
],
```

Throwing `Mcp\Server\Exception\ToolCallException` from an interceptor rejects the
call with a regular MCP tool-error envelope (the agent sees the reason); any
other exception becomes an opaque internal error.

The same seam exists for the other two capability calls, with their own
interfaces so a tool policy never accidentally applies to a prompt:
`Interceptor\PromptGetInterceptorInterface` (`prompt_interceptors`, sees
`PromptGetContext`) and `Interceptor\ResourceReadInterceptorInterface`
(`resource_interceptors`, sees `ResourceReadContext` — carries the URI and,
for a template match, the extracted RFC 6570 variables). Rejecting from
either throws `Mcp\Server\Exception\PromptGetException` /
`Mcp\Server\Exception\ResourceReadException`; `completion/complete` is **not**
wrapped by any of these chains — see
[Visibility: completions](/visibility#completions-obey-the-same-filters).


`$context->isStateless()` tells the stateless era apart: there
`$context->session` is a throwaway built for the one request, so its id names
nothing (do not log it as a session) and nothing stored in it survives.
`$context->requestContext` is the SDK's `RequestContext` for the call (null
outside a server request): `getClientGateway()` lets an interceptor ask the
user before the call — the way to confirm a costly operation on a tool whose
handler the application does not own, such as an OpenAPI-bridged one — and
`getTraceContext()` carries the caller's W3C `traceparent`/`tracestate`/
`baggage` (stateless era). On the stateless era an ask ends the request and
the whole chain runs again on the retry: interceptors outside the asking one
run once per round (see "Server-initiated communication").

## The chain order is fixed and load-bearing

```
tools/call
  └─ ToolCallBudgetInterceptor        (outermost, only with a call budget)
       └─ configured `interceptors`  (in list order — first = outermost)
            └─ CachingToolCallInterceptor   (only if cache.tools is non-empty)
                 └─ ResponseSizeLimitInterceptor  (innermost, only if limits.tool_result_bytes > 0)
                      └─ the actual tool
```

This order is never configurable and must never be reordered: **caching
sits between your interceptors and the size limit, never around your
interceptors.** If caching wrapped around RBAC/audit, a cache hit would skip
them entirely — turning the cache into an ACL bypass. Configured
interceptors (RBAC, audit, tracing — including the three bridges) always
run, on every call, cache hit or miss. The size limit only runs on a cache
miss: the value it already limited is what gets cached, so a hit never
needs re-limiting.

## Tool-call budget: stop agent loops

A hard cap on `tools/call` in a row. An agent stuck in a loop burns the budget
and gets an explanatory tool error instead of hammering the application:

```php
'rasuvaeff/yii3-mcp' => [
    'tool_call_budget' => [
        'calls' => 50,     // 0 = unlimited (default)
        'window' => 3600,  // seconds, stateless era only
    ],
],
```

"In a row" depends on the protocol era:

| Era | Counted per | Reset by |
|---|---|---|
| handshake (`initialize`) | MCP session, in the session itself | a new session (re-initialize) or the session TTL |
| stateless 2026-07-28 | client id, in the container's PSR-16 cache | the fixed window turning (`floor(now / window)`) |

The stateless era has no session to count in — a session counter would start
at zero on every request — so with `modern_era` on, a budget requires a
`Psr\SimpleCache\CacheInterface` in the container (a missing one fails the
build). Without `SharedSecretMiddleware` there is no client id, and every
anonymous stateless caller shares **one** budget. A cache outage rejects the
call (fail-closed).

This is loop protection, not a client quota. Client quotas belong to an
application-level rate limiter. The budget guard is always the outermost
interceptor, so it rejects before any other interceptor does work. 3.x's
`tool_call_budget.calls` fails the build with a pointer to the new key.

## Masking sensitive arguments

Anything an interceptor sends out of the process — a log line, a trace
span, an audit record — must not carry secrets. `Interceptor\ArgumentMasker`
replaces the values of sensitive keys with `***`, case-insensitively, at
**every** nesting level:

```php
use Rasuvaeff\Mcp\Interceptor\ArgumentMasker;

$masker = new ArgumentMasker();                       // or: new ArgumentMasker(['ssn', 'password'])
$safe = $masker->mask($context->arguments);
// ['user' => ['name' => 'alice', 'password' => '***']]
```

The default key list: `password`/`pass`/`pwd`, `secret`, `token`/`bearer`/
`jwt`, `auth`/`authorization`, `cookie`, `api_key`/`apikey`/`api-key`/
`x-api-key`, `access_token`/`refresh_token`/`id_token`/`session_token`/
`auth_token` (and their kebab spellings), `client_secret`, `private_key`,
`credit_card`. Comparison is exact after lowercasing — `apikey` matches
`ApiKey` but not `apiKeyHeader`; pass your own list to cover
application-specific names. It is one shared helper so every consumer
(the [audit bridge](/bridges/audit-log), the
[telemetry bridge](/bridges/telemetry), your own interceptors) masks with
identical semantics instead of drifting apart.

## Result size limit and caching

A tool result has no natural upper bound — a bridged GET against a real API,
or a hand-written tool over a large table, can return megabytes of JSON and
burn an agent's context window. `limits.tool_result_bytes` truncates an
over-limit string result with an explicit marker; any other result (array,
object) is rejected outright instead, because a truncated JSON payload is
invalid JSON, not a smaller valid one:

```php
'rasuvaeff/yii3-mcp' => [
    'limits' => ['tool_result_bytes' => 0],   // 0 = unlimited (default)
],
```

The limit is a byte budget applied to the content: a multi-byte character
that does not fit whole is dropped rather than split (a broken UTF-8
sequence would make the JSON-RPC response unencodable, and the Streamable
HTTP transport drops such a response silently), and the marker itself is
appended on top of the budget. The marker reports the bytes actually kept.
For a string result the budget counts the **raw** string's bytes, before
JSON encoding (control characters expand on the wire, up to ~6x); for
arrays/objects it counts the JSON-encoded bytes. It bounds what reaches the
agent's context window — worker memory during production of the result is
bounded separately by `openapi.max_response_bytes`.

### Compact and pretty JSON tool results

Since SDK 0.10 the SDK itself encodes an array or object tool result as
compact JSON, and that is the package default. Pretty-printed text is ~3x
the bytes of the same payload — a `search` page of 20 cards costs the agent
tens of kilobytes of pure indentation — but stays available for clients
whose users read raw tool output:

```php
'rasuvaeff/yii3-mcp' => [
    // 'compact' (default, byte-identical to the SDK's own encoding:
    // JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) or 'pretty': the
    // package re-encodes array/object results with JSON_PRETTY_PRINT.
    // Anything else fails at config load.
    'result_json' => 'pretty',
],
```

What `pretty` changes — and what deliberately does not:

- only the **text content** of array/object results is re-encoded; string,
  int, float, bool and `null` results, and results that are already SDK
  `Content` objects, are served exactly as in `compact`;
- `structuredContent` is still produced for array results (an
  `outputSchema`-declaring tool keeps its structured output) — the pretty
  text is simply the indented JSON encoding of that same payload;
- interceptors, the tool-result cache and `limits.tool_result_bytes` all
  keep seeing the **raw** handler result: the conversion happens after the
  interceptor chain, right before the SDK would have formatted the result.
  The size limit's byte budget therefore measures the same thing it did
  before — the encoded result simply ends up smaller.

For read-heavy tools called repeatedly with the same arguments inside a
session (a lookup table, an OpenAPI GET), `cache.tools` skips the handler
entirely on a hit — opt in by tool name (for the OpenAPI bridge, the
**served** name, after any `tool_names` rename) with a TTL in seconds:

```php
'rasuvaeff/yii3-mcp' => [
    'cache' => [
        'tools' => ['blog_tags_list' => 60],
    ],
],
```

Requires a PSR-16 `CacheInterface` in the container. The cache key always
includes the resolved client id — a shared cache between distinct clients
would leak one client's result to another. The key material is typed: an
absent client id (stdio) encodes as `null` and can never collide with a
real client that happens to be named `anonymous`. The key also carries a
mandatory **namespace** (`cache.namespace` param, defaulting to
`server_name`): two applications sharing one cache backend — a common
Redis — with same-named tools must never read each other's results, and an
external PSR-16 prefix is defence in depth, not the primary isolation.
When `openapi.identity_provider`
is configured, the resolved `ExecutionIdentity` is part of the key too:
delegated upstream credentials mean the same tool and arguments can produce
identity-specific results, and the identity may be finer-grained than the
client id (many end users behind one MCP client). Only successful results
are cached; a thrown exception never is. A cache read/write failure fails
**open** (the tool runs) — this is an availability optimization, not a
security gate. An identity **provider** failure, by contrast, fails
**closed** for cached tools: serving a result without knowing whose it is
would be exactly the leak the key exists to prevent.

The key identifies the **MCP client**, not the application user behind it.
A tool whose result depends on who is logged in (reading the current user
from the application, not from the MCP identity) must therefore not be
cached unless `openapi.identity_provider` resolves that user — "idempotent
read" is not the same as "same answer for everyone". Bridged operations are
covered by the identity provider; hand-written tools are yours to judge.

Interceptor order is fixed: tool-call budget (outermost) → configured
`interceptors` → caching → result size limit (innermost, closest to the
actual tool call). Configured interceptors (RBAC, audit) always run, even on
a cache hit — caching cannot be used to bypass them. The size limit only
runs on a cache miss; the value it already limited is what gets cached, so a
hit never needs re-limiting.

### Decorating tool results

Interceptors see the raw handler result, so they cannot add content next to
it without re-implementing result formatting. A result decorator gets the
**formatted** `CallToolResult` instead — text in the configured
`result_json` mode, `structuredContent` by the rules of the request's
protocol revision — and returns it with whatever it adds: a `ResourceLink`
per item of a search result, an image, a note.

```php
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Enum\StructuredContent;
use Mcp\Schema\Result\CallToolResult;
use Rasuvaeff\Mcp\Interceptor\ToolCallContext;
use Rasuvaeff\Mcp\Interceptor\ToolResultDecoratorInterface;

final readonly class CreatorLinks implements ToolResultDecoratorInterface
{
    public function decorate(CallToolResult $result, ToolCallContext $context): CallToolResult
    {
        $items = is_array($result->structuredContent) ? ($result->structuredContent['items'] ?? []) : [];
        if ($context->toolName !== 'search_creators' || $result->isError || !is_array($items)) {
            return $result;
        }

        $links = [];
        foreach ($items as $item) {
            $links[] = new ResourceLink(uri: 'app://creator/' . $item['slug'], name: $item['slug']);
        }

        return new CallToolResult(
            [...$result->content, ...$links],
            $result->isError,
            // since SDK 0.10 an explicit null is SENT as structuredContent: pass Absent when there is none
            $result->hasStructuredContent() ? $result->structuredContent : StructuredContent::Absent,
            $result->meta,
        );
    }
}
```

```php
'rasuvaeff/yii3-mcp' => [
    // FQCNs resolved through the container, applied in order; a class that
    // does not implement ToolResultDecoratorInterface fails the build
    'result_decorators' => [CreatorLinks::class],
],
```

- Decorators run after the whole interceptor chain (budget, interceptors,
  the result cache, the size limit) on every successful `tools/call` —
  cache hits included, since the cache stores the raw result.
- What a decorator adds does not count against `limits.tool_result_bytes`,
  which measures the raw result inside the chain.
- Keep `structuredContent` unless the tool's `outputSchema` still describes
  the new value: clients validate it. When building a new `CallToolResult`,
  pass `StructuredContent::Absent` for a result without structured content —
  a plain `null` is sent as `"structuredContent": null`.
- A multi round-trip ask (`input_required`) is never decorated; the final
  round's result is. A `ToolCallException` never reaches a decorator (the
  SDK turns it into an error envelope after the handler); a handler that
  returns `CallToolResult::error()` itself does — check `$result->isError`.
- With decorators (and always in `pretty` mode) the package builds the
  result exactly as the SDK's `CallToolHandler` would — structured content
  through the SDK's `extractStructuredContent()` for the request's revision,
  a primitive structured value carried as its JSON text (SEP-2106) — in the
  configured encoding; the SDK's warning about a value that cannot be
  `structuredContent` despite an `outputSchema` is not logged on that path.
- `McpServerFactory::create(resultDecorators: [...])` takes them directly
  without `yiisoft/config`.

## Per-client rate limits (bring your own limiter)

This package deliberately ships no limiter storage. Implement
`Interceptor\ToolCallLimiterInterface` over the rate limiter your
application already runs, and wire `Interceptor\RateLimitInterceptor` into
`interceptors`:

```php
final readonly class AppToolCallLimiter implements ToolCallLimiterInterface
{
    public function __construct(private CounterInterface $counter) {}

    public function allow(?string $clientId, string $toolName): bool
    {
        return $this->counter->hit(($clientId ?? 'no-client') . ':' . $toolName)->isAllowed();
    }
}
```

The interceptor keys by resolved client id plus tool name. A transport
without identity (stdio) passes `null` — typed absence, not a reserved
string a real client id could collide with. **Fail-closed**: a limiter
backend throw rejects the call — an enforced quota must not silently become
"unlimited" during an outage.

## Retrying transient failures (bring your own retry)

This package ships no retry logic — a naive blanket retry duplicates side
effects on a non-idempotent tool. Scope any retry to an explicit allow-list
of verified-idempotent tools and to transient failure types only, e.g. with
[`rasuvaeff/retry`](https://github.com/rasuvaeff/retry):

```php
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Mcp\OpenApi\Exception\OperationFailedException;

final readonly class RetryInterceptor implements ToolCallInterceptorInterface
{
    /** @param list<string> $idempotentTools verified idempotent — never blanket-retry */
    public function __construct(private array $idempotentTools) {}

    public function intercept(ToolCallContext $context, callable $next): mixed
    {
        if (!in_array($context->toolName, $this->idempotentTools, true)) {
            return $next();
        }

        return Retry::new()
            ->maxAttempts(3)
            ->withExponential(baseMs: 100, multiplier: 2.0, capMs: 2_000)
            ->retryOn(OperationFailedException::class)   // transient failures only
            ->run($next);
    }
}
```

Place it near the end of `interceptors` (closest to the tool call) — an
interceptor listed *before* it (e.g. `RateLimitInterceptor`) wraps the whole
retry loop and is checked once per outer call, not once per attempt; listed
*after*, it would re-trigger on every retry.

## Hooks for prompts and resources

The same seams exist for the other capabilities. `prompts/get` and
`resources/read` (static resources and templates alike) each have their own
interceptor chain and visibility filter — separate interfaces, so a tool
policy never accidentally applies to a prompt:

```php
// config/params.php — each list resolved through the container, first = outermost
'rasuvaeff/yii3-mcp' => [
    'prompt_interceptors' => [PromptAuditInterceptor::class],     // Interceptor\PromptGetInterceptorInterface
    'resource_interceptors' => [ResourceAclInterceptor::class],   // Interceptor\ResourceReadInterceptorInterface
    'prompt_visibility' => PlanBasedPromptVisibility::class,      // Visibility\PromptVisibilityInterface
    'resource_visibility' => PlanBasedResourceVisibility::class,  // Visibility\ResourceVisibilityInterface
],
```

- `Interceptor\PromptGetContext` carries the prompt name, arguments, session
  and client id; `Interceptor\ResourceReadContext` carries the URI, the RFC
  6570 variables extracted from a template (with the matched `uriTemplate`)
  and the same identity fields.
- Rejecting: throw `Mcp\Server\Exception\PromptGetException` /
  `Mcp\Server\Exception\ResourceReadException` — the client sees the message.
  Hiding: visibility (or a thrown `*NotFoundException`) reports the
  capability as **not found**, indistinguishable from a missing one — a
  client that guesses a hidden prompt name or resource URI learns nothing,
  and the call never reaches the interceptors or the handler.
- Visibility filters `prompts/list`, `resources/list` and
  `resources/templates/list` with the same implementation that guards the
  direct calls, so listing and fetching can never disagree.
- **`completion/complete` obeys the same filters.** Argument autocompletion
  (`#[CompletionProvider]` on a prompt argument or a resource-template
  variable) is served by the SDK straight off the registry, so it used to
  answer for prompts and templates a session could not see — leaking both the
  suggested values and the capability's existence. It is now decorated with
  the configured prompt/resource visibility and reports a hidden ref as not
  found, exactly like a missing one.

For bridges (audit, telemetry) the core ships one shared outcome
vocabulary — `Interceptor\CallOutcome` (`success` / `rejected` / `error`,
with `CallOutcome::fromThrowable()`): a rate-limit or ACL rejection is
classified `rejected` and never pollutes error-rate metrics.

## Shared outcome vocabulary

For consumers that classify calls (the audit and telemetry bridges),
`Interceptor\CallOutcome` (`success` / `rejected` / `error`, with
`CallOutcome::fromThrowable()`) is the one shared vocabulary — a rate-limit
or ACL rejection is classified `rejected` and never pollutes error-rate
metrics.

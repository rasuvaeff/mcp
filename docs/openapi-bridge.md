---
title: "OpenAPI bridge"
description: "Bridge an existing OpenAPI/REST API into MCP tools with rasuvaeff/mcp-openapi: allow-lists, dry run, delegated authorization, and output schemas from responses."
---

# OpenAPI bridge

::: tip Yii3 params
`'rasuvaeff/yii3-mcp' => [...]` snippets are Yii3 configuration. Elsewhere pass the same
value as a constructor argument; see [Getting started](/intro/getting-started#_4-add-what-you-need).
:::

If the application already maintains an OpenAPI 3.0.x or 3.1.x document,
allow-listed operations can be bridged as MCP tools with zero duplication:
names come from `operationId` (or `tool_names`), descriptions from
`summary`/`description`, input schemas from parameters/request body, output
schemas from the success response. Calls execute as real HTTP requests
against the API, passing its full middleware stack (validation, rate
limiting, auth) — unlike a hand-written tool that invokes a handler
directly.

```php
'rasuvaeff/yii3-mcp' => [
    'openapi' => [
        // file path OR http(s) URL — e.g. the app's own spec endpoint,
        // always current; fetched with spec_headers, NOT with headers
        'spec_path' => 'https://api.example.com/rest/json-url',
        'base_url' => 'https://api.example.com',
        'operations' => ['getBlogTags', 'getPage'],   // allow-list; empty = nothing
        'tool_names' => ['getBlogTags' => 'blog_tags_list'],
        'headers' => ['Authorization' => 'Bearer ' . getenv('MCP_API_TOKEN')],
        'spec_headers' => [],
        'cache_ttl' => 60,               // PSR-16 URL-spec cache; 0 = fetch every build
        'safe_methods_only' => true,     // read-only bridge: non-GET in the list => build error
        'max_response_bytes' => 4_194_304,
        'opaque_errors' => false,
    ],
],
```

Disabled while `spec_path` is empty; an empty `operations` allow-list
exposes nothing even with a spec configured.

## Credential scopes are separate on purpose

`headers` authenticates operation calls against `base_url`; `spec_headers`
authenticates the spec **fetch** against `spec_path`. When the two live on
different origins, a shared header set would hand the API token to the spec
host. A spec URL embedding credentials (userinfo) is rejected outright — it
would otherwise end up in diagnostics and exception messages.

**Bridged operations execute with the configured upstream credentials — the
upstream API does not automatically inherit the MCP caller's identity or
RBAC decision.** Do not expose user/tenant-scoped operations with a broader
service token than the caller should have.

## Renaming: `tool_names`

`tool_names` only renames what MCP clients see — the allow-list, handler
execution, and delegated-header calls all stay keyed by `operationId`.
[Interceptors](/interceptors), [visibility](/visibility) rules, and any
audit/RBAC bridge must reference the **renamed** name. An `operationId` in
`tool_names` absent from `operations` throws `InvalidArgumentException` at
build time (a likely typo); a rename that is invalid as an MCP tool name or
collides with another tool's name throws `InvalidSpecException`. The
collision check covers attribute tools too — see
[Security: capability name collisions](/security#capability-name-collisions).

Every `GET` operation is advertised with `readOnlyHint: true` automatically.
OpenAPI `tags` propagate into the served tool's `_meta`
(`{"rasuvaeff/yii3-mcp": {"tags": [...]}}`), which the declarative `tag:`
[visibility](/visibility#declarative-tool-visibility) pattern reads
directly.

## Output schema from responses

A bridged tool advertises `outputSchema` when the operation declares a
matching success response: the **lowest concrete 2xx** response with an
`application/json` schema of `type: object` (OpenAPI 3.1's `type: ["object",
"null"]` nullable union is accepted the same way; local `$ref`s resolved;
top-level keywords canonicalized to `type`/`properties`/`required`/
`additionalProperties`/`description`). Array/scalar responses and `2XX`
wildcards are not advertised — a JSON object payload still arrives as
`structuredContent`, just without the upfront contract. Keep the OpenAPI
document honest: a spec that diverges from the API surfaces as client-side
validation errors.

## Per-operation customization

`OperationModifierInterface` is a per-operation hook, applied **after** the
`tool_names` rename — for changing a description, adding annotations, or
renaming further without writing a whole `ServerConfiguratorInterface`.
A further rename it produces is validated and checked for collisions the
same way as a `tool_names` rename.

## Delegated authorization

For delegated authorization, configure **both** `identity_provider` and
`delegated_header_provider`:

- the identity provider returns an immutable `ExecutionIdentity`
  (`subjectId`, `tenantId`, `clientId`);
- the header provider is called on **every** operation call and receives
  only the operation id/method/path plus that identity — never the raw MCP
  shared secret — and exchanges it for headers.

Do not forward the inbound `Authorization` header verbatim. A provider
failure stops the call **before** HTTP (fail-closed). Dynamic headers
override matching static ones, without cross-call reuse. This is also what
partitions the [tool-result cache](/interceptors#result-size-limit-and-caching)
by identity, below the client-id level.

## Dry run

```php
'rasuvaeff/yii3-mcp' => [
    'openapi' => [
        // operationIds that get an extra `dryRun` boolean argument
        'dry_run' => ['createSubscriber'],
    ],
],
```

A dry-run-enabled operation's `inputSchema` gains a `dryRun: boolean`
argument. Calling the tool with `dryRun: true` returns the request that
*would* be sent (`operationId`, `method`, `url`, `body`) as text — never as
`structuredContent`, so it never conflicts with the operation's declared
`outputSchema` — without sending it, and without any upstream credentials
leaving the process (headers are never included in the preview). The flag is
checked twice, fail-closed: an operationId absent from `dry_run` ignores a
`dryRun` argument entirely and always executes for real, even if a client
sends it anyway. On a dry-run-enabled operation a non-boolean `dryRun`
value (`1`, `"true"`) is rejected with an error instead of being executed —
a malformed flag must never turn an intended preview into a real call.

Dry-run is orthogonal to `safe_methods_only`: it does not expose an operation
the safety gate would otherwise reject — a write operation still needs
`safe_methods_only: false` (or omitted) to be exposed at all, dry-run or not.
A dry-run call still passes through the full interceptor chain (session
budget, RBAC/audit, caching, size limit) like any other call — previewing a
write action requires the same permission as actually calling it.

## In-process execution

When `base_url` points at the application itself, every bridged call is a
real HTTP request back into the same process pool: under php-fpm one call
occupies two workers (the MCP request waits for the nested one) and can
starve a small `pm.max_children`, on top of loopback TLS/DNS configuration
and per-call latency. `executor => 'psr15'` removes the network from the
path entirely:

```php
'openapi' => [
    // ...
    'executor' => 'psr15',
    // Psr\Http\Server\RequestHandlerInterface FQCN, resolved through the DI
    // container — the application's own handler (typically the console
    // application's). Required for psr15 mode.
    'handler' => App\RequestHandler::class,
    // optional: OpenApi\ExecutionRequestAttributesInterface FQCN — maps the
    // resolved ExecutionIdentity to request attributes on the nested
    // request, so the stack authenticates the user behind the MCP client
    // the usual way (delegated headers are still sent as headers)
    'request_attributes' => App\ExecutionRequestAttributes::class,
    // optional: OpenApi\InProcessScopeInterface FQCN — enter()/leave() hooks
    // the executor runs around the nested handle()
    'in_process_scope' => App\InProcessScope::class,
],
```

The nested request carries the same method, URI (`base_url` + substituted
path + query), headers (configured defaults, delegated ones, `Accept`,
`Content-Type`) and JSON body the HTTP executor would send; the response
goes through the same incremental size cap, error mapping and
`opaque_errors` handling. `base_url` keeps shaping the request URI and the
dry-run preview — nothing leaves the process, and the PSR-18 client is only
touched by a URL `spec_path` fetch.

Two contracts are the application's to honour:

- **`handler` is responsible for its own re-entrancy.** The outer MCP
  request's scoped state — current route, current user, a URI already set
  on the shared request — is still in place when the nested request runs;
  re-entering the Yii3 stack naively fails with `Can not set URI since it
  was already set` or, far worse, serves the nested call under the outer
  request's identity. Reset what your stack globalizes in
  `InProcessScopeInterface::enter()` and restore it in `leave()`; the
  executor calls `leave()` in a `finally`, so it runs even when the nested
  call throws, and a stateless scope implementation may be shared.
- **delegated mode keeps working**: the delegated header provider's output
  arrives as headers, and `request_attributes` (when configured) adds the
  identity as attributes — both derive from the SAME per-call identity
  resolution.

Misconfiguration fails the server build: `psr15` without `handler`, any of
the three psr15-only keys in `http` mode, or an unsupported `executor`
value. `mcp:doctor` reflects the mode — it requires the PSR-17
server-request factory and the configured handler instead of the PSR-18
client for operation execution.

## Building the bridge by hand

The bridge is a package feature, not an application one: `OpenApiBridgeFactory`
builds a ready configurator from what any consumer already has, so nothing
`@internal` has to be reached for.

```php
use Rasuvaeff\Mcp\OpenApi\OpenApiBridgeFactory;

$server = (new McpServerFactory($container, $sessionStore))->create([], [
    OpenApiBridgeFactory::create(
        spec: __DIR__ . '/openapi.json',   // path, http(s) URL, or a decoded document
        baseUrl: 'https://api.example.com',
        httpClient: $httpClient,           // PSR-18
        requestFactory: $psr17,            // PSR-17
        streamFactory: $psr17,
        operations: ['getBlogTags'],
        headers: ['Authorization' => 'Bearer ' . $token],
        safeMethodsOnly: true,
        toolNames: ['getBlogTags' => 'blog_tags_list'],
    ),
]);
```

A URL spec is fetched with `specHeaders` (its own credential scope) and cached
through `specCache` when `specCacheTtl` is above zero. Every other option the
`openapi` params carry — `modifier`, `dryRunOperations`, `identityProvider`,
`delegatedHeaderProvider`, `maxResponseBytes`, `opaqueErrors`,
`multiSegmentPathParams`, and the in-process quartet `inProcessHandler`,
`serverRequestFactory`, `requestAttributes`, `inProcessScope` — is a named
argument here. The config-plugin path calls this same factory, so both
assemble the bridge identically.

## A failing bridged call names the tool, not the operationId

Everything a caller reads — HTTP failures, the response-cap refusal and the
argument guards — names the tool as served (after `tool_names` and the
operation modifier). Under a rename those two strings differ, and quoting the
operationId back at an agent hands it an identifier that appears in no tool
list it has.

The messages reach the client because `BridgedToolHandler` rethrows
`OperationFailedException` and `Exception\InvalidToolArgumentException` as the
SDK's `ToolCallException`: `CallToolHandler` turns only that type into a
tool-error envelope carrying the message, and replaces every other exception
with a generic internal error.

Those two types are the whole allow-list, and the narrowness is the point. The
PSR-17/PSR-18 stack underneath raises plain `InvalidArgumentException`s of its
own — an unparseable request URI, a delegated header name that is not RFC 7230
compatible — whose messages quote the base URL or the offending header. Those
describe the deployment rather than the call, so they stay behind the generic
internal error instead of being relabelled to the agent as a problem with the
arguments it sent.

The **dry-run preview payload** still reports `operationId`. It documents the
upstream request that would be sent, and the operationId is how a caller looks
the operation up in the OpenAPI document.

## Path arguments are validated, not just encoded

A path argument is rejected at call time when it is empty, `.`, or contains
`..`, `/`, or `\`. `rawurlencode()` keeps dots verbatim and encodes `/` as
`%2F`, which upstreams that decode before normalizing the path (Apache with
`AllowEncodedSlashes`, some proxies and servlet containers) hand back as a
real separator — so a value like `../..` could climb out of the
allow-listed route using the bridge's credentials; an empty value is the
same escape one level up (`/users/` is typically the collection route, not
the allow-listed item route). Single dots are fine (`v1.2` is a valid
slug) — a value that genuinely needs `..` cannot be bridged as a path
argument.

### Array-valued query parameters

List/search operations take array filters (`regions`, `platforms`, `niches`).
A `type: array` parameter in `in: query` is bridged as an array argument: the
tool's input schema keeps `items`, `enum`, `minItems` and `maxItems` exactly as
the spec declares them, and the executor validates them again before sending
(a direct caller cannot smuggle an oversized or non-scalar array past the
SDK's schema check). Items must be scalars — objects and nested arrays fail the
server build, as do arrays in a path segment; `style` other than `form` and
`allowReserved: true` fail it too.

The wire format is the application's contract, so it is configurable. With the
default (`array_query_style` empty) each parameter follows its own declared
`explode`: `explode: false` is written `name=a,b`, anything else repeats the
key (`name=a&name=b`, the OpenAPI default). PHP applications that read arrays
as `name[]=a&name[]=b` set `array_query_style` to `brackets` — a plain repeated
key is read back by PHP's `parse_str` as the LAST value only. `array_query_params`
overrides the style for one parameter of one operation; an unknown style fails
the server build. An empty array sends nothing (an empty filter constrains
nothing); `minItems` rejects it before the call. The same options exist on
`OpenApiBridgeFactory::create()` as `arrayQueryStyle` and `arrayQueryParams`.

A parameter listed in `multi_segment_path_params` may carry that many
`"/"`-separated segments — for an upstream that identifies a resource by a
nested path and accepts it percent-encoded (GitLab takes `group/project` as
`group%2Fproject`). The opt-in buys the separator and nothing else: `..`, a
backslash, an empty value and a bare `.` stay rejected, every segment must
match `[A-Za-z0-9_][A-Za-z0-9_.-]*`, and the limit must be an integer from 1
to 20 — a string, float or out-of-range value fails at server build time. Opt a parameter in deliberately — the value comes from the MCP client,
and a multi-segment value no longer keeps the request on the allow-listed
route (`1/repository/archive` under `/projects/{id}` reaches an operation the
allow-list never exposed). The segment cap is what bounds that, so keep it as
low as the upstream's real nesting depth. The base URL must not
embed credentials (userinfo) or carry a query string/fragment — dry-run
previews return the full URL to the caller, so the base URL is never allowed
to be a credential carrier. Fixed upstream headers belong in `headers`/
`HttpOperationExecutor::defaultHeaders`. **Bridged operations execute with the
configured upstream credentials. The upstream API does not automatically
inherit the MCP caller identity or RBAC decision. Do not expose user/tenant-
scoped operations with a broader service token.**

For delegated authorization configure both `identity_provider` and
`delegated_header_provider`. The first returns an immutable
`ExecutionIdentity`; the second exchanges it for headers on every operation
call and receives only operation id/method/path plus that identity, never the
raw MCP shared secret. Do not forward the inbound `Authorization` header
verbatim. Provider failures stop the call before HTTP (fail-closed), and
dynamic headers override matching static headers without cross-call reuse.

Duplicate `operationId` values also fail while indexing the document.
Tool arguments are keyed by name, so an operation with a
path and a query parameter sharing one name — or a parameter named `body`
alongside a request body — cannot be bridged and throws
`InvalidSpecException` at build time.

Resource bounds are enforced **before** allocation, not after: the upstream
response body is read incrementally and the call fails the moment it crosses
`max_response_bytes` (an advertised `Content-Length` over the cap is rejected
without reading at all); JSON decoding is depth-capped; the OpenAPI document
itself is size-bounded (10 MiB) for URL and file sources alike, and `$ref`
inlining runs under an explicit depth + node budget, so a hostile or
degenerate remote spec cannot make indexing recurse or allocate without
bound. Upstream error bodies are excerpted (bounded, UTF-8-safe) into the
tool error — or suppressed entirely with `opaque_errors` when upstream error
details are not the MCP caller's to see.

An `operationId` that cannot serve as an MCP tool name (space, unicode,
over 64 characters — `^[A-Za-z0-9._/-]{1,64}$`) throws `InvalidSpecException`
when the operation is selected; `mcp/sdk` itself only logs a warning and
would otherwise register the tool anyway, surfacing only as an opaque
`tools/list` rejection on the client. A `null` argument for a path or query
parameter is treated the same as an omitted one (skipped, not sent as an
empty value) — matching OpenAPI 3.1's nullable union notation
(`{"type": ["string", "null"]}`) on scalar parameter schemas, which the
bridge accepts alongside the plain 3.0 type string.

### Multi-segment path arguments are opt-in, per parameter

Some upstreams identify a resource by a nested path and accept it
percent-encoded — GitLab takes `group/project` as `group%2Fproject`. Listing
the parameter in `openapi.multi_segment_path_params` allows that many
`"/"`-separated segments for that parameter only:

```php
'openapi' => [
    'multi_segment_path_params' => ['id' => 3],
],
```

Empty by default, so every path argument stays single-segment unless an
operator says otherwise. The opt-in buys the separator and nothing else:

| Value | `['id' => 3]` | Why |
|---|---|---|
| `dev/keppio` | accepted | two well-formed segments |
| `122` | accepted | single segment, unchanged |
| `a..b`, `x/..`, `..` | rejected | `..` anywhere |
| `a\b` | rejected | backslash anywhere |
| `dev//keppio`, `/x`, `x/` | rejected | empty segment |
| `dev/./x` | rejected | `.` segment |
| `dev/kep pio`, `dev/-x` | rejected | segment charset |
| `1/repository/archive/x` | rejected | over the segment limit |

The limit must be an integer from 1 to 20 — a string (`'3'`), a float or an
out-of-range value fails at server build time rather than being coerced.
Two things are deliberately NOT configurable: the per-segment charset
(`[A-Za-z0-9_][A-Za-z0-9_.-]*`) and that ceiling. **A multi-segment value no longer pins the request to the
allow-listed route** — under `GET /projects/{id}`, a value of
`1/repository/archive` reaches an operation `operations` never exposed, with
the bridge's credentials. The segment cap is the only thing that bounds that,
and the value comes from the MCP client, so set the limit to the upstream's
real nesting depth and no higher.

The **base URL** must not embed credentials (userinfo) or carry a query
string/fragment — dry-run previews return the full URL to the caller, so
the base URL can never be a credential carrier.

## Resource bounds, enforced before allocation

- The upstream response body is read **incrementally**; the call fails the
  moment it crosses `max_response_bytes` — an advertised `Content-Length`
  already over the cap is rejected without reading at all.
- JSON decoding is depth-capped.
- The OpenAPI document itself is size-bounded (10 MiB) for URL and file
  sources alike, and `$ref` inlining runs under an explicit depth + node
  budget — a hostile or degenerate remote spec cannot make indexing recurse
  or allocate without bound.
- Upstream error bodies are excerpted (bounded, UTF-8-safe) into the tool
  error, or suppressed entirely with `opaque_errors` when upstream error
  details are not the MCP caller's to see.

## Spec parsing constraints

Local `#/components/...` `$ref`s resolve inline; a recursive schema keeps
its reference with a `$defs` entry; external (URL/file) `$ref`s are refused. URL parameters are limited to scalar `string`/`integer`/`number`/
`boolean` schemas with OpenAPI's defaults (`simple` path, `form` query) —
header/cookie parameters, external or non-scalar parameter schemas, custom
serialization, non-default `explode`, and `allowReserved=true` throw
`InvalidSpecException` when the operation is selected. Duplicate
`operationId` values fail while indexing. An operation with a path and a
query parameter sharing one name — or a parameter named `body` alongside a
request body — cannot be bridged. An `operationId` unusable as an MCP tool
name (space, unicode, over 64 characters —
`^[A-Za-z0-9._/-]{1,64}$`) throws `InvalidSpecException` when selected,
rather than surfacing only as an opaque `tools/list` rejection on the
client. A `null` path/query argument is treated as omitted, matching
OpenAPI 3.1's nullable union notation on scalar parameter schemas.

## Caching the URL spec

A URL spec can be cached via PSR-16 (`cache_ttl`); the cache stores the raw
document, and allow-listing/validation run on **every** server build
regardless. A cache failure falls back to HTTP; an HTTP or spec failure
remains fail-closed. A removed operation can remain callable for up to the
TTL — use a local file or a short TTL for security-sensitive specs.

For custom scenarios, use the pieces directly: `SpecIndex` +
`HttpOperationExecutor` + `OpenApiServerConfigurator` — a
`ServerConfiguratorInterface`, the generic extension point accepted by
`McpServerFactory::create(tools, configurators)`.

See [Cookbook: bridging an existing REST API](/cookbook/bridging-existing-api)
for a worked example.

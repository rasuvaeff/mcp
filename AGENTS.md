# AGENTS.md — mcp

Guidance for AI agents working on this package. Read before changing code.

## What this is

Framework-neutral MCP server runtime over the official `mcp/sdk` (namespace
`Rasuvaeff\Mcp`). `McpServerFactory` reads the SDK's capability attributes off
the listed classes and registers container-resolved handlers; `McpAction`
(PSR-15) serves Streamable HTTP, `McpServeCommand` serves stdio. Consumers:
`rasuvaeff/yii3-mcp` and its bridges.

Public API: every `@api` type, listed in `README.md` → Components. `rasuvaeff/yii3-mcp`
also uses these `@internal` types, so a change to them is a cross-package change:
`McpServerAssembler`, `McpServerComponents`, `CachePolicyParams`,
`OAuth\OAuthComponentFactory`, `Session\SessionDirectory`, `Apps\AppParamParser`,
`Utf8`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Never invent protocol structures and never weaken fail-closed defaults.**
   An empty secret rejects every request; hidden equals missing.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make test-coverage`, `make mutation`, `make release-check`.

## Invariants & gotchas

- **No framework and no OpenAPI here.** No `yiisoft/*`, `Illuminate\*`, Symfony
  beyond `symfony/console` and `symfony/uid`, no `extra.config-plugin` (it would
  duplicate DI keys of `rasuvaeff/yii3-mcp`).
- **Wire and storage literals keep the `yii3-mcp` name on purpose** (session key
  and request attribute `rasuvaeff.yii3-mcp.client-id`, `yii3-mcp.*` cache
  prefixes, `_meta` key `rasuvaeff/yii3-mcp`): renaming the session key answers
  404 to every live session. `DeclarativeToolVisibility` reads the `_meta` key
  the OpenAPI bridge writes.
- Params keys mentioned below belong to `rasuvaeff/yii3-mcp`.
- **`result_json` defaults to `'compact'`, which since SDK 0.10 IS the SDK's
  own encoding; `'pretty'` (and any configured decorator) makes
  `InterceptingReferenceHandler::handleTool()` build the result itself, AFTER
  the interceptor chain and BEFORE the SDK's `CallToolHandler`** — the SDK
  skips both its formatter and `extractStructuredContent()` for a reference
  handler that already returns a `CallToolResult`, which is the entire
  mechanism. Compact WITHOUT decorators returns the raw result and lets the
  SDK format — never build it in-package unconditionally. The placement is
  load-bearing: interceptors (RBAC/audit), the cache and the size limit must
  keep seeing the RAW handler result they were written against.
  `ToolResultTextFormatter` (@internal, was `CompactToolResultFormatter`)
  must stay a branch-for-branch mirror of the SDK's
  `CallToolHandler::handle()` (a null or scalar structured value is sent as
  its JSON text, SEP-2106) and `ToolResultFormatter::format()` TEXT branches
  (Content pass-through, mixed-array per-item formatting, scalar/null/bool
  sentinels, `JSON_INVALID_UTF8_SUBSTITUTE`), differing only in
  `JSON_PRETTY_PRINT` for `pretty`; re-diff both on every SDK pin bump.
  `McpServerFactory` installs the handler when `result_json` is `pretty`.
  `structuredContent` is NOT mirrored but delegated to
  `ToolReference::extractStructuredContent($result, $protocolVersion)`: since
  SDK 0.8 the rule depends on the revision (a list is structured content only
  from 2026-07-28 on; before it strict clients reject the whole call). The 0.7
  copy of those rules drifted the moment 0.8 changed them —
  `structuredContentAgreesWithTheSdk` compares the two per revision. Since
  0.10 the SDK returns `StructuredContent::Absent` for "none" and
  `CallToolResult` sends an explicit `null`: anything that rebuilds a result
  (decorators, tests) passes `hasStructuredContent() ? structuredContent :
  StructuredContent::Absent`. A ready
  `InputRequiredResult` (multi round-trip ask) passes through unformatted.
  Its property test compares the text with `json_encode(structuredContent)` at
  the ENCODING level on purpose: PHP decodes JSON numbers without a decimal
  point as ints, so `0.0` does not survive `json_decode(json_encode(0.0))` as a
  float — true for the SDK's own path alike (PHP 8.5 encodes `0.0` as `0`).
- **Interceptor chain order is fixed and load-bearing:** tool-call budget
  (outermost) → configured `interceptors` → `CachingToolCallInterceptor` →
  `ResponseSizeLimitInterceptor` (innermost). Caching must sit BETWEEN user
  interceptors and the size limit, never around user interceptors — RBAC/
  audit must run on every call including a cache hit, or caching becomes an
  ACL bypass. Never reorder without preserving this.
- **Result decorators run AFTER the chain and AFTER formatting, and never
  change anything by their mere presence.** `InterceptingReferenceHandler`
  formats first (`ToolResultTextFormatter` in the configured encoding — a
  copy of `CallToolHandler`'s branch), then hands the `CallToolResult` to each
  decorator in order. Compact mode WITHOUT decorators still returns the raw
  result and lets the SDK format — never build it in-package
  unconditionally. An
  `InputRequiredResult` returns before formatting and is never decorated.
  `McpServerFactory::create()` installs the handler when decorators alone are
  configured (otherwise they would be silently ignored). The regression guard
  is `ToolResultDecoratorTest::anIdentityDecoratorChangesNothing` — every
  result shape × both eras × both `result_json` modes compared whole against
  a server without decorators; re-run it on every SDK pin bump, it is what
  proves the in-package copy still matches the SDK.
- **`ToolCallBudgetInterceptor` counts in two places, by era, and the
  stateless one is the reason the class exists.** Handshake era: in the
  session (initialize → TTL). Stateless 2026-07-28 era: the SDK hands every
  request a THROWAWAY session (`StatelessProtocol` builds
  `new Session(new InMemorySessionStore())`), so a session counter starts at
  zero on every call and the guard silently never fires — that was 3.x's
  `SessionBudgetInterceptor` under SDK 0.8. There the budget is per client id
  in PSR-16 over a FIXED window: `floor(now / window)` is part of the key and
  the TTL runs to the end of the window. Never key on the client alone with
  `ttl = window`: PSR-16 `set()` re-arms the TTL on every write, so a client
  that keeps calling would never be reset. A null client id is one shared
  bucket (stricter, on purpose). Missing cache or a throwing backend rejects
  the call (fail-closed, like `RateLimitInterceptor`). The resolver resolves
  `CacheInterface` ONLY when the stateless era is on (3.0.1 was the same class
  of bug with PSR-18), and `session.budget` (the 3.x key) fails the build —
  the package's own params must therefore never ship that key. Era detection
  lives in `Interceptor\RequestEra` only.
- **A multi round-trip call is several `tools/call`s, and each round re-enters
  the whole chain.** On the stateless era `elicit()` suspends the handler
  Fiber; the SDK abandons it, answers `input_required`, and on the client's
  retry runs the handler from the top (`ElicitationReplay`). Consequences kept
  on purpose: the budget counts every round (answers are unsigned — a budget
  skipping retries is bypassed by attaching made-up `inputResponses`);
  `CachingToolCallInterceptor` neither stores an `InputRequiredResult` (it
  would answer the retry carrying its own answer, forever) nor reads/writes
  on a retry (`RequestEra::isRetry()` — the arguments do not show the
  answers). More than one ask needs `request_state.key`.
  `tests/MultiRoundTripTest` drives it end to end through the tester.
- **`cache_policy` `public` is refused on per-caller answers.** With any
  visibility filter, `tools/list`/`prompts/list`/`resources/list`/
  `resources/templates/list`/`resources/read` depend on the caller; a
  `public` SEP-2549 hint would let a shared proxy serve one caller's view to
  another, so `McpServerFactory::create()` throws. `CachePolicyParams` fails
  config load on a method that carries no hint (a typo would be a hint never
  sent) — do not loosen either into a warning.
- **Neither budget counter is concurrency-safe** — a plain `get()`/`set()`
  read-modify-write, no compare-and-swap, because neither the SDK's
  `SessionInterface` nor PSR-16 exposes one. N concurrent requests can
  overrun the budget by up to N-1. Accepted: it's an anti-loop guard
  against a runaway agent, not a hard quota — do not "fix" this with a
  backend-specific lock (e.g. flock on `FileSessionStore`'s file), which
  would break for any other `SessionStoreInterface` a consumer binds. A real
  per-client quota belongs in an application-level rate limiter with a
  proper atomic store, not here.
- **`CachingToolCallInterceptor`'s cache key MUST include everything the
  result's identity depends on: the mandatory application namespace, the
  resolved client id AND, when `openapi.identity_provider` is configured,
  the resolved `ExecutionIdentity`.** A cache shared across distinct clients
  leaks one client's result to another — this is not a configurable
  trade-off; with delegated upstream credentials the identity can be
  finer-grained than the client id (many end users behind one MCP client),
  so the client id alone is NOT enough. The key material is a typed JSON
  structure carrying a format version — an ABSENT client id encodes as
  `null`, never as a sentinel string a real client id (`anonymous`) could
  collide with; the namespace (default: `server_name`) isolates
  applications sharing one cache backend. An identity provider failure
  fails CLOSED for
  cached tools (a cache outage fails open — availability; not knowing whose
  result it is — never). Cached values are wrapped (`['v' => $result]`)
  specifically to distinguish a genuine `null` tool result from a cache
  miss (PSR-16's `get()` returns `null` on both). Keys must stay within
  PSR-16's guaranteed 64 characters — the sha256 digest is truncated to 45
  hex chars for that reason; a longer key makes a strict PSR-16
  implementation throw on every call, silently disabling caching through
  the fail-open catch.
- **Never cut a string with `substr()` on a path that reaches the client.**
  Everything this package truncates (an over-limit tool result in
  `ResponseSizeLimitInterceptor`, the upstream error-body excerpt in
  `HttpOperationExecutor`) ends up inside a JSON-RPC response the SDK encodes
  with `JSON_THROW_ON_ERROR`; a half-written multi-byte character makes that
  encode fail, and `Mcp\Server\Protocol::queueOutgoing()` then **drops the
  response silently** (only the sessionless stdio path gets an
  `INTERNAL_ERROR` fallback, so the failure is invisible on Streamable HTTP).
  Use `Utf8::cut()` (`@internal`, byte-wise on purpose — `mb_strcut` would
  pull `ext-mbstring` into the package requirements and into every CI job's
  extension list for two call sites). `cut()` only avoids SPLITTING a
  character; it does not repair input that was never UTF-8, so a foreign body
  (a legacy-encoded upstream error page) is validated separately with
  `preg_match('//u', …)` and replaced by a byte-count placeholder. Tests assert
  with PCRE, not `mb_check_encoding`. `ext-mbstring` IS in the CI extension
  lists now (property-testing requires it), so that no longer fails outright —
  which is exactly why the rule has to be stated rather than enforced by
  accident: production code and its tests must not imply a requirement this
  package deliberately does not declare.
- **A handler that emits a notification cannot be asserted through
  `Testing\McpTester`.** The moment anything suspends the Fiber to send
  (progress, client logging, `notifications/resources/updated`), the SDK's
  `StreamableHttpTransport` switches to streaming and `echo`es the SSE frames
  straight to output with `ob_flush()`; the PSR-7 response body stays empty and
  even an `ob_start()` around the call captures nothing. Test such handlers by
  running them inside `new Fiber(...)` and asserting the suspend value — that is
  exactly what the transport would flush. Do not "fix" the tester by parsing
  output buffers.
- **A `text/event-stream` response leaves `McpAction` wrapped in
  `OutputBufferReleasingStream` (@internal), and the wrapper must stay.** The
  SDK's stateless `StatelessResponder::sse()` wrote frames with `flush()` but
  no `ob_flush()` up to 0.8.1; the `output_buffering` buffer PHP opens before
  the application (4096 by default) and `yiisoft/psr-emitter` (ends only buffers
  above the level it recorded) would hold a whole `subscriptions/listen`
  stream until it closes (#74). The wrapper ends buffers right before the
  SDK's callback writes; it never reads the stream itself. SDK 0.10 carries the
  upstream fix (modelcontextprotocol/php-sdk#520, `@ob_flush()` in `sse()`),
  but `ob_flush()` only pushes the TOP buffer into the one below it — with
  nested buffers (PHP's own `output_buffering` plus an application or emitter
  buffer) the frame still waits. The wrapper stays until a runtime test under
  PHP-FPM shows the SDK alone delivers frame by frame.
- **`Resource\ResourceUpdateNotifier` has two delivery paths, one per era,
  and the SDK joins them nowhere.** The notification bus (SDK 0.8) feeds ONLY
  stateless `subscriptions/listen` streams; handshake sessions still get an
  update only through their own `ClientGateway`, i.e. only the CALLING session,
  after `SubscriptionManagerInterface::isSubscribed()` (so nothing unsolicited
  reaches the wire). `notify($uri, ?$context)` publishes to the bus whenever
  one is bound — without a context too (queue worker) — and skips the gateway
  for a stateless context (its session is a throwaway). The bus is bound in
  `rasuvaeff/yii3-mcp` `config/di.php` only when `notifications.bus` is set, and BOTH
  `McpServerFactory` and the notifier take it as an optional dependency: a
  memory bus split into two instances would publish where nobody reads. `rasuvaeff/yii3-mcp` `config/di.php` binds the manager so the SDK's
  subscribe handler and the notifier read the same state; a consumer swapping the
  binding must get both sides, which is why `McpServerFactory` forwards it to
  `Builder::setResourceSubscriptionManager()` instead of letting them diverge.
- **`*ListChanged` capabilities are deliberately NOT enabled.** Passing an
  event dispatcher to the SDK flips `toolsListChanged`/`promptsListChanged`/
  `resourcesListChanged` to `true`, but the SDK ships no listener that turns the
  dispatched `*ListChangedEvent`s into `notifications/*/list_changed` messages,
  and registry mutations happen at BUILD time, before any client is connected.
  Enabling it would advertise a capability the server never honours — the exact
  mistake `resources/subscribe` used to make here. Revisit only if the SDK grows
  the listener.
- **Two eras on one endpoint, and `initialize` negotiates (since SDK 0.8).**
  Handshake era: the client's revision when supported, else the newest
  handshake one (`MessageInterface::PROTOCOL_VERSION`); `protocol_version`
  pins one HANDSHAKE revision (`rasuvaeff/yii3-mcp` `config/di.php` rejects 2026-07-28 — it has no
  initialize). Stateless era (`modern_era`, default on): the SDK classifies
  each request by `params._meta`; there is no session, the SDK builds a
  throwaway one per request. Do not hardcode a revision anywhere —
  `Testing\McpTester` defaults to that constant (they disagreed once: the
  tester claimed 2025-06-18 while the server answered 2025-11-25) and takes a
  revision for era-specific tests. Anything session-bound must be tested on
  both eras; that is how the 0.8 bump found the budget, ownership and
  `getClientInfo()` holes.
- **`completion/complete` does not go through the reference handler, so
  visibility has to be applied to it separately.** The SDK's
  `CompletionCompleteHandler` reads the registry directly; before
  `Visibility\FilteredCompletionCompleteHandler` existed, a prompt hidden by
  `PromptVisibilityInterface` still returned completions for its arguments —
  verified end-to-end, not inferred — leaking the values AND the capability's
  existence (hidden answered, missing errored). The decorator wraps the SDK
  handler rather than reimplementing provider resolution, and phrases its
  refusal with the SDK's own `PromptNotFoundException` /
  `ResourceNotFoundException` message AND the SDK's code (`-32602` since 0.8;
  answering the old `-32002` kept the text identical and still made the code
  an existence oracle) so a hidden ref stays byte-identical to a missing one.
  `tools/call` has the same problem one step earlier:
  `Visibility\FilteredCallToolHandler` answers a hidden tool before the SDK
  validates arguments against its schema (an invalid call would otherwise
  reveal it) — the reference-handler check stays as defence in depth.
  `tests/Visibility/HiddenIsMissingTest` compares whole error envelopes for
  every capability method; keep new methods in it. A ref that does not
  resolve at all is passed through to the inner handler — never phrase "unknown capability" in two places. If the SDK
  ever routes completion through the reference handler, drop the decorator
  instead of stacking two checks.
- **`tag:` is a reserved prefix in `DeclarativeToolVisibility` patterns.**
  A pattern starting with `tag:` matches the tool's tags (`_meta['rasuvaeff/yii3-mcp']['tags']`,
  populated by the OpenAPI bridge from OpenAPI `tags`) instead of its name;
  the prefix is stripped once during `compile()`, so a tag pattern must
  never ALSO get compiled as a name pattern (that bug already happened once —
  keep the early `continue` after appending the tag-kind entry). Tool names
  containing a literal `tag:` are indistinguishable from the prefix — none
  exist in this ecosystem, and this is an accepted, documented trade-off.
  Note the trust boundary: tags come from the OpenAPI document, so over a URL
  spec a `tag:`-based DENY rule can be disarmed by the upstream dropping the
  tag (exposure stays bounded by the `operations` allow-list). Documented in
  both READMEs — deny by name over a remote spec, keep `tag:` for allow-lists
  and local files.
- **Capability identities must be unique across the WHOLE server, and the
  SDK will not tell you otherwise.** `Registry::registerTool()` is
  last-write-wins with no duplicate check, and `Builder::build()` runs its
  explicit loader (`Builder::add()`, used by configurators) BEFORE the
  reflected one (`Builder::addTool()`, used for `#[McpTool]` methods) — so
  on a collision the attribute tool wins and the **bridged tool silently
  disappears** from `tools/list`, which was verified empirically, not
  inferred. Two layers of defence, keep BOTH:
  1. `McpServerFactory` always installs `GuardedRegistry` (@internal, wraps
     the SDK registry via `Builder::setRegistry()`) — the single point every
     registration path converges on; ANY live duplicate (tools, resources,
     templates, prompts) throws `Exception\DuplicateCapabilityException` at
     build time. Re-register after an explicit unregister stays allowed.
     Side effect: the registry is always eagerly loaded — intended.
  2. The reserved-names handshake gives BETTER error messages earlier:
     `McpServerFactory` collects the attribute tools' names (mirroring the
     SDK's own rule: the attribute's `name`, else the method name, else the
     class short name for `__invoke`) and hands them to every configurator
     implementing `ReservedToolNamesAwareInterface` before `configure()`;
     `OpenApiServerConfigurator` (in `rasuvaeff/yii3-mcp`) seeds its `$usedNames` map with them so a
     `tool_names`/modifier rename onto a taken name fails fast. If the SDK's
     naming rule drifts on a pin bump,
     `reservedNamesFollowTheSdkDefaultNamingRule` is the test that catches it.
- **MCP Apps: `_meta.ui` means two different things at two levels, and the
  wrong one is silently ignored.** The resource DESCRIPTOR (`resources/list`)
  carries only the bare marker (`McpApps::resourceMarker()`, an empty
  `\stdClass`) — that is what flags the resource as an app. The resource
  CONTENT (`resources/read`) carries `UiResourceContentMeta` (CSP,
  permissions, domain, prefersBorder) — the sandbox contract. A policy placed
  on the descriptor does nothing; a marker on the content tells the host
  nothing. `AppResourceHandler` therefore returns a `TextResourceContents`,
  not a string and not a `ReadResourceResult`: the SDK's resource formatter
  only carries `_meta` through on a ready `ResourceContents`, and it has no
  branch for `ReadResourceResult` at all (it throws on the unhandled type).
  `McpAppsConfigurator` is also the SINGLE enabler of the extension —
  `Builder::enableExtension()` throws `LogicException` on a duplicate id, so
  an application configurator that also enables `McpApps` while
  `apps.enable` is on fails the build. That is the intended fail-fast.
- **`Apps\AppParamParser` must never call the SDK's `UiResourceCsp::fromArray()`
  or `UiResourcePermissions::fromArray()`.** Both would misread this package's
  params format SILENTLY: permissions treat a PRESENT key as requested
  (`isset()`), so `'camera' => false` would switch the camera ON, and CSP
  reads camelCase (`connectDomains`) while params are snake_case
  (`connect_domains`) like every other option here — every field would come
  out `null`, no CSP would be emitted, and the operator would believe a domain
  was allow-listed while the host applied its own restrictive default. Map
  explicitly into the constructors; `tests/Apps/AppParamParserTest.php` is the
  regression guard. CSP domains themselves are passed through verbatim — the
  host enforces the policy and `definitions` is application-owned config, not
  client input.
- **`mcp/sdk` is pinned `~0.10.0` (tilde, not caret).** There is no separately
  maintained 4.x line. The 0.8 → 0.10 bump (5.0) moved every SDK class
  (`Mcp\Server\Server`, `Mcp\Server\Capability\*`, `Mcp\Server\Exception\*`,
  `Mcp\Schema\RequestMeta`), added `SessionInterface::isUnreadable()` and
  `RegistryInterface::publishesListChanges()` (`GuardedRegistry` forwards it),
  made a missing `Host` a 403, bumped `MessageInterface::PROTOCOL_VERSION` to
  2026-07-28 (the tester uses `ProtocolVersion::latestHandshake()`), refuses
  `tools/call` arguments that are not a JSON object, keeps nested JSON
  objects as `\stdClass` (`ArgumentMasker` and the cache key walk them),
  encodes results compactly and suspends with `NotificationSuspension`
  objects. The SDK is experimental
  until 1.0; minors are breaking. Bumping the pin is a deliberate act: re-run
  the full test suite (it exercises real SDK behavior end-to-end) and expect
  API drift. After SDK 1.0 → `^1.0` and a major of this package. The 0.6.0 →
  0.7.0 bump was verified empirically (full build + mutation + bc-check, not
  just a changelog read) before merging — every class this package depends on
  (`Tool`, `ToolAnnotations`, `Registry`, `NameValidator`) was byte-for-byte
  unchanged; do the same verification on any future bump, don't assume a minor
  is safe from the changelog alone.
- **Empty JSON Schema `properties` must serialize as `{}`.** A tool definition
  built here passes `\stdClass` (or leaves the SDK to normalize the empty array):
  clients reject `"properties": []` and with it the whole `tools/list`.
- **Session store default must be FPM-safe AND owner-only.** MCP Streamable
  HTTP sessions span requests (`initialize` → `Mcp-Session-Id` → subsequent
  calls); the SDK's `InMemorySessionStore` default silently breaks under
  PHP-FPM, and its `FileSessionStore` creates a `0775` directory with
  umask-mode files — readable by other OS users. `rasuvaeff/yii3-mcp` `config/di.php` binds
  `Session\PrivateFileSessionStore` (0700 dir, 0600 files) with an
  application-specific default directory (`Session\SessionDirectory`,
  derived from `server_name`); do not "simplify" either away.
- **Sessions are bound to the client that created them; the binding is
  immutable and enforced in THREE places — keep all.** The session UUID leaks
  into proxy/client logs via the HTTP header. (0) Since SDK 0.10 the SDK binds
  a handshake-era session to its principal: `McpAction` hands it the resolved
  client id as `StreamableHttpTransport::PRINCIPAL_ATTRIBUTE` (unless a front
  middleware — OAuth — already set one); it is the only layer left when no
  session store is passed to `McpAction`
  (`theSdkRefusesAForeignClientEvenWithoutTheOwnGuard`). (1) `McpAction` stamps
  `InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY` as the owner right
  AFTER the initialize response (never first-call binding — whoever replays
  a fresh session id first would claim it) and answers the SDK's own 404
  shape to a POST/DELETE presenting a foreign or ownerless session when a
  client id is resolved. (2) `InterceptingReferenceHandler::clientId()`
  reads identity FROM THE SESSION first and throws
  `Exception\SessionOwnershipException` on an owner/holder mismatch — this
  runs BEFORE visibility, so a foreign session is never consulted for what
  the caller may see, and it keeps identity correct under Fiber-interleaved
  runtimes where the process-local holder cannot be trusted.
- **`McpServerFactory` reads attributes itself** (reflection over public
  methods) because the SDK's attribute discovery is file-scan based
  (`setDiscovery`), which doesn't fit DI-listed classes. Registration goes
  through `Builder::addTool()/addResource()` with `[class, method]` handlers;
  the SDK's `ReflectedElementLoader` then generates input schemas from
  signatures/DocBlocks and `ReferenceHandler` resolves instances via the
  container. Keep attribute semantics identical to the SDK's own `Discoverer`.
- A configured tool class with no capability attributes throws
  `InvalidToolClassException` at server build time — fail-fast, never a
  silently empty server.
- The SDK requires `ext-fileinfo` and ships the `php-http/discovery` composer
  plugin (set to `false` in `allow-plugins` — we pass PSR-17 factories
  explicitly everywhere, no runtime discovery on our paths). CI extensions
  include `fileinfo` in every job.
- **The client id travels with the request, never in static state.**
  `Identity\RequestIdentity` (@internal) puts it into the SDK `AccessToken` that
  the transport hands to the reference handler (through the session, one request
  only, never persisted) and to the request-state binder. The claim value is an
  OBJECT so no JWT can forge it. `ClientBoundRequestStateBinder` must give the
  inner binder the ORIGINAL token: a token without a subject makes the SDK bind
  to a random value and no state would ever verify.
  `InterceptingReferenceHandler` puts the original token back into the session
  before interceptors or the handler run and is therefore ALWAYS installed:
  `getAccessToken()` must stay `null` without OAuth. The session owner still
  wins; a disagreement fails closed. `InterleavedIdentityTest` is the guard.
- **OAuth: a token never becomes an anonymous request.**
  `OAuth\BearerTokenMiddleware` puts the SDK's `AuthorizationMiddleware` first
  (validation, `WWW-Authenticate` challenges, scopes — never re-implement
  them here) and `OAuth\AuthorizedRequestHandler` behind it turns the
  configured identity (`principal` | `client_id` | `subject`) into
  `SharedSecretMiddleware::CLIENT_ID_ATTRIBUTE`; a token WITHOUT that claim is
  answered 401 `invalid_token`, never passed on with a null client id.
  `OAuth\OAuthComponentFactory` validates the cheap params (resource, servers,
  scopes) BEFORE building the validator, so a typo names its param instead of
  surfacing as a cache/HTTP failure; the package ships OAuth unconfigured and
  resolving the guard from those defaults throws. `firebase/php-jwt` is only
  suggested (JWT validation through `JwtTokenValidator::fromIssuer()`), so the
  factory checks for it and names the package; `psr/cache` is required.
- **Classes covered by property tests (the `OpenApi\*` ones are in
  `rasuvaeff/yii3-mcp`) — six classes are covered by property tests, and the properties are the
  contract — not the examples.** `Utf8::cut()` (budget, encodability, prefix
  relation), `OpenApi\ToolNameValidator` (charset + length; stated
  independently of the class's own regex, which is what makes it able to catch
  a `\z` → `$` regression), `Interceptor\ArgumentMasker` (idempotence, no sensitive value at
  any depth, structure preserved), `Visibility\DeclarativeToolVisibility`
  (deny beats allow, monotonicity) and `Interceptor\ToolCallBudgetInterceptor`
  (model-based for both eras: `tests/Support/SessionBudget/` and
  `tests/Support/StatelessBudget/` — clients × windows × clock — sequential
  only, see the concurrency note above). Generators live in `<method>Generators()`, **public
  static** (rector rewrites a private static helper called from a property
  body, which silently unhooks the provider — it surfaces on `release-check`,
  not `build`). Every property carries `Classify::cover` gates: a generator
  that drifts and stops reaching a branch fails loudly instead of passing
  vacuously. CI caches the regression corpus (`PROPERTY_DB`) with split
  `actions/cache/restore` + `save`; do not collapse them back into the
  combined action, whose `post-if: success()` never saves on the red run that
  found the counterexample.
- `#[McpTool]` on `GreetingTool::explode` in tests intentionally throws:
  the assertion is that tool failures surface as MCP error envelopes
  (`isError`/`error`), not HTTP 500 with a trace.
- Tests decode both plain-JSON and SSE-framed (`data: ...`) response bodies —
  the Streamable HTTP transport may use either framing.
- Code: `declare(strict_types=1)`, `final readonly class` (except
  `McpServeCommand` — extends symfony Command), `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` references a 40-char commit
  SHA with a `# vN` comment; `permissions: { contents: read }`,
  `persist-credentials: false` on every checkout. Never revert to floating
  tags; verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed), `llms.txt` and `CHANGELOG.md`.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.

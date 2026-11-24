---
title: "Operations"
description: "Operating an MCP server: mcp:list introspection and the mcp:doctor health check, with stable exit codes for CI and deploy gates."
---

# Operations

Two console commands answer "what is actually being served" and "is the
configuration healthy" without needing a real MCP client.

## Introspection: `mcp:list`

`mcp:list` prints every registered tool, resource, resource template and
prompt — with argument summaries (`name*` = required) — without an MCP client.
It goes through the same in-process JSON-RPC path a real client uses, so
attribute tools, OpenAPI-bridged operations and configurator-registered
prompts all show up:

```bash
./yii mcp:list
./yii mcp:list --json   # full definitions as normalized JSON
```

`--json` prints the complete capability definitions (input/output schemas
included) in the `SchemaSnapshot` normalized form — item order and object
keys are stable, so the output diffs cleanly in CI and feeds external
automation.

The listing is the **default (unauthenticated) view** — the command drives a
synthetic session with no client identity, and says so in its output: with
per-session visibility or RBAC wired in, a real client may see a different
capability set.

The command (like `McpTester`) needs PSR-17 factories in the container. Keep
these services in every config group that builds `Mcp\Server`, including the
console group:

| Entry point / feature | Required services |
|---|---|
| `McpAction` | `ResponseFactoryInterface`, `StreamFactoryInterface` |
| `McpListCommand`, `McpTester` | `ServerRequestFactoryInterface`, `ResponseFactoryInterface`, `StreamFactoryInterface` |
| URL OpenAPI spec | PSR-18 `ClientInterface`, PSR-17 `RequestFactoryInterface` |
| OpenAPI operation execution | PSR-18 `ClientInterface`, PSR-17 `RequestFactoryInterface`, `StreamFactoryInterface` |
| OpenAPI operation execution (`executor => 'psr15'`) | PSR-17 `ServerRequestFactoryInterface`, `StreamFactoryInterface`, the configured `openapi.handler` |

`ServerRequestFactoryInterface` and `RequestFactoryInterface` are distinct
PSR-17 contracts; binding one does not satisfy the other.

## Diagnostics: `mcp:doctor`

`mcp:doctor` checks the MCP server configuration end-to-end and reports each
check as pass/skip/fail — the output never contains the configured secret or
header values, and printed URLs are stripped of userinfo credentials
(exception messages from application services pass through truncated, so
treat the report as operator-facing diagnostics):

```bash
./yii mcp:doctor           # human-readable table
./yii mcp:doctor --json    # machine-readable report
./yii mcp:doctor --probe   # also fetch a URL OpenAPI spec over the network
```

Checks include endpoint secret, the optional `expected_http_host` allow-list,
every PSR service required by enabled entry points/features, session storage
(including **confidentiality**: a session directory readable by group/others
fails the check, not just an unwritable one), the OpenAPI spec, the MCP Apps
configuration (every declarative definition is parsed, so a malformed one is
reported even when the server build check is skipped), the protocol eras
(what the stateless era changes for this configuration: a single
`endpoint_secret` makes every stateless caller share one tool-call budget, no
`notifications.bus` leaves listen streams empty, no `request_state.key` limits
a call to one ask; a too-short key fails) and a real server build. Missing services are reported by
their exact interface. Exit codes are stable for scripting: `0` healthy,
`2` config error, `3` storage error, `4` upstream error — the category of the
**first** failing check (checks run root-causes-first, so a broken config
reports as config even though it also breaks the server build).

Without `--probe` the command never touches the network: with a URL
`spec_path` both the spec fetch and the server build (which loads the spec
eagerly) are reported as skipped.

See [Cookbook: debugging with mcp:doctor](/cookbook/debugging-with-doctor) for a
walkthrough of diagnosing a broken deployment.

## Interactive debugging

For manual exploration against a running Streamable HTTP endpoint, use the
official MCP Inspector:

```bash
npx @modelcontextprotocol/inspector
# transport: Streamable HTTP, URL: https://your-app/rest/mcp,
# header: X-Mcp-Secret: <secret>
```

## Contract drift detection

`Testing\SchemaSnapshot` snapshots every served capability definition
(everything `mcp:list --json` prints) into a committed JSON file; a test run
comparing against it fails on any drift — a changed method signature
silently changes the generated `inputSchema`, which would otherwise break
agents mid-flight without any test noticing. See
[Cookbook: your first MCP server](/cookbook/mcp-server-first-time).

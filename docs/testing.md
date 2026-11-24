---
title: "Testing your tools"
description: "Testing MCP tools in-process with McpTester on both protocol eras, and catching contract drift with a committed schema snapshot."
---

# Testing your tools

`Testing\McpTester` drives the real Streamable HTTP code path in-process —
no HTTP server, no stdio process:

```php
$tester = new McpTester($server, $psr17, $psr17, $psr17);

$result = $tester->callTool('order.status', ['orderId' => '42']);
$this->assertSame('paid', $result['content'][0]['text']);

$tester->listTools();                 // every paginated tool definition
$tester->listResources();             // every resource definition
$tester->listResourceTemplates();     // every resource-template definition
$tester->listPrompts();               // every prompt definition
$tester->readResource('app://x');     // resource contents
$tester->request('custom/method');     // any raw JSON-RPC method
```

Without a revision the tester speaks the newest **handshake** revision
(`ProtocolVersion::latestHandshake()`; the SDK's
`MessageInterface::PROTOCOL_VERSION` is the stateless 2026-07-28 since 0.9).
It addresses `http://localhost/mcp`, so the request carries a `Host` the
SDK's DNS-rebinding guard allows — a request without one is refused since
SDK 0.9 — and sends empty tool arguments as `{}` (a JSON array is refused
with `-32602`).

Pass a revision as the fifth argument to test a specific protocol era, and
`ClientCapabilities` as the sixth to test a tool that asks the user
(`new ClientCapabilities(elicitation: true)`; the first call returns the
`input_required` result, answer with `request('tools/call', [... 'inputResponses' => [...]])`). A
handshake revision (`ProtocolVersion::V2025_06_18`, …) is negotiated through
`initialize`; `ProtocolVersion::V2026_07_28` speaks the stateless era — no
`initialize`, no session id, every request carries its own `_meta` envelope
and the `Mcp-Method`/`Mcp-Name`/`Mcp-Param-*` headers, built by the SDK's own
client classes (`initialize()` asks `server/discover` there). The server must
serve that era (`McpServerFactory(modernEra: true)`):

```php
$modern = new McpTester($server, $psr17, $psr17, $psr17, ProtocolVersion::V2026_07_28);
$modern->callTool('order.status', ['orderId' => '42']);
```

A JSON-RPC error is thrown as `Testing\McpErrorException` with the whole
envelope — `errorCode`, `errorMessage`, `errorData` — so a test can assert the
code, not only the text (`-32602` for an unknown or hidden capability).

## Schema snapshot: catch accidental contract drift

A changed method signature silently changes the generated `inputSchema` — and
breaks agents mid-flight. `Testing\SchemaSnapshot` snapshots every served
capability definition into a committed JSON file; drift fails the test until
the snapshot is regenerated deliberately:

```php
SchemaSnapshot::verify($tester, __DIR__ . '/mcp-schema.json');
// a mismatch throws with a per-section summary:
// "tools: changed [order.status]; prompts: added [code-review]"
```

`verify()` treats a **missing** snapshot as an error, so a deleted or
never-committed file cannot yield a green CI build. To create or deliberately
regenerate the snapshot, run once with the environment flag (or call
`SchemaSnapshot::record()`), then commit the file:

```bash
MCP_SNAPSHOT_RECORD=1 vendor/bin/testo --suite=Unit
```

`assert()` remains as the migration-friendly mode: a missing file is generated
on the first run, then compared exactly like `verify()`.

When bumping the `mcp/sdk` pin, expect to regenerate: schema serialization
may legitimately change between SDK minors.

For interactive debugging use the official MCP Inspector:

```bash
npx @modelcontextprotocol/inspector
# transport: Streamable HTTP, URL: https://your-app/rest/mcp,
# header: X-Mcp-Secret: <secret>
```

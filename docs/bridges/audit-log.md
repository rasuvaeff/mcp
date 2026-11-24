---
title: "Audit log bridge"
description: "rasuvaeff/yii3-mcp-audit-log-bridge: record every MCP tools/call as an audit event — actor resolution, argument masking, and what it can't see."
---

# Audit log bridge

`rasuvaeff/yii3-mcp-audit-log-bridge` records every `tools/call` into
[`rasuvaeff/yii3-audit-log`](https://github.com/rasuvaeff/yii3-audit-log) —
the answer to "what did the AI actually do in our system."

## Install and wire

```bash
composer require rasuvaeff/yii3-mcp-audit-log-bridge
```

```php
// config/params.php
use Rasuvaeff\Yii3McpAuditLogBridge\AuditTrailInterceptor;

'rasuvaeff/yii3-mcp' => [
    'interceptors' => [AuditTrailInterceptor::class],
],
```

`AuditLogger` must already be wired — `rasuvaeff/yii3-audit-log`'s own
config does that.

## What gets recorded

One audit event per `tools/call`, across every registration path (attribute
tools, OpenAPI-bridged operations, configurator-registered handlers):

| Audit field | Value |
|---|---|
| actor | decided by an `AuditActorResolverInterface`; default type `mcp-client`, id = MCP session id, or the client id where there is no session (stateless era, stdio); name = client (`claude-code 1.2`) |
| action | `mcp.tools.call` |
| subject | type `mcp-tool` (configurable), id = tool name |
| changes | one field per tool argument, plus `mcp.outcome`, `mcp.duration_ms`, `mcp.session`, `mcp.client`, `mcp.client_id` (when the transport carries one), `mcp.error` (on failure) |
| metadata | requestId = session id (handshake era only), userAgent = client name |

`mcp.outcome` follows the core's shared
[`CallOutcome`](/bridges/overview#a-shared-vocabulary-calloutcome) vocabulary
— a rejection (rate limit, RBAC) is distinguishable from an
unexpected crash in audit queries. **Failures are recorded and rethrown** —
the MCP error envelope the agent sees is unchanged, and a failing call is
still audited.

## What the audit trail does NOT see

Both blind spots are shared with every other bridge — see
[Bridges: a shared blind spot](/bridges/overview#a-shared-blind-spot-rejections-outside-the-chain):
tool-call budget exhaustion and session-ownership rejections both happen
outside the interceptor chain and produce no audit event.

## Who is the actor: the connection or the user

By default the actor is the MCP **connection** (session id + handshake
client name) — enough for a single-agent server, but it cannot answer
"which user did what," since session ids die with the session store's TTL
while audit rows live for years.

```php
// config/common/di/mcp.php
use Rasuvaeff\Yii3McpAuditLogBridge\{AuditActorResolverInterface, IdentityAuditActorResolver};

return [AuditActorResolverInterface::class => IdentityAuditActorResolver::class];
```

| Resolver | Actor |
|---|---|
| `ClientAuditActorResolver` (default) | type `mcp-client`, id = session id or client id, name = client |
| `IdentityAuditActorResolver` | type `mcp-user`, id = the authenticated user id (guest falls back to the connection) |
| your own | anything the application knows |

`IdentityAuditActorResolver` reads identity from the
[RBAC bridge](/bridges/rbac)'s `IdentitySourceInterface` — the same source
its RBAC and session-binding interceptors use, so the audit trail and the
access decision can never disagree about who is calling. The RBAC bridge is
a `suggest`, not a hard dependency; installing it and binding the resolver
is the one line above. A resolver that throws fails the call loudly — an
event is never written under a wrong actor.

The connection is never lost when the actor becomes a user: `mcp.session`,
`mcp.client`, and `mcp.client_id` (each when the transport carries it) are
recorded as change fields regardless of actor. That attribution is stronger still
— the session owner is immutable once stamped at `initialize`, so
`mcp.client_id` on a session's events can never be silently reassigned
mid-session.

## Masking sensitive arguments

Argument values first go through the core's recursive `ArgumentMasker` (pass
your own as `argumentMasker:`), so a secret nested in an array or object is
masked at any depth. Each tool argument then becomes its own change field, and
`AuditLogger`'s `SensitiveValueMasker` applies by field name — see
[Bridges: a shared helper](/bridges/overview#a-shared-helper-argumentmasker).
Call metadata fields keep the `mcp.` prefix so they can never collide with
an argument name.

## Manual wiring

```php
$interceptor = new AuditTrailInterceptor(
    auditLogger: $auditLogger,                            // Rasuvaeff\Yii3AuditLog\AuditLogger
    actorResolver: new ClientAuditActorResolver('agent'), // default: ClientAuditActorResolver('mcp-client')
    subjectType: 'mcp-tool',                              // default
    argumentMasker: new ArgumentMasker(),                 // default
);

$server = $factory->create($tools, $configurators, [$interceptor]);
```

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3 – 8.6 |
| `rasuvaeff/mcp` | `^0.1` |
| `rasuvaeff/yii3-mcp` | `^6.0` in a Yii3 application |
| `rasuvaeff/yii3-audit-log` | `^1.0` |
| `rasuvaeff/yii3-mcp-rbac-bridge` | `^2.0`, optional — only for `IdentityAuditActorResolver` |

Full class-by-class reference: [packages/audit-log-bridge](/packages/audit-log-bridge).

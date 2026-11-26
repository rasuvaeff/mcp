# Changelog

## Unreleased

- **Fixed:** a `subscriptions/listen` stream no longer ends an output buffer that belongs to
  the server. Under RoadRunner it killed the worker, under Swoole the frames were lost; now
  only the buffers PHP opened itself are ended. Under RoadRunner the stream arrives in one
  piece when it ends (#11).

## 0.1.3 — 2026-11-25

- **Fixed:** the stateless tool-call budget rejects a call when the cache reports a failed
  write, and counts on a store that returns numbers as strings (Laravel on Redis); both
  cases lifted the limit silently (#8).

## 0.1.2 — 2026-11-25

- **Added:** `Configuration\ServerOptions` and `Configuration\ServerComponentResolver`:
  the interpretation of the server params, moved here from `rasuvaeff/yii3-mcp` so that
  every framework package reads the same keys (#6).
- **Added:** `Testing\AdapterContract`: the behaviour every framework adapter must keep,
  checked through the framework's own kernel.
- **Changed:** conflicts with `opis/json-schema` below 2.6.2, which calls a function
  deprecated in PHP 8.6.

## 0.1.1 — 2026-11-24

- **Fixed:** visibility sees the client id of the request on the stateless era: it
  is resolved into the session before any filtered handler decides. It was null
  there, so a per-client filter treated every stateless client as anonymous (#4).

## 0.1.0 — 2026-11-23

- Initial package: the MCP server runtime extracted from `rasuvaeff/yii3-mcp`
  5.0.1 under `Rasuvaeff\Mcp`.
  `ExecutionIdentity` and `ExecutionIdentityProviderInterface` move from
  `OpenApi\` to `Identity\`. Markdown prompts are not included.
- **Changed:** the client id travels with the request instead of a process-wide
  static slot, so interleaved requests (Fibers, Swoole) keep their own identity.
- **Added:** `McpTester(clientId:)` makes every request as that client.
- **Added:** agent skill `rasuvaeff-mcp` in `resources/skills`.

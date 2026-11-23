# Changelog

## Unreleased

- Initial package: the MCP server runtime extracted from `rasuvaeff/yii3-mcp`
  5.0.1 under `Rasuvaeff\Mcp`.
  `ExecutionIdentity` and `ExecutionIdentityProviderInterface` move from
  `OpenApi\` to `Identity\`. Markdown prompts are not included.
- **Changed:** the client id travels with the request instead of a process-wide
  static slot, so interleaved requests (Fibers, Swoole) keep their own identity.
  Behind `SharedSecretMiddleware`, `RequestContext::getAccessToken()` now returns
  a token without scopes or subject instead of `null`.
- **Added:** `McpTester(clientId:)` makes every request as that client.

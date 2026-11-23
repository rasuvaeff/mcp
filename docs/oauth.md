---
title: "OAuth 2.1 resource server"
description: "Protecting an MCP endpoint with OAuth 2.1 bearer tokens: BearerTokenMiddleware, audience and issuer checks, scopes and client identity."
---

# OAuth 2.1 resource server

::: tip Yii3 params
`'rasuvaeff/yii3-mcp' => [...]` snippets are Yii3 configuration. Elsewhere pass the same
value as a constructor argument; see [Getting started](/intro/getting-started#_4-add-what-you-need).
:::

The alternative to shared secrets: the MCP endpoint accepts access tokens an
authorization server issued for it (MCP authorization spec, RFC 9728
metadata, RFC 6750 challenges). Validation, the `WWW-Authenticate`
challenges and the per-method/per-tool scopes are the SDK's own
`AuthorizationMiddleware`; `OAuth\BearerTokenMiddleware` wraps it and turns
the token's identity into the **client id**, so session ownership,
visibility, the tool-call budget, the result cache key and the
audit/RBAC/telemetry bridges work exactly as with `client_secrets`.

```bash
composer require firebase/php-jwt   # JWT validation; not needed with your own validator
```

```php
// config/params.php
'rasuvaeff/yii3-mcp' => [
    'oauth' => [
        // canonical URI of the endpoint; '' = OAuth not configured
        'resource' => 'https://mcp.example.com/mcp',
        // issuers whose tokens are accepted (at least one)
        'authorization_servers' => ['https://auth.example.com'],
        'issuer' => '',              // expected `iss`; '' = the first authorization server
        'audience' => [],            // accepted `aud`; [] = the resource
        'algorithms' => ['RS256'],
        'token_type' => '',          // required `typ`, e.g. 'at+jwt' (RFC 9068); '' skips the check
        'leeway' => 0,               // tolerated clock skew, seconds
        'scope_claim' => 'scope',
        'scopes_supported' => ['mcp'],   // advertised in the metadata and the 401 challenge
        'scopes' => [
            'default' => ['mcp'],                         // every request
            'methods' => ['tools/call' => ['mcp:tools']], // per JSON-RPC method
            'tools' => ['order.cancel' => ['orders:write']], // per tool name
            'implies' => ['orders:admin' => ['orders:write']], // a granted key grants its list
        ],
        // what becomes the client id: 'principal' (issuer + client + subject),
        // 'client_id' (client_id, falling back to azp) or 'subject' (sub)
        'identity' => 'principal',
        'validator' => '',   // service id of your own AuthorizationTokenValidatorInterface
        'cache' => '',       // service id of a PSR-6 pool; '' = Psr\Cache\CacheItemPoolInterface
        'resource_name' => 'Orders',
        'resource_documentation' => 'https://example.com/docs/mcp',
    ],
],
```

```php
// config/routes.php
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Rasuvaeff\Mcp\OAuth\BearerTokenMiddleware;

Route::methods(['POST', 'GET', 'DELETE', 'OPTIONS'], '/mcp')
    ->middleware(BearerTokenMiddleware::class)
    ->action(McpAction::class),
// RFC 9728: the path the resource derives — /.well-known/oauth-protected-resource + the endpoint path
Route::get('/.well-known/oauth-protected-resource/mcp')
    ->action(ProtectedResourceMetadataHandler::class),
```

- **Validation.** Without `oauth.validator` the token is a JWT checked against
  the issuer's keys: the SDK reads the issuer's discovery document
  (`/.well-known/oauth-authorization-server`, then `openid-configuration`),
  fetches its JWKS and caches both in the PSR-6 pool (rate-limited key
  refresh). Uses the container's PSR-18 `ClientInterface` and
  `RequestFactoryInterface` when bound. Opaque tokens or introspection:
  bind your own `Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface`
  and name it in `oauth.validator`.
- **Answers.** No or another scheme → 401 with `resource_metadata="…"`; an
  invalid, expired, foreign-audience or foreign-issuer token → 401
  `invalid_token`; a missing scope → 403 `insufficient_scope` with the
  required scopes; the keys cannot be fetched → 503 with `Retry-After`.
- **Identity.** A token without the claim `identity` names (e.g. no `sub` with
  `'subject'`) is refused with 401 `invalid_token` — never served
  anonymously. With `'principal'` the client id is an opaque string of
  issuer, client and subject; pick `'client_id'` or `'subject'` when RBAC
  roles or audit records should name it readably.
- **Sessions** are bound to the token's principal by the SDK and to the client
  id by the package; the validated `AccessToken` is available to a handler
  through `RequestContext::getAccessToken()` (scopes, claims).
- **Misconfiguration fails at build**, naming the param: no `resource`, no
  authorization server, an unknown `scopes` key, a validator or cache service
  of the wrong type, missing `firebase/php-jwt`. The package ships OAuth
  unconfigured; resolving `BearerTokenMiddleware` from the defaults throws
  instead of letting requests through.

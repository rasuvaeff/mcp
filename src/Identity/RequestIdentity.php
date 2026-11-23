<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Identity;

use Mcp\Server\Authorization\AccessToken;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The client id of ONE request, carried by the request itself: the SDK hands
 * its reference handler and its request-state binder the JSON-RPC request, not
 * the PSR-7 one, but it does pass the `AccessToken::class` attribute to both —
 * per request, on both protocol eras, never written to a session store.
 *
 * The identity travels as an OBJECT in the token's claims. A claim decoded
 * from a JWT or an introspection response is a scalar or an array, so no
 * token issuer can produce one.
 *
 * @internal
 */
final readonly class RequestIdentity
{
    private const string CLAIM = 'rasuvaeff.yii3-mcp.client-id';

    private function __construct(
        private string $clientId,
        private ?AccessToken $original,
    ) {}

    /**
     * The request as it was when there is no client id to carry.
     */
    public static function attach(ServerRequestInterface $request, ?string $clientId): ServerRequestInterface
    {
        if ($clientId === null) {
            return $request;
        }

        /** @var mixed $token */
        $token = $request->getAttribute(AccessToken::class);
        $original = $token instanceof AccessToken ? $token : null;

        return $request->withAttribute(AccessToken::class, new AccessToken(
            scopes: $original?->getScopes() ?? [],
            claims: [...($original?->getClaims() ?? []), self::CLAIM => new self(clientId: $clientId, original: $original)],
        ));
    }

    public static function clientId(?AccessToken $token): ?string
    {
        $identity = self::of($token);

        return $identity instanceof self ? $identity->clientId : null;
    }

    /**
     * The token the request really arrived with — null for a client that
     * presented a shared secret, not a token.
     */
    public static function originalToken(?AccessToken $token): ?AccessToken
    {
        $identity = self::of($token);

        return $identity instanceof self ? $identity->original : $token;
    }

    private static function of(?AccessToken $token): ?self
    {
        /** @var mixed $identity */
        $identity = $token?->getClaim(self::CLAIM);

        return $identity instanceof self ? $identity : null;
    }
}

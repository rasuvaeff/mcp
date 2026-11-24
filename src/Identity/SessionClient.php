<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Identity;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Session\SessionInterface;
use Rasuvaeff\Mcp\Exception\SessionOwnershipException;
use Rasuvaeff\Mcp\Interceptor\InterceptingReferenceHandler;

/**
 * The client id of the current request, resolved into its session before
 * anything decides on it: every filtered handler and the reference handler
 * call this first, so visibility sees the same id on both protocol eras.
 *
 * @internal
 */
final class SessionClient
{
    /**
     * The session's immutable owner wins; the client id the request itself
     * carries ({@see RequestIdentity}) covers sessions with no recorded owner —
     * always so on the stateless era — and is mirrored into the session on
     * first use. An owner that DISAGREES with the current request's id fails
     * closed: a session is never re-attributed.
     */
    public static function resolve(?SessionInterface $session): ?string
    {
        if (!$session instanceof SessionInterface) {
            return null;
        }

        /** @var mixed $token */
        $token = $session->get(AccessToken::class);
        $token = $token instanceof AccessToken ? $token : null;
        $clientId = RequestIdentity::clientId($token);

        // the carrier has done its job: from here on interceptors, visibility
        // and the handler see the token the request really arrived with, so
        // "has a token" keeps meaning OAuth
        if ($clientId !== null) {
            $session->set(AccessToken::class, RequestIdentity::originalToken($token));
        }

        /** @var mixed $stored */
        $stored = $session->get(InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY);

        if (is_string($stored)) {
            if ($clientId !== null && $clientId !== $stored) {
                throw new SessionOwnershipException(sprintf(
                    'Session belongs to another client; refusing to serve client "%s"',
                    $clientId,
                ));
            }

            return $stored;
        }

        if ($clientId !== null) {
            $session->set(InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY, $clientId);
        }

        return $clientId;
    }
}

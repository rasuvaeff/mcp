<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Stateless\OriginatingRequestBinder;
use Mcp\Server\Stateless\RequestStateBinderInterface;
use Rasuvaeff\Mcp\Identity\RequestIdentity;

/**
 * Binds a multi round-trip `requestState` to the request it was minted for
 * (the SDK's {@see OriginatingRequestBinder}: method, tool/prompt/resource,
 * a digest of the arguments and the OAuth principal) AND to the package's
 * resolved client id.
 *
 * The SDK binder knows only an OAuth token; behind
 * {@see SharedSecretMiddleware} there is none, so a state minted for one
 * client would verify when another client replays it with the same
 * arguments. The client id comes from the token McpAction puts on the request
 * ({@see RequestIdentity}); the inner binder gets the token the request really
 * arrived with, so a shared-secret client stays tokenless for it.
 *
 * @internal
 */
final readonly class ClientBoundRequestStateBinder implements RequestStateBinderInterface
{
    public function __construct(
        private RequestStateBinderInterface $inner = new OriginatingRequestBinder(),
    ) {}

    #[\Override]
    public function bind(Request $request, ?AccessToken $accessToken): string
    {
        return json_encode(
            [
                'request' => $this->inner->bind($request, RequestIdentity::originalToken($accessToken)),
                'client' => RequestIdentity::clientId($accessToken),
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}

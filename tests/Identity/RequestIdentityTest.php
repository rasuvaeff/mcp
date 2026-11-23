<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Identity;

use Mcp\Server\Authorization\AccessToken;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Mcp\Identity\RequestIdentity;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RequestIdentity::class)]
final class RequestIdentityTest
{
    public function withoutAClientIdTheRequestIsUntouched(): void
    {
        $request = new ServerRequest('POST', '/mcp');

        Assert::same(RequestIdentity::attach($request, null), $request);
    }

    public function aSharedSecretClientIsCarriedWithoutARealToken(): void
    {
        $token = $this->attached(new ServerRequest('POST', '/mcp'), 'claude');

        Assert::same(RequestIdentity::clientId($token), 'claude');
        Assert::null(RequestIdentity::originalToken($token));
        Assert::same($token->getScopes(), []);
        Assert::null($token->getSubject());
    }

    public function aBearerTokenKeepsItsScopesAndClaims(): void
    {
        $original = new AccessToken(['mcp'], ['sub' => 'user-1', 'client_id' => 'agent']);
        $token = $this->attached((new ServerRequest('POST', '/mcp'))->withAttribute(AccessToken::class, $original), 'agent');

        Assert::same(RequestIdentity::clientId($token), 'agent');
        Assert::same(RequestIdentity::originalToken($token), $original);
        Assert::same($token->getScopes(), ['mcp']);
        Assert::same($token->getSubject(), 'user-1');
        Assert::same($token->getPrincipal(), $original->getPrincipal());
    }

    #[DataProvider('forgedClaimProvider')]
    public function aClaimFromTheTokenItselfIsNeverAnIdentity(mixed $claim): void
    {
        $token = new AccessToken([], ['rasuvaeff.yii3-mcp.client-id' => $claim]);

        Assert::null(RequestIdentity::clientId($token));
        Assert::same(RequestIdentity::originalToken($token), $token);
    }

    public static function forgedClaimProvider(): iterable
    {
        yield 'string' => ['admin'];
        yield 'array' => [['clientId' => 'admin']];
        yield 'object' => [(object) ['clientId' => 'admin']];
    }

    public function noTokenHasNoIdentity(): void
    {
        Assert::null(RequestIdentity::clientId(null));
        Assert::null(RequestIdentity::originalToken(null));
    }

    private function attached(ServerRequest $request, string $clientId): AccessToken
    {
        return RequestIdentity::attach($request, $clientId)->getAttribute(AccessToken::class);
    }
}

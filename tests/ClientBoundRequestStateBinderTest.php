<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests;

use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Authorization\AccessToken;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Mcp\ClientBoundRequestStateBinder;
use Rasuvaeff\Mcp\Identity\RequestIdentity;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClientBoundRequestStateBinder::class)]
final class ClientBoundRequestStateBinderTest
{
    public function theSameRequestOfTheSameClientBindsTheSame(): void
    {
        Assert::same($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind('a', 'order.rename', ['orderId' => '42']));
    }

    public function anotherClientBindsDifferently(): void
    {
        Assert::notSame($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind('b', 'order.rename', ['orderId' => '42']));
        Assert::notSame($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind(null, 'order.rename', ['orderId' => '42']));
    }

    public function aBearerTokenIsBoundAsTheTokenItReallyIs(): void
    {
        $original = new AccessToken([], ['iss' => 'https://idp.test', 'sub' => 'user-1']);
        $request = RequestIdentity::attach((new ServerRequest('POST', '/mcp'))->withAttribute(AccessToken::class, $original), 'a');
        $bound = json_decode((new ClientBoundRequestStateBinder())->bind(new CallToolRequest('t', []), $request->getAttribute(AccessToken::class)), associative: true);

        Assert::same($bound['client'], 'a');
        Assert::same(json_decode($bound['request'], associative: true)['principal'], ['identified' => ['iss' => 'https://idp.test', 'client' => null, 'sub' => 'user-1']]);
    }

    public function anotherToolOrOtherArgumentsBindDifferently(): void
    {
        Assert::notSame($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind('a', 'order.delete', ['orderId' => '42']));
        Assert::notSame($this->bind('a', 'order.rename', ['orderId' => '42']), $this->bind('a', 'order.rename', ['orderId' => '43']));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function bind(?string $clientId, string $tool, array $arguments): string
    {
        $request = RequestIdentity::attach(new ServerRequest('POST', '/mcp'), $clientId);

        return (new ClientBoundRequestStateBinder())->bind(new CallToolRequest($tool, $arguments), $request->getAttribute(AccessToken::class));
    }
}

<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Support;

use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

final readonly class TokenTool
{
    #[McpTool(name: 'token.subject')]
    public function subject(RequestContext $context): string
    {
        $token = $context->getAccessToken();

        return $token instanceof \Mcp\Server\Authorization\AccessToken ? 'subject ' . $token->getSubject() . ', claims ' . implode(',', array_keys($token->getClaims())) : 'no token';
    }
}

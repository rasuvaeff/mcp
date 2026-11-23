<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Support;

use Mcp\Server\Capability\Attribute\McpTool;

final readonly class StaticOnlyTool
{
    #[McpTool(name: 'static-op')]
    public static function staticOp(): string
    {
        return 'static';
    }
}

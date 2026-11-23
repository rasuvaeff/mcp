<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Support;

use Mcp\Server\Capability\Attribute\McpTool;

final class CountingTool
{
    public int $calls = 0;

    /**
     * Counts invocations.
     */
    #[McpTool(name: 'count.up')]
    public function up(): int
    {
        return ++$this->calls;
    }
}

<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Identity;

/**
 * Minimal application identity passed to delegated upstream credential
 * providers. It deliberately excludes the raw MCP shared secret.
 *
 * @api
 */
final readonly class ExecutionIdentity
{
    public function __construct(
        public ?string $subjectId = null,
        public ?string $tenantId = null,
        public ?string $clientId = null,
    ) {}
}

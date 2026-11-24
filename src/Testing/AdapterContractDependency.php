<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Testing;

/**
 * What {@see AdapterContractTool} needs from the container: a concrete class
 * nothing binds, so the adapter proves its container autowires tools.
 *
 * @api
 */
final readonly class AdapterContractDependency
{
    public function greeting(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}

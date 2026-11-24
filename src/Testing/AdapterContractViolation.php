<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Testing;

use RuntimeException;

/**
 * A framework adapter does not keep the contract {@see AdapterContract} checks.
 *
 * @api
 */
final class AdapterContractViolation extends RuntimeException {}

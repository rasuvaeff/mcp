<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Testing;

use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Rasuvaeff\Mcp\Interceptor\InterceptingReferenceHandler;
use RuntimeException;

/**
 * The tool class an application under {@see AdapterContract} registers.
 *
 * @api
 */
final readonly class AdapterContractTool
{
    public const string SECRET_FAILURE = 'adapter-contract-internal-detail';

    public function __construct(
        private AdapterContractDependency $dependency,
    ) {}

    #[McpTool(name: 'contract.greet')]
    public function greet(string $name): string
    {
        return $this->dependency->greeting($name);
    }

    #[McpTool(name: 'contract.client')]
    public function client(RequestContext $context): string
    {
        /** @var mixed $clientId */
        $clientId = $context->getSession()->get(InterceptingReferenceHandler::CLIENT_ID_SESSION_KEY);

        return is_string($clientId) ? $clientId : '';
    }

    #[McpTool(name: 'contract.fail')]
    public function fail(): string
    {
        throw new RuntimeException(self::SECRET_FAILURE);
    }
}

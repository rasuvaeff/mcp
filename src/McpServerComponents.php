<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp;

use Rasuvaeff\Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Mcp\Interceptor\ToolResultDecoratorInterface;
use Rasuvaeff\Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Mcp\Visibility\ResourceVisibilityInterface;
use Rasuvaeff\Mcp\Visibility\ToolVisibilityInterface;

/**
 * @internal
 */
final readonly class McpServerComponents
{
    /**
     * @param list<class-string> $tools
     * @param list<ServerConfiguratorInterface> $configurators
     * @param list<ToolCallInterceptorInterface> $interceptors
     * @param list<PromptGetInterceptorInterface> $promptInterceptors
     * @param list<ResourceReadInterceptorInterface> $resourceInterceptors
     * @param list<ToolResultDecoratorInterface> $resultDecorators
     */
    public function __construct(
        public array $tools,
        public array $configurators,
        public array $interceptors,
        public ?ToolVisibilityInterface $visibility,
        public array $promptInterceptors,
        public array $resourceInterceptors,
        public ?PromptVisibilityInterface $promptVisibility,
        public ?ResourceVisibilityInterface $resourceVisibility,
        public array $resultDecorators = [],
    ) {}
}

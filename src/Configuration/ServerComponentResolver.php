<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Configuration;

use LogicException;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Mcp\Apps\AppParamParser;
use Rasuvaeff\Mcp\Apps\McpAppsConfigurator;
use Rasuvaeff\Mcp\Identity\ExecutionIdentityProviderInterface;
use Rasuvaeff\Mcp\Interceptor\CachingToolCallInterceptor;
use Rasuvaeff\Mcp\Interceptor\PromptGetInterceptorInterface;
use Rasuvaeff\Mcp\Interceptor\ResourceReadInterceptorInterface;
use Rasuvaeff\Mcp\Interceptor\ResponseSizeLimitInterceptor;
use Rasuvaeff\Mcp\Interceptor\ToolCallBudgetInterceptor;
use Rasuvaeff\Mcp\Interceptor\ToolCallInterceptorInterface;
use Rasuvaeff\Mcp\Interceptor\ToolResultDecoratorInterface;
use Rasuvaeff\Mcp\McpServerComponents;
use Rasuvaeff\Mcp\ServerConfiguratorInterface;
use Rasuvaeff\Mcp\Visibility\DeclarativeToolVisibility;
use Rasuvaeff\Mcp\Visibility\PromptVisibilityInterface;
use Rasuvaeff\Mcp\Visibility\ResourceVisibilityInterface;
use Rasuvaeff\Mcp\Visibility\ToolVisibilityInterface;

/**
 * Turns server params into the components {@see \Rasuvaeff\Mcp\McpServerFactory}
 * takes, resolving every configured class through the container. Shared by the
 * framework packages: the keys are the same in each of them.
 *
 * @api
 */
final readonly class ServerComponentResolver
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private ContainerInterface $container,
        private array $params,
    ) {}

    /**
     * @param list<ServerConfiguratorInterface> $configurators applied before the configured ones
     */
    public function resolve(array $configurators = []): McpServerComponents
    {
        /** @var list<class-string> $tools */
        $tools = $this->params['tools'] ?? [];
        /** @var array{spec_path?: string, operations?: list<string>, identity_provider?: class-string<ExecutionIdentityProviderInterface>|''} $openapi */
        $openapi = $this->params['openapi'] ?? [];

        /** @var array<string, int> $cacheTools */
        $cacheTools = $this->params['cache']['tools'] ?? [];

        // Resolve application services only when a configured feature needs them.
        $identityProviderClass = $openapi['identity_provider'] ?? '';
        $identityProviderNeeded = $cacheTools !== [];
        /** @var ?ExecutionIdentityProviderInterface $identityProvider */
        $identityProvider = $identityProviderClass === '' || !$identityProviderNeeded
            ? null
            : $this->getService($identityProviderClass);

        /** @var array{enable?: bool, definitions?: list<array<string, mixed>>} $apps */
        $apps = $this->params['apps'] ?? [];
        $appDefinitions = $apps['definitions'] ?? [];

        if (($apps['enable'] ?? false) || $appDefinitions !== []) {
            $configurators[] = new McpAppsConfigurator(
                array_map(AppParamParser::parse(...), $appDefinitions),
            );
        }

        /** @var list<class-string<ServerConfiguratorInterface>> $configuratorClasses */
        $configuratorClasses = $this->params['configurators'] ?? [];

        foreach ($configuratorClasses as $configuratorClass) {
            $configurators[] = $this->getService($configuratorClass);
        }

        /** @var list<ToolCallInterceptorInterface> $interceptors */
        $interceptors = [];

        /** @var array{calls?: int, window?: int} $budget */
        $budget = $this->params['tool_call_budget'] ?? [];
        $calls = $budget['calls'] ?? 0;

        if ($calls > 0) {
            /** @var string $serverName */
            $serverName = $this->params['server_name'];
            /** @var bool $modernEra */
            $modernEra = $this->params['modern_era'] ?? true;
            $interceptors[] = new ToolCallBudgetInterceptor(
                budget: $calls,
                // the stateless era has no session to count in; only then is
                // a PSR-16 store required — and resolved
                cache: $modernEra ? $this->budgetCache() : null,
                window: $budget['window'] ?? 3600,
                namespace: $serverName,
            );
        }

        /** @var list<class-string<ToolCallInterceptorInterface>> $interceptorClasses */
        $interceptorClasses = $this->params['interceptors'] ?? [];

        foreach ($interceptorClasses as $interceptorClass) {
            $interceptors[] = $this->getService($interceptorClass);
        }

        if ($cacheTools !== []) {
            /** @var string $cacheNamespace */
            $cacheNamespace = $this->params['cache']['namespace'] ?? '';
            /** @var string $serverName */
            $serverName = $this->params['server_name'];
            $interceptors[] = new CachingToolCallInterceptor(
                $this->getService(CacheInterface::class),
                $cacheTools,
                namespace: $cacheNamespace === '' ? $serverName : $cacheNamespace,
                identityProvider: $identityProvider,
            );
        }

        /** @var int $maxResultBytes */
        $maxResultBytes = $this->params['limits']['tool_result_bytes'] ?? 0;

        if ($maxResultBytes > 0) {
            $interceptors[] = new ResponseSizeLimitInterceptor($maxResultBytes);
        }

        /** @var class-string<ToolVisibilityInterface>|'' $visibilityClass */
        $visibilityClass = $this->params['tool_visibility'] ?? '';
        /** @var array{deny?: list<string>, allow?: list<string>} $declarative */
        $declarative = $this->params['visibility'] ?? [];
        $deny = $declarative['deny'] ?? [];
        $allow = $declarative['allow'] ?? [];

        if ($visibilityClass !== '' && ($deny !== [] || $allow !== [])) {
            throw new LogicException('Configure either "tool_visibility" (a ToolVisibilityInterface class) or declarative "visibility" deny/allow lists, not both');
        }

        $visibility = null;

        if ($visibilityClass !== '') {
            /** @var ToolVisibilityInterface $visibility */
            $visibility = $this->getService($visibilityClass);
        } elseif ($deny !== [] || $allow !== []) {
            $visibility = new DeclarativeToolVisibility(deny: $deny, allow: $allow);
        }

        /** @var list<PromptGetInterceptorInterface> $promptInterceptors */
        $promptInterceptors = [];

        /** @var list<class-string<PromptGetInterceptorInterface>> $promptInterceptorClasses */
        $promptInterceptorClasses = $this->params['prompt_interceptors'] ?? [];

        foreach ($promptInterceptorClasses as $promptInterceptorClass) {
            $promptInterceptors[] = $this->getService($promptInterceptorClass);
        }

        /** @var list<ResourceReadInterceptorInterface> $resourceInterceptors */
        $resourceInterceptors = [];

        /** @var list<class-string<ResourceReadInterceptorInterface>> $resourceInterceptorClasses */
        $resourceInterceptorClasses = $this->params['resource_interceptors'] ?? [];

        foreach ($resourceInterceptorClasses as $resourceInterceptorClass) {
            $resourceInterceptors[] = $this->getService($resourceInterceptorClass);
        }

        /** @var class-string<PromptVisibilityInterface>|'' $promptVisibilityClass */
        $promptVisibilityClass = $this->params['prompt_visibility'] ?? '';
        /** @var PromptVisibilityInterface|null $promptVisibility */
        $promptVisibility = $promptVisibilityClass === '' ? null : $this->getService($promptVisibilityClass);

        /** @var class-string<ResourceVisibilityInterface>|'' $resourceVisibilityClass */
        $resourceVisibilityClass = $this->params['resource_visibility'] ?? '';
        /** @var ResourceVisibilityInterface|null $resourceVisibility */
        $resourceVisibility = $resourceVisibilityClass === '' ? null : $this->getService($resourceVisibilityClass);

        /** @var list<ToolResultDecoratorInterface> $resultDecorators */
        $resultDecorators = [];

        /** @var list<class-string> $resultDecoratorClasses */
        $resultDecoratorClasses = $this->params['result_decorators'] ?? [];

        foreach ($resultDecoratorClasses as $resultDecoratorClass) {
            $resultDecorator = $this->getService($resultDecoratorClass);

            // getService() only proves the configured class itself; a class
            // that is no decorator must fail the build, not the first call
            if (!$resultDecorator instanceof ToolResultDecoratorInterface) {
                throw new LogicException(sprintf('Result decorator "%s" must implement %s', $resultDecoratorClass, ToolResultDecoratorInterface::class));
            }

            $resultDecorators[] = $resultDecorator;
        }

        return new McpServerComponents(
            $tools,
            $configurators,
            $interceptors,
            $visibility,
            $promptInterceptors,
            $resourceInterceptors,
            $promptVisibility,
            $resourceVisibility,
            $resultDecorators,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function getService(string $id): object
    {
        $service = $this->container->get($id);

        if (!$service instanceof $id) {
            throw new LogicException(sprintf('Service "%s" must be an instance of %s', $id, $id));
        }

        return $service;
    }

    private function budgetCache(): CacheInterface
    {
        if (!$this->container->has(CacheInterface::class)) {
            throw new LogicException(sprintf(
                '"tool_call_budget.calls" with "modern_era" enabled needs a %s in the container: the stateless era has no session to count calls in',
                CacheInterface::class,
            ));
        }

        /** @var CacheInterface */
        return $this->container->get(CacheInterface::class);
    }
}

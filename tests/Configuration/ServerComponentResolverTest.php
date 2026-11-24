<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Configuration;

use LogicException;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Mcp\Apps\McpAppsConfigurator;
use Rasuvaeff\Mcp\Configuration\ServerComponentResolver;
use Rasuvaeff\Mcp\Identity\ExecutionIdentity;
use Rasuvaeff\Mcp\Interceptor\CachingToolCallInterceptor;
use Rasuvaeff\Mcp\Interceptor\ResponseSizeLimitInterceptor;
use Rasuvaeff\Mcp\Interceptor\ToolCallBudgetInterceptor;
use Rasuvaeff\Mcp\Tests\Support\DenyListVisibility;
use Rasuvaeff\Mcp\Tests\Support\DenyPromptVisibility;
use Rasuvaeff\Mcp\Tests\Support\DenyResourceVisibility;
use Rasuvaeff\Mcp\Tests\Support\FakeCache;
use Rasuvaeff\Mcp\Tests\Support\GreetingTool;
use Rasuvaeff\Mcp\Tests\Support\IdentityDecorator;
use Rasuvaeff\Mcp\Tests\Support\LinkingDecorator;
use Rasuvaeff\Mcp\Tests\Support\MutableExecutionIdentityProvider;
use Rasuvaeff\Mcp\Tests\Support\PromptsConfigurator;
use Rasuvaeff\Mcp\Tests\Support\RecordingInterceptor;
use Rasuvaeff\Mcp\Tests\Support\RecordingPromptInterceptor;
use Rasuvaeff\Mcp\Tests\Support\RecordingResourceInterceptor;
use Rasuvaeff\Mcp\Visibility\DeclarativeToolVisibility;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

#[Test]
#[Covers(ServerComponentResolver::class)]
final class ServerComponentResolverTest
{
    public function emptyParamsConfigureNothing(): void
    {
        $components = (new ServerComponentResolver(new SimpleContainer([]), []))->resolve();

        Assert::same($components->tools, []);
        Assert::same($components->configurators, []);
        Assert::same($components->interceptors, []);
        Assert::null($components->visibility);
        Assert::same($components->promptInterceptors, []);
        Assert::same($components->resourceInterceptors, []);
        Assert::null($components->promptVisibility);
        Assert::null($components->resourceVisibility);
        Assert::same($components->resultDecorators, []);
    }

    public function configuredClassesAreResolvedInOrder(): void
    {
        $services = [
            RecordingInterceptor::class => new RecordingInterceptor('a'),
            RecordingPromptInterceptor::class => new RecordingPromptInterceptor(),
            RecordingResourceInterceptor::class => new RecordingResourceInterceptor(),
            DenyListVisibility::class => new DenyListVisibility(['x']),
            DenyPromptVisibility::class => new DenyPromptVisibility(hidden: ['x']),
            DenyResourceVisibility::class => new DenyResourceVisibility(hiddenUris: ['file:///x']),
            LinkingDecorator::class => new LinkingDecorator(name: 'l'),
            IdentityDecorator::class => new IdentityDecorator(),
            PromptsConfigurator::class => new PromptsConfigurator(),
        ];
        $leading = new PromptsConfigurator();
        $components = (new ServerComponentResolver(new SimpleContainer($services), [
            'tools' => [GreetingTool::class],
            'interceptors' => [RecordingInterceptor::class],
            'prompt_interceptors' => [RecordingPromptInterceptor::class],
            'resource_interceptors' => [RecordingResourceInterceptor::class],
            'tool_visibility' => DenyListVisibility::class,
            'prompt_visibility' => DenyPromptVisibility::class,
            'resource_visibility' => DenyResourceVisibility::class,
            'result_decorators' => [LinkingDecorator::class, IdentityDecorator::class],
            'configurators' => [PromptsConfigurator::class],
        ]))->resolve([$leading]);

        Assert::same($components->tools, [GreetingTool::class]);
        Assert::same($components->configurators, [$leading, $services[PromptsConfigurator::class]]);
        Assert::same($components->interceptors, [$services[RecordingInterceptor::class]]);
        Assert::same($components->promptInterceptors, [$services[RecordingPromptInterceptor::class]]);
        Assert::same($components->resourceInterceptors, [$services[RecordingResourceInterceptor::class]]);
        Assert::same($components->visibility, $services[DenyListVisibility::class]);
        Assert::same($components->promptVisibility, $services[DenyPromptVisibility::class]);
        Assert::same($components->resourceVisibility, $services[DenyResourceVisibility::class]);
        Assert::same($components->resultDecorators, [$services[LinkingDecorator::class], $services[IdentityDecorator::class]]);
    }

    public function theChainIsBudgetConfiguredCacheAndSizeLimit(): void
    {
        $cache = new FakeCache();
        $own = new RecordingInterceptor('own');
        $interceptors = (new ServerComponentResolver(new SimpleContainer([CacheInterface::class => $cache, RecordingInterceptor::class => $own]), [
            'server_name' => 'app',
            'tool_call_budget' => ['calls' => 1, 'window' => 60],
            'interceptors' => [RecordingInterceptor::class],
            'cache' => ['tools' => ['greet' => 10]],
            'limits' => ['tool_result_bytes' => 1],
        ]))->resolve()->interceptors;

        Assert::same(count($interceptors), 4);
        Assert::equals($interceptors[0], new ToolCallBudgetInterceptor(budget: 1, cache: $cache, window: 60, namespace: 'app'));
        Assert::same($interceptors[1], $own);
        Assert::equals($interceptors[2], new CachingToolCallInterceptor($cache, ['greet' => 10], namespace: 'app'));
        Assert::equals($interceptors[3], new ResponseSizeLimitInterceptor(1));
    }

    public function omittedBudgetKeysUseTheDocumentedDefaults(): void
    {
        $cache = new FakeCache();
        $interceptors = (new ServerComponentResolver(new SimpleContainer([CacheInterface::class => $cache]), [
            'server_name' => 'app',
            'tool_call_budget' => ['calls' => 5],
        ]))->resolve()->interceptors;

        Assert::equals($interceptors, [new ToolCallBudgetInterceptor(budget: 5, cache: $cache, window: 3600, namespace: 'app')]);
    }

    public function theBudgetNeedsACacheOnTheStatelessEraOnly(): void
    {
        $params = ['server_name' => 'app', 'tool_call_budget' => ['calls' => 5]];

        Assert::string($this->failure($params))->contains('"tool_call_budget.calls" with "modern_era" enabled needs a ' . CacheInterface::class);
        Assert::equals(
            (new ServerComponentResolver(new SimpleContainer([]), $params + ['modern_era' => false]))->resolve()->interceptors,
            [new ToolCallBudgetInterceptor(budget: 5, window: 3600, namespace: 'app')],
        );
    }

    public function theToolCacheUsesItsNamespaceAndTheExecutionIdentity(): void
    {
        $cache = new FakeCache();
        $identity = new MutableExecutionIdentityProvider(new ExecutionIdentity(subjectId: 'u'));
        $interceptors = (new ServerComponentResolver(new SimpleContainer([CacheInterface::class => $cache, MutableExecutionIdentityProvider::class => $identity]), [
            'server_name' => 'app',
            'cache' => ['tools' => ['greet' => 10], 'namespace' => 'shared'],
            'openapi' => ['identity_provider' => MutableExecutionIdentityProvider::class],
        ]))->resolve()->interceptors;

        Assert::equals($interceptors, [new CachingToolCallInterceptor($cache, ['greet' => 10], namespace: 'shared', identityProvider: $identity)]);
    }

    public function theIdentityProviderIsNotResolvedWithoutTheToolCache(): void
    {
        $components = (new ServerComponentResolver(new SimpleContainer([]), [
            'openapi' => ['identity_provider' => MutableExecutionIdentityProvider::class],
        ]))->resolve();

        Assert::same($components->interceptors, []);
    }

    /**
     * @param array<string, mixed> $apps
     */
    #[DataProvider('appsProvider')]
    public function appsAreConfiguredWhenEnabledOrDefined(array $apps, int $configurators): void
    {
        $resolved = (new ServerComponentResolver(new SimpleContainer([]), ['apps' => $apps]))->resolve()->configurators;

        Assert::same(count($resolved), $configurators);

        foreach ($resolved as $configurator) {
            Assert::instanceOf($configurator, McpAppsConfigurator::class);
        }
    }

    public static function appsProvider(): iterable
    {
        $definition = ['uri' => 'ui://a', 'name' => 'a', 'html' => '<p>'];

        yield 'nothing' => [[], 0];
        yield 'off' => [['enable' => false, 'definitions' => []], 0];
        yield 'enabled' => [['enable' => true], 1];
        yield 'defined' => [['definitions' => [$definition]], 1];
        yield 'both' => [['enable' => true, 'definitions' => [$definition]], 1];
    }

    /**
     * @param array<string, mixed> $visibility
     */
    #[DataProvider('visibilityProvider')]
    public function declarativeVisibilityIsBuiltFromEitherList(array $visibility, ?DeclarativeToolVisibility $expected): void
    {
        Assert::equals((new ServerComponentResolver(new SimpleContainer([]), ['visibility' => $visibility]))->resolve()->visibility, $expected);
    }

    public static function visibilityProvider(): iterable
    {
        yield 'none' => [['deny' => [], 'allow' => []], null];
        yield 'deny' => [['deny' => ['a.*']], new DeclarativeToolVisibility(deny: ['a.*'], allow: [])];
        yield 'allow' => [['allow' => ['b.*']], new DeclarativeToolVisibility(deny: [], allow: ['b.*'])];
        yield 'both' => [['deny' => ['a.*'], 'allow' => ['b.*']], new DeclarativeToolVisibility(deny: ['a.*'], allow: ['b.*'])];
    }

    #[DataProvider('visibilityConflictProvider')]
    public function aVisibilityClassExcludesTheDeclarativeLists(array $visibility): void
    {
        Assert::string($this->failure(['tool_visibility' => DenyListVisibility::class, 'visibility' => $visibility]))
            ->contains('Configure either "tool_visibility"');
    }

    public static function visibilityConflictProvider(): iterable
    {
        yield 'deny' => [['deny' => ['a']]];
        yield 'allow' => [['allow' => ['a']]];
    }

    public function aResultDecoratorThatIsNoDecoratorFails(): void
    {
        Assert::string($this->failure(['result_decorators' => [GreetingTool::class]], [GreetingTool::class => new GreetingTool(prefix: 'Hi')]))
            ->contains('must implement Rasuvaeff\\Mcp\\Interceptor\\ToolResultDecoratorInterface');
    }

    public function aServiceOfAnotherTypeFails(): void
    {
        Assert::string($this->failure(['interceptors' => [RecordingInterceptor::class]], [RecordingInterceptor::class => new \stdClass()]))
            ->contains('must be an instance of ' . RecordingInterceptor::class);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $services
     */
    private function failure(array $params, array $services = []): string
    {
        $caught = null;

        try {
            (new ServerComponentResolver(new SimpleContainer($services), $params))->resolve();
        } catch (LogicException $caught) {
        }

        Assert::notNull($caught);

        return $caught->getMessage();
    }
}

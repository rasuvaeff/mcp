# rasuvaeff/mcp

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/mcp?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/mcp)](https://packagist.org/packages/rasuvaeff/mcp)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp/build.yml?branch=master)](https://github.com/rasuvaeff/mcp/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/mcp/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/mcp/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/mcp/blob/master/psalm.xml)
[![License](https://img.shields.io/packagist/l/rasuvaeff/mcp)](LICENSE.md)

[English version](README.md)

Независимый от фреймворка runtime сервера
[Model Context Protocol](https://modelcontextprotocol.io) поверх официального
[`mcp/sdk`](https://packagist.org/packages/mcp/sdk): tools на атрибутах через
любой PSR-11 контейнер, interceptors, фильтры visibility, владение session и
OAuth; работает через PSR-15 или stdio. Для Yii3 ставьте
[`rasuvaeff/yii3-mcp`](https://github.com/rasuvaeff/yii3-mcp): он подключает этот
пакет сам.

**[Документация](https://rasuvaeff.github.io/mcp/)** — руководство, интеграция с Yii3, bridges, cookbook и API-справочник (на английском).

> **Пользуетесь AI coding assistant?** [llms.txt](llms.txt) содержит компактную
> API-справку для модели. Для контрибьюторов: [AGENTS.md](AGENTS.md).
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent-скилл этого пакета в `.agents/skills/`
> автоматически при установке.

## Требования

| Требование | Версия |
|---|---|
| PHP | 8.3 – 8.6 |
| `mcp/sdk` | `~0.10.0` (экспериментальный до 1.0, отсюда tilde-пин) |
| `ext-fileinfo` | необязательно: определение MIME-типа содержимого файлов |
| Реализация PSR-7/PSR-17 | любая, для HTTP endpoint |
| `firebase/php-jwt` | необязательно, `^7.0`: проверка JWT для OAuth |

## Установка

```bash
composer require rasuvaeff/mcp
```

## Использование

### Объявить tool

Атрибуты протокола принадлежат SDK; сам класс — обычный сервис.

```php
use Mcp\Server\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->get($orderId)->status;
    }
}
```

### Отдать по HTTP

```php
use Rasuvaeff\Mcp\{McpAction, McpServerFactory, SharedSecretMiddleware};
use Rasuvaeff\Mcp\Session\PrivateFileSessionStore;

$store = new PrivateFileSessionStore(directory: '/var/lib/my-app/mcp-sessions', ttl: 3600);
$server = (new McpServerFactory(
    container: $container, // PSR-11
    sessionStore: $store,
    name: 'my-app',
    version: '1.0.0',
))->create([OrderTools::class]);

$action = new McpAction(
    server: $server,
    responseFactory: $psr17,
    streamFactory: $psr17,
    allowedHosts: ['mcp.example.com'],
    sessionStore: $store,
);
$guard = new SharedSecretMiddleware(secret: getenv('MCP_SECRET') ?: '', responseFactory: $psr17);

// route POST/GET/DELETE/OPTIONS /mcp through $guard -> $action
```

Передавайте одно и то же хранилище sessions фабрике и `McpAction`: без него
session защищена только своим id. Для stdio добавьте `McpServeCommand` в любой
`Symfony\Component\Console\Application`.

### Защита и обработка вызовов

Первый interceptor — самый внешний.

```php
use Rasuvaeff\Mcp\Interceptor\{RateLimitInterceptor, ResponseSizeLimitInterceptor, ToolCallBudgetInterceptor};
use Rasuvaeff\Mcp\Visibility\DeclarativeToolVisibility;

$server = $factory->create(
    toolClasses: [OrderTools::class],
    configurators: [$configurator],
    interceptors: [
        new ToolCallBudgetInterceptor(budget: 50, cache: $psr16, window: 3600),
        new RateLimitInterceptor($limiter),
        new ResponseSizeLimitInterceptor(maxBytes: 65_536),
    ],
    toolVisibility: new DeclarativeToolVisibility(deny: ['admin.*']),
);
```

### Тестирование

```php
use Rasuvaeff\Mcp\Testing\{McpTester, SchemaSnapshot};

$tester = new McpTester($server, $psr17, $psr17, $psr17);
$tester->callTool('order.status', ['orderId' => '42']);
SchemaSnapshot::verify($tester, __DIR__ . '/mcp-schema.json');
```

### Компоненты

| Класс | Роль |
|---|---|
| `McpServerFactory` | имена классов tools → настроенный SDK `Server` |
| `McpAction` | PSR-15 endpoint Streamable HTTP; привязывает session к клиенту |
| `McpServeCommand`, `McpListCommand` | команды `symfony/console`: `mcp:serve` (stdio) и `mcp:list` |
| `SharedSecretMiddleware` | fail-closed проверка секрета; пустой секрет отвечает 503 |
| `OAuth\BearerTokenMiddleware` | guard OAuth 2.1 resource server поверх `AuthorizationMiddleware` SDK |
| `Identity\SecretResolverInterface`, `Identity\StaticSecretResolver` | несколько клиентов на endpoint и ротация секретов |
| `Identity\ExecutionIdentity`, `Identity\ExecutionIdentityProviderInterface` | от чьего имени выполняется вызов |
| `Session\PrivateFileSessionStore` | файловые sessions только для владельца (`0700`/`0600`) |
| `ConditionalToolInterface` | отказ класса tools от регистрации |
| `ServerConfiguratorInterface`, `ReservedToolNamesAwareInterface` | добавляют capabilities в SDK builder |
| `Interceptor\ToolCallInterceptorInterface`, `Interceptor\ToolCallContext` | обёртка каждого `tools/call` |
| `Interceptor\PromptGetInterceptorInterface`, `Interceptor\PromptGetContext` | обёртка каждого `prompts/get` |
| `Interceptor\ResourceReadInterceptorInterface`, `Interceptor\ResourceReadContext` | обёртка каждого `resources/read` |
| `Interceptor\ToolCallBudgetInterceptor` | лимит вызовов на session или на клиента и окно |
| `Interceptor\RateLimitInterceptor`, `Interceptor\ToolCallLimiterInterface` | лимиты через ваш limiter, fail-closed |
| `Interceptor\ResponseSizeLimitInterceptor` | ограничивает размер результата tool |
| `Interceptor\CachingToolCallInterceptor` | PSR-16 кэш по клиентам для выбранных tools |
| `Interceptor\ToolResultDecoratorInterface` | правит оформленный результат успешного вызова |
| `Interceptor\ArgumentMasker`, `Interceptor\CallOutcome` | маскирование и словарь исходов для аудита и телеметрии |
| `Interceptor\InterceptingReferenceHandler` | подключает цепочки к SDK (использует фабрика) |
| `Visibility\ToolVisibilityInterface`, `Visibility\DeclarativeToolVisibility` | фильтры tools: по session или по шаблону имени |
| `Visibility\PromptVisibilityInterface`, `Visibility\ResourceVisibilityInterface` | фильтры prompts и resources |
| `Resource\ResourceUpdateNotifier` | отправляет `notifications/resources/updated` |
| `Apps\McpAppsConfigurator`, `Apps\AppDefinition` | MCP Apps (`ui://` resources) |
| `Testing\McpTester`, `Testing\McpErrorException`, `Testing\SchemaSnapshot` | in-process клиент и снимок контракта |
| `Exception\DuplicateCapabilityException` | две capability с одной identity; сборка падает |
| `Exception\InvalidToolClassException` | класс tools отсутствует или не имеет атрибутов capability |
| `Exception\SessionOwnershipException` | вызов пришёл не от владельца session |

### Долгоживущие процессы

Ни один класс не хранит изменяемое static-состояние. Client id едет вместе с
запросом (в `AccessToken` SDK, живущем один запрос), поэтому чередующиеся запросы
одного процесса (Fibers, корутины Swoole) сохраняют свою идентичность; это покрыто
тестом на голых Fibers.

## Безопасность

- Endpoint только для доверенных: ставьте перед `McpAction`
  `SharedSecretMiddleware`, `OAuth\BearerTokenMiddleware` или сетевой ACL.
- Пустой секрет отклоняет все запросы; скрытая capability отвечает ровно как
  отсутствующая; сбой limiter или провайдера идентичности отклоняет вызов.
- Имена capabilities уникальны на весь сервер, это проверяется при сборке.
- Аннотации tools — подсказки клиенту, а не авторизация.

## Примеры

См. [examples/](examples/): 10 скриптов, все работают offline.

## Разработка

PHP и Composer на хосте не нужны, запускайте в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Или через Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).

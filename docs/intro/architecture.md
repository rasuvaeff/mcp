---
title: "Architecture"
description: "How McpServerFactory assembles an mcp/sdk Server from tool classes and configurators: tool registration, interceptors, visibility, and the PSR services each entry point needs."
---

# Architecture

## McpServerFactory: from tool classes to an SDK Server

`McpServerFactory::create()` (`src/McpServerFactory.php`) is the single place
that turns application config into an `Mcp\Server\Builder`-built `Server`:

1. For every FQCN in `tools`, it reflects the class's public methods for the
   SDK's own attributes (`#[McpTool]`, `#[McpResource]`,
   `#[McpResourceTemplate]`, `#[McpPrompt]`) and registers each as a
   `[class, method]` handler via `Builder::addTool()` / `addResource()` /
   `addResourceTemplate()` / `addPrompt()`. A class implementing
   `ConditionalToolInterface` is resolved through the container first and
   skipped when `shouldRegister()` returns `false`. A class with **no**
   capability attributes on any public method throws
   `InvalidToolClassException` at build time — never a silently empty
   registration.
2. Every `ServerConfiguratorInterface` (the OpenAPI bridge, your own) runs next, each contributing capabilities to the
   same builder. A configurator implementing
   `ReservedToolNamesAwareInterface` is handed the attribute tools' names
   first, so it can fail fast on a collision instead of silently losing a
   tool to the SDK's last-write-wins registry (see below).
3. The builder's reference handler is always
   `Interceptor\InterceptingReferenceHandler`, a decorator wrapping the SDK's own
   `ReferenceHandler`, so **every** registration path (attribute tools,
   OpenAPI-bridged operations, configurator-added handlers) goes through the same
   interceptor and visibility chain and the same client identity check.
4. The registry is always wrapped in `GuardedRegistry` (`@internal`): the SDK
   registry is last-write-wins with no duplicate check, so a name collision
   between *any* two registration paths would otherwise silently drop one
   handler. `GuardedRegistry` throws `Exception\DuplicateCapabilityException`
   at build time on any live duplicate — this is the second, structural line
   of defense behind the reserved-names handshake in step 2.
5. If any per-session visibility filter is configured, the corresponding
   `Filtered*Handler` (`Visibility\FilteredListToolsHandler`,
   `FilteredCallToolHandler`,
   `FilteredListPromptsHandler`, `FilteredListResourcesHandler`,
   `FilteredListResourceTemplatesHandler`, and
   `FilteredCompletionCompleteHandler` when prompt or resource visibility is
   set) is registered as a request handler ahead of the SDK's own — see
   [Visibility](/visibility) for why `completion/complete` needs its own
   decorator.

## What the runtime needs

`McpServerFactory` and `McpAction` depend only on PSR interfaces and the SDK's
`SessionStoreInterface`. [Getting started](/intro/getting-started) wires them by
hand; [Yii3: configuration](/yii3/configuration) shows how the Yii3 package does it
from params.

Different entry points need different PSR services present in the container:

| Entry point / feature | Required services |
|---|---|
| `McpAction` | `ResponseFactoryInterface`, `StreamFactoryInterface` |
| `McpListCommand`, `McpTester` | `ServerRequestFactoryInterface`, `ResponseFactoryInterface`, `StreamFactoryInterface` |
| URL OpenAPI spec | PSR-18 `ClientInterface`, PSR-17 `RequestFactoryInterface` |
| OpenAPI operation execution (HTTP) | `ClientInterface`, `RequestFactoryInterface`, `StreamFactoryInterface` |
| OpenAPI operation execution (PSR-15) | `RequestHandlerInterface`, `ServerRequestFactoryInterface`, `StreamFactoryInterface` |
| URL spec cache | PSR-16 `CacheInterface`, when a spec cache TTL is set (`openapi.cache_ttl` in Yii3) |

`ServerRequestFactoryInterface` and `RequestFactoryInterface` are distinct
PSR-17 contracts — binding one does not satisfy the other. Keep these bound
in **every** config group that builds `Mcp\Server\Server` (in Yii3 also the console
group used by `mcp:list`/`mcp:doctor`/`mcp:serve` — `mcp:doctor` reports a
missing service by its exact interface name.

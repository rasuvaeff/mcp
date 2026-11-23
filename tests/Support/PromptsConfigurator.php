<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Support;

use Mcp\Server\Builder;
use Rasuvaeff\Mcp\ServerConfiguratorInterface;

final readonly class PromptsConfigurator implements ServerConfiguratorInterface
{
    #[\Override]
    public function configure(Builder $builder): void
    {
        $builder
            ->addPrompt(static fn(string $diff, string $focus = ''): string => $focus . $diff, name: 'code-review')
            ->addPrompt(static fn(): string => 'ok', name: 'plain-note');
    }
}

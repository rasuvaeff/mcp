<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Support;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}

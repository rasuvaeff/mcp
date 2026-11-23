<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A body whose first read suspends the surrounding Fiber: what a non-blocking
 * server does while the request body is still arriving.
 */
final class SuspendingStream implements StreamInterface
{
    private bool $suspended = false;

    public function __construct(
        private readonly StreamInterface $inner,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        $this->suspend();

        return (string) $this->inner;
    }

    #[\Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[\Override]
    public function detach()
    {
        return $this->inner->detach();
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    #[\Override]
    public function tell(): int
    {
        return $this->inner->tell();
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->inner->eof();
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    #[\Override]
    public function rewind(): void
    {
        $this->inner->rewind();
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $this->suspend();

        return $this->inner->read($length);
    }

    #[\Override]
    public function getContents(): string
    {
        $this->suspend();

        return $this->inner->getContents();
    }

    #[\Override]
    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    private function suspend(): void
    {
        if (!$this->suspended && \Fiber::getCurrent() instanceof \Fiber) {
            $this->suspended = true;
            \Fiber::suspend();
        }
    }
}

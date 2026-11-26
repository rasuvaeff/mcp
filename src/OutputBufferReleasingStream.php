<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp;

use Psr\Http\Message\StreamInterface;

/**
 * Body of a `text/event-stream` response: ends PHP's userland output buffers
 * right before the SDK's callback stream writes its frames, so each frame's
 * `flush()` reaches the SAPI instead of piling up in a buffer.
 *
 * The SDK's stateless responder (mcp/sdk 0.8.1, `StatelessResponder::sse()`)
 * writes every frame with `echo` + `flush()` only. `flush()` does not touch
 * the buffer `output_buffering` (4096 in both shipped php.ini files) opens
 * before the application starts, and `yiisoft/psr-emitter` ends only the
 * buffers opened above the level it recorded — so a `subscriptions/listen`
 * stream reached the client in one piece when it closed. The handshake-era
 * transport calls `ob_flush()` itself and is unaffected; ending the buffers
 * first is harmless for it.
 *
 * Only buffers PHP opened itself (`default output handler`) are ended, top
 * down: the release stops at the first buffer with a handler of its own.
 * Under RoadRunner that is the PSR-7 capture, so a stream arrives in one
 * piece when it ends (#11). Under the CLI SAPI with no buffer left the frames
 * are captured and returned with the read (#15).
 * Buffers are ended down to `$baseLevel` (0 — all of them — in production);
 * a non-removable buffer stops the release.
 *
 * @internal
 */
final readonly class OutputBufferReleasingStream implements StreamInterface
{
    private const string PHP_HANDLER = 'default output handler';

    /**
     * @param string $sapi PHP_SAPI; a parameter so the CLI case is testable
     * @param bool|null $roadRunner whether this is a RoadRunner worker; null = the `RR_MODE` variable RoadRunner sets
     */
    public function __construct(
        private StreamInterface $inner,
        private int $baseLevel = 0,
        private string $sapi = PHP_SAPI,
        private ?bool $roadRunner = null,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->deliver(fn(): string => (string) $this->inner);
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
        return $this->inner->isWritable();
    }

    #[\Override]
    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    #[\Override]
    public function read(int $length): string
    {
        return $this->deliver(fn(): string => $this->inner->read($length));
    }

    #[\Override]
    public function getContents(): string
    {
        return $this->deliver(fn(): string => $this->inner->getContents());
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }

    /**
     * A CLI worker answers through the PSR-7 body: frames echoed there reach
     * its stdout, not the client. RoadRunner's worker even keeps a buffer of
     * its own that sends stdout to its log, and a buffer cannot tell where it
     * forwards. So under the CLI, when no buffer is left or under RoadRunner,
     * the frames are captured and returned with the read: the stream arrives
     * whole when it ends (#15). Swoole's buffer is the response and stays live.
     *
     * @param \Closure(): string $read
     */
    private function deliver(\Closure $read): string
    {
        $this->release();

        $capture = $this->sapi === 'cli'
            && (ob_get_level() === $this->baseLevel || ($this->roadRunner ?? getenv('RR_MODE') !== false));

        if (!$capture) {
            return $read();
        }

        // the SDK calls ob_flush() after each frame: a plain buffer would pass
        // the frames down to RoadRunner's log, this handler keeps them
        $captured = '';
        ob_start(static function (string $chunk) use (&$captured): string {
            $captured .= $chunk;

            return '';
        });

        try {
            $result = $read();
        } finally {
            ob_end_clean();
        }

        return $captured . $result;
    }

    private function release(): void
    {
        while (ob_get_level() > $this->baseLevel) {
            // a buffer with a handler of its own is how a long-running server
            // (Swoole, the PSR-7 capture under RoadRunner) receives the
            // response: ending it sends the frames to the worker's stdout
            if ((ob_get_status()['name'] ?? '') !== self::PHP_HANDLER) {
                return;
            }

            // a non-removable buffer refuses with a notice and stays: stop there
            if (!@ob_end_flush()) {
                return;
            }
        }
    }
}

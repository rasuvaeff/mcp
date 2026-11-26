<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\Tests;

use Mcp\Server\Transport\CallbackStream;
use Nyholm\Psr7\Stream;
use Rasuvaeff\Mcp\OutputBufferReleasingStream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(OutputBufferReleasingStream::class)]
final class OutputBufferReleasingStreamTest
{
    /**
     * The SDK's SSE callback echoes each frame and calls flush(): it must run
     * with no userland buffer above the base level, or frames pile up until
     * the stream closes. Output already sitting in those buffers is passed on
     * in order, not dropped.
     */
    /**
     * @param \Closure(OutputBufferReleasingStream): string $consume
     */
    #[DataProvider('consumers')]
    public function underAServerSapiTheFramesGoOutAsTheyAreWritten(\Closure $consume): void
    {
        $this->assertReleasedBy($consume, 'fpm-fcgi', returned: '', sent: 'pending|frame');
    }

    /**
     * A CLI worker with no buffer of its own (RoadRunner's PSR7Worker) would
     * print the frames to its stdout: they come back with the read instead.
     *
     * @param \Closure(OutputBufferReleasingStream): string $consume
     */
    #[DataProvider('consumers')]
    public function underTheCliWithNoBufferLeftTheFramesComeBackWithTheRead(\Closure $consume): void
    {
        $this->assertReleasedBy($consume, 'cli', returned: 'frame', sent: 'pending|');
    }

    public static function consumers(): iterable
    {
        yield 'read' => [static fn(OutputBufferReleasingStream $stream): string => $stream->read(8192)];
        yield 'getContents' => [static fn(OutputBufferReleasingStream $stream): string => $stream->getContents()];
        yield 'cast to string' => [static fn(OutputBufferReleasingStream $stream): string => (string) $stream];
    }

    /**
     * RoadRunner's worker keeps a buffer that sends stdout to its log: the
     * frames must not end there, they come back with the read.
     */
    public function underRoadRunnerTheFramesComeBackPastItsOwnBuffer(): void
    {
        $logged = '';
        ob_start(static function (string $chunk) use (&$logged): string {
            $logged .= $chunk;

            return '';
        }, 1);
        $base = ob_get_level() - 1;

        try {
            $stream = new OutputBufferReleasingStream(new CallbackStream(static function (): void {
                echo 'frame';
                // what the SDK does after each frame
                @ob_flush();
                echo '|next';
            }), $base, 'cli', roadRunner: true);

            Assert::same($stream->getContents(), 'frame|next');
        } finally {
            while (ob_get_level() > $base) {
                ob_end_clean();
            }
        }

        Assert::same($logged, '');
    }

    public function withoutRoadRunnerABufferOfItsOwnStillTakesTheFrames(): void
    {
        $received = '';
        ob_start(static function (string $chunk) use (&$received): string {
            $received .= $chunk;

            return '';
        }, 1);
        $base = ob_get_level() - 1;

        try {
            $stream = new OutputBufferReleasingStream(new CallbackStream(static function (): void {
                echo 'frame';
            }), $base, 'cli', roadRunner: false);

            Assert::same($stream->getContents(), '');
        } finally {
            while (ob_get_level() > $base) {
                ob_end_clean();
            }
        }

        Assert::same($received, 'frame');
    }

    public function aReadThatFailsUnderTheCliLeavesNoBufferBehind(): void
    {
        $base = ob_get_level();
        $stream = new OutputBufferReleasingStream(new CallbackStream(static function (): void {
            echo 'half a frame';

            throw new \RuntimeException('stream broke');
        }), $base, 'cli', roadRunner: false);

        try {
            $stream->getContents();
            Assert::fail('the read did not fail');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'stream broke');
        }

        Assert::same(ob_get_level(), $base);
    }

    public function everythingElseIsDelegated(): void
    {
        $stream = new OutputBufferReleasingStream(Stream::create('payload'));

        Assert::same($stream->getSize(), 7);
        Assert::true($stream->isSeekable());
        Assert::true($stream->isReadable());
        Assert::true($stream->isWritable());
        $stream->seek(3);
        Assert::same($stream->tell(), 3);
        Assert::false($stream->eof());
        $stream->rewind();
        Assert::same($stream->tell(), 0);
        Assert::same($stream->getMetadata('mode'), 'w+b');
    }

    public function closeClosesTheInnerStream(): void
    {
        $inner = Stream::create('payload');
        $stream = new OutputBufferReleasingStream($inner);

        $stream->close();

        // a closed PSR-7 stream has no size and no position left
        Assert::null($inner->getSize());
        Assert::null($stream->getSize());
    }

    public function detachHandsOverTheInnerResource(): void
    {
        $inner = Stream::create('payload');
        $stream = new OutputBufferReleasingStream($inner);

        $resource = $stream->detach();

        Assert::true(is_resource($resource));
        Assert::null($inner->getSize());
    }

    public function aBufferWithAHandlerOfItsOwnStaysAndReceivesTheFrames(): void
    {
        $received = '';
        ob_start(static function (string $chunk) use (&$received): string {
            $received .= $chunk;

            return '';
        }, 1);
        $base = ob_get_level() - 1;
        $serverLevel = ob_get_level();

        try {
            ob_start();
            echo 'pending|';

            $stream = new OutputBufferReleasingStream(new CallbackStream(static function () use (&$levelAtWrite): void {
                $levelAtWrite = ob_get_level();
                echo 'frame';
            }), $base);

            Assert::same($stream->getContents(), '');
            Assert::same($levelAtWrite, $serverLevel);
        } finally {
            while (ob_get_level() > $base) {
                ob_end_clean();
            }
        }

        Assert::same($received, 'pending|frame');
    }

    public function aBufferOfItsOwnBelowStopsTheRelease(): void
    {
        $base = ob_get_level();
        ob_start(static fn(string $chunk): string => $chunk);
        $serverLevel = ob_get_level();
        ob_start();
        ob_start();

        try {
            Assert::same((new OutputBufferReleasingStream(new CallbackStream(static function (): void {}), $base))->getContents(), '');

            Assert::same(ob_get_level(), $serverLevel);
        } finally {
            while (ob_get_level() > $base) {
                ob_end_clean();
            }
        }
    }

    /**
     * @param \Closure(OutputBufferReleasingStream): string $consume
     */
    private function assertReleasedBy(\Closure $consume, string $sapi, string $returned, string $sent): void
    {
        // the outer buffer stands in for the SAPI: it collects what got through
        ob_start();
        $base = ob_get_level();
        $levelAtWrite = null;

        try {
            ob_start();
            echo 'pending|';
            ob_start();

            $stream = new OutputBufferReleasingStream(new CallbackStream(static function () use (&$levelAtWrite): void {
                $levelAtWrite = ob_get_level();
                echo 'frame';
            }), $base, $sapi, roadRunner: false);

            Assert::same($consume($stream), $returned);
            Assert::same($levelAtWrite, $sapi === 'cli' ? $base + 1 : $base);
            Assert::same(ob_get_level(), $base);
        } finally {
            while (ob_get_level() > $base) {
                ob_end_clean();
            }

            $output = (string) ob_get_clean();
        }

        Assert::same($output, $sent);
    }
}

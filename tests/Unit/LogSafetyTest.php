<?php

/**
 * Unit::LogSafetyTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LogSafety;

/**
 * The forwarded-exception-text bound (review lastfm__secaudit info).
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LogSafety
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 */
final class LogSafetyTest extends TestCase
{
    public function testShortMessagePassesThroughUnchanged(): void
    {
        $message = 'SQLSTATE[HY000]: MySQL server has gone away';
        $this->assertSame($message, LogSafety::boundedMessage(new \RuntimeException($message)));
    }

    public function testMessageOfExactlyTheCeilingIsNotMarkedTruncated(): void
    {
        $message = str_repeat('a', LogSafety::MAX_EXCEPTION_MESSAGE_BYTES);
        $this->assertSame($message, LogSafety::boundedMessage(new \RuntimeException($message)));
    }

    public function testOversizedMessageIsBoundedWithTheHeadIntact(): void
    {
        $message = 'HEAD-SURVIVES: drive full on ' . str_repeat('x', 4096) . ' sk=TAIL-MUST-NOT-LOG';
        $bounded = LogSafety::boundedMessage(new \RuntimeException($message));

        $this->assertStringStartsWith('HEAD-SURVIVES: ', $bounded);
        $this->assertStringNotContainsString('TAIL-MUST-NOT-LOG', $bounded);
        $this->assertStringContainsString('truncated', $bounded);
        $this->assertStringContainsString((string) strlen($message), $bounded, 'the log line states the original size');
        $this->assertLessThanOrEqual(
            LogSafety::MAX_EXCEPTION_MESSAGE_BYTES + LogSafety::MAX_MARKER_BYTES,
            strlen($bounded),
        );
    }

    /**
     * The cut must land on a character boundary — a prefix chopped mid
     * multi-byte sequence would put invalid UTF-8 into every log consumer.
     * A 3-byte character straddles the 512-byte ceiling (512 = 3×170+2).
     */
    public function testTruncationNeverSplitsAMultiByteCharacter(): void
    {
        $message = str_repeat('あ', 400); // 1200 bytes
        $bounded = LogSafety::boundedMessage(new \RuntimeException($message));

        $this->assertSame(1, preg_match('//u', $bounded), 'bounded text must be valid UTF-8');
        $head = substr($bounded, 0, LogSafety::MAX_EXCEPTION_MESSAGE_BYTES);
        $this->assertSame(1, preg_match('//u', $head), 'the kept prefix itself must be character-clean');
    }

    public function testNonPositiveCeilingFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LogSafety::boundedMessage(new \RuntimeException('x'), 0);
    }

    public function testScrubbedStripsControlCharsAndCaps(): void
    {
        $raw = "bad\r\nINJECTED\x00\x1B[31m\x7F" . str_repeat('x', 1000);
        $out = LogSafety::scrubbed($raw);

        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $out));
        $this->assertStringContainsString('truncated', $out);
        $this->assertLessThanOrEqual(
            LogSafety::MAX_RESPONSE_STRING_BYTES + LogSafety::MAX_MARKER_BYTES,
            strlen($out),
        );
        $this->assertSame('short ok', LogSafety::scrubbed('short ok'));
        $this->assertSame(1, preg_match('//u', LogSafety::scrubbed(str_repeat('あ', 300))));
    }
}

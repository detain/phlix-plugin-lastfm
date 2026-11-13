<?php

/**
 * Unit::PlaybackTicksTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\PlaybackTicks;

/**
 * The 100-ns tick unit, named exactly once.
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 */
#[CoversClass(\Phlix\Plugins\Scrobbler\Lastfm\PlaybackTicks::class)]
final class PlaybackTicksTest extends TestCase
{
    public function testTheConversionFactorIsThePlexJellyfinTick(): void
    {
        $this->assertSame(10_000_000, PlaybackTicks::TICKS_PER_SECOND);
    }

    public function testFromSecondsProducesTheCanonicalTickCount(): void
    {
        $this->assertSame(0, PlaybackTicks::fromSeconds(0));
        $this->assertSame(10_000_000, PlaybackTicks::fromSeconds(1));
        $this->assertSame(1_010_000_000, PlaybackTicks::fromSeconds(101));
        $this->assertSame(2_000_000_000, PlaybackTicks::fromSeconds(200));
    }

    public function testToSecondsTruncatesPartialTicksTowardZero(): void
    {
        $this->assertSame(0, PlaybackTicks::toSeconds(9_999_999));
        $this->assertSame(1, PlaybackTicks::toSeconds(10_000_000));
        $this->assertSame(1, PlaybackTicks::toSeconds(19_999_999));
        $this->assertSame(0, PlaybackTicks::toSeconds(-9_999_999));
        $this->assertSame(-1, PlaybackTicks::toSeconds(-10_000_000));
    }

    /**
     * @param int $seconds
     */
    #[DataProvider('wholeSecondRoundTripProvider')]
    public function testWholeSecondsRoundTripExactly(int $seconds): void
    {
        $this->assertSame($seconds, PlaybackTicks::toSeconds(PlaybackTicks::fromSeconds($seconds)));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function wholeSecondRoundTripProvider(): array
    {
        return [
            'zero'     => [0],
            'short'    => [30],
            'boundary' => [101],
            'long'     => [3_600],
        ];
    }
}

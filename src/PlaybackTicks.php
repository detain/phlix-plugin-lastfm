<?php

/**
 * PlaybackTicks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

/**
 * The position unit the host playback events carry.
 *
 * `PlaybackStarted::positionTicks` and `PlaybackStopped::finalPositionTicks`
 * are 100-nanosecond "ticks" (the Plex/Jellyfin convention the host event
 * contract documents). This class is the package's single naming of that
 * unit: every conversion between ticks and seconds goes through here, so a
 * future contract change is one file, not a scatter of `10_000_000` literals.
 *
 * Truncation is toward zero (`intdiv`): a partial tick never rounds up into
 * an extra played second — for the scrobble gate that is the conservative
 * direction, because over-counted listening is the failure that writes false
 * history.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 */
final class PlaybackTicks
{
    /** Ticks per second: 100 ns × 10,000,000 = 1 s. */
    public const TICKS_PER_SECOND = 10_000_000;

    /**
     * Whole seconds contained in a tick count (truncated toward zero).
     */
    public static function toSeconds(int $ticks): int
    {
        return intdiv($ticks, self::TICKS_PER_SECOND);
    }

    /**
     * The tick count for a whole number of seconds (fixture-side inverse of
     * {@see self::toSeconds()}, so tests state seconds and never multiply.
     */
    public static function fromSeconds(int $seconds): int
    {
        return $seconds * self::TICKS_PER_SECOND;
    }
}

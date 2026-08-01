<?php

/**
 * Unit::LastfmScrobblerNowPlayingCoverageTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;

/**
 * Additional edge case tests for {@see LastfmScrobbler} to cover uncovered code paths.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmScrobblerNowPlayingCoverageTest extends TestCase
{
    /** @var LastfmApi&\PHPUnit\Framework\MockObject\MockObject */
    private LastfmApi $api;

    /** @var LastfmSessionRepository&\PHPUnit\Framework\MockObject\MockObject */
    private LastfmSessionRepository $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(LastfmApi::class);
        $this->sessions = $this->createMock(LastfmSessionRepository::class);
    }

    /**
     * Test that onPlaybackStopped skips when track has null title.
     * This covers the null title check at line 169-170 in LastfmScrobbler.
     */
    public function testOnPlaybackStoppedSkipsWhenTrackHasNullTitle(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        // Track resolver returns track with null title
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => null, 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStopped(
            new PlaybackStopped('s', 'u1', 'm', 'd', 100_000_000, true)
        );
    }

    /**
     * Test that onPlaybackStopped skips when track has null artist.
     * This covers the null artist check at line 169-170 in LastfmScrobbler.
     */
    public function testOnPlaybackStoppedSkipsWhenTrackHasNullArtist(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        // Track resolver returns track with null artist
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song Title', 'artist' => null, 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStopped(
            new PlaybackStopped('s', 'u1', 'm', 'd', 100_000_000, true)
        );
    }

    /**
     * Test that onPlaybackStopped skips when scrobble rules are not met.
     * Duration <= 30 seconds should skip.
     */
    public function testOnPlaybackStoppedSkipsWhenDurationTooShort(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        // Track is only 20 seconds - too short to scrobble
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song Title', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 20,
        ]);

        // User plays for 15 seconds (>50% of 20s), but duration is <= 30s so fails MIN_DURATION_SECONDS
        $scrobbler->onPlaybackStopped(
            new PlaybackStopped('s', 'u1', 'm', 'd', 150_000_000, true)
        );
    }

    /**
     * Test that onPlaybackStopped skips when played fraction is too low.
     * Even with long track, if user played <50%, should skip.
     */
    public function testOnPlaybackStoppedSkipsWhenPlayedFractionTooLow(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song Title', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        // User plays 50 seconds out of 200 = 25% which is < 50%, so should skip
        $scrobbler->onPlaybackStopped(
            new PlaybackStopped('s', 'u1', 'm', 'd', 500_000_000, true)
        );
    }

    /**
     * Test that onPlaybackStopped scrobbles when rules ARE met.
     * This is to ensure the scrobble path is covered when conditions pass.
     */
    public function testOnPlaybackStoppedScrobblesWhenRulesMet(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);

        $capturedTimestamp = null;
        $this->api->expects(self::once())->method('scrobble')
            ->willReturnCallback(function (string $sk, string $track, string $artist, ?string $album, ?int $timestamp) use (&$capturedTimestamp) {
                $capturedTimestamp = $timestamp;
                return true;
            });

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song Title', 'artist' => 'Artist', 'album' => 'The Album', 'duration_seconds' => 200,
        ]);

        // User plays 120 seconds out of 200 = 60% which is > 50%, and duration > 30s
        $scrobbler->onPlaybackStopped(
            new PlaybackStopped('s', 'u1', 'm', 'd', 1_200_000_000, true)
        );

        // Verify a timestamp was captured
        $this->assertNotNull($capturedTimestamp);
    }

    /**
     * @param callable(string): ?array{title: string, artist: string, album: ?string, duration_seconds: ?int} $resolveTrack
     */
    private function buildScrobbler(callable $resolveTrack): LastfmScrobbler
    {
        return new LastfmScrobbler($this->api, $this->sessions, $resolveTrack);
    }
}

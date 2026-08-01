<?php

/**
 * Unit::LastfmScrobblerEdgeCasesTest.
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
 * Edge case tests for {@see LastfmScrobbler}.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmScrobblerEdgeCasesTest extends TestCase
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
     * Test that onPlaybackStarted skips when session exists but track is not found.
     * This covers line 132-133 in LastfmScrobbler where track is null.
     */
    public function testOnPlaybackStartedSkipsWhenTrackNotFound(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        // Track resolver returns null (track not found in library)
        $this->api->expects(self::never())->method('updateNowPlaying');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);

        $scrobbler->onPlaybackStarted(
            new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0)
        );
    }

    /**
     * Test that onPlaybackStopped skips when session exists but track is not found.
     * This covers line 159-160 in LastfmScrobbler where track is null.
     */
    public function testOnPlaybackStoppedSkipsWhenTrackNotFound(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        // Track resolver returns null (track not found in library)
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);

        $scrobbler->onPlaybackStopped(
            new PlaybackStopped('s', 'u1', 'm', 'd', 100_000_000, true)
        );
    }

    /**
     * Test that onPlaybackStarted skips when session exists but track has no artist.
     * This covers line 262-263 in resolveTrackResolver - when title or artist is null.
     */
    public function testOnPlaybackStartedSkipsWhenTrackHasNoArtist(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        // Track resolver returns track without artist
        $this->api->expects(self::never())->method('updateNowPlaying');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => null, 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(
            new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0)
        );
    }

    /**
     * Test that onPlaybackStarted skips when session exists but track has no title.
     */
    public function testOnPlaybackStartedSkipsWhenTrackHasNoTitle(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        // Track resolver returns track without title
        $this->api->expects(self::never())->method('updateNowPlaying');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => null, 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(
            new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0)
        );
    }

    /**
     * Test that scrobble timestamp is calculated correctly as (current time - played seconds).
     * This verifies the scrobble timestamp calculation in onPlaybackStopped.
     */
    public function testScrobbleTimestampIsCalculatedCorrectly(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);

        $capturedTimestamp = null;
        $this->api->expects(self::once())
            ->method('scrobble')
            ->willReturnCallback(function (string $sk, string $track, string $artist, ?string $album, ?int $timestamp) use (&$capturedTimestamp) {
                $capturedTimestamp = $timestamp;
                return true;
            });

        $playedSeconds = 120;
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $beforeTime = time();
        $event = new PlaybackStopped('s', 'u1', 'm', 'd', $playedSeconds * 10_000_000, true);
        $scrobbler->onPlaybackStopped($event);
        $afterTime = time();

        // Timestamp should be approximately (time() - 120)
        $expectedMin = $beforeTime - $playedSeconds;
        $expectedMax = $afterTime - $playedSeconds;
        $this->assertGreaterThanOrEqual($expectedMin, $capturedTimestamp);
        $this->assertLessThanOrEqual($expectedMax, $capturedTimestamp);
    }

    /**
     * Test that meetsScrobbleRules returns false when duration is 0.
     */
    public function testMeetsScrobbleRulesRejectsZeroDuration(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);

        // 0 duration should be rejected (unknown duration path)
        $this->assertFalse($scrobbler->meetsScrobbleRules(0, 30));
    }

    /**
     * Test that meetsScrobbleRules returns false when duration is negative.
     */
    public function testMeetsScrobbleRulesRejectsNegativeDuration(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);

        // Negative duration should be rejected
        $this->assertFalse($scrobbler->meetsScrobbleRules(-10, 30));
    }

    /**
     * @param callable(string): ?array{title: string, artist: string, album: ?string, duration_seconds: ?int} $resolveTrack
     */
    private function buildScrobbler(callable $resolveTrack): LastfmScrobbler
    {
        return new LastfmScrobbler($this->api, $this->sessions, $resolveTrack);
    }
}

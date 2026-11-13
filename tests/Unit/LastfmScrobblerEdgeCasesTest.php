<?php

/**
 * Unit::LastfmScrobblerEdgeCasesTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmOutcome;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Plugins\Scrobbler\Lastfm\PlaybackTicks;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;

/**
 * Edge case tests for {@see LastfmScrobbler}.
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
#[CoversClass(\Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler::class)]
#[AllowMockObjectsWithoutExpectations]
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
     * Exercises the null-track guard in LastfmScrobbler::handlePlaybackStarted().
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
     * Exercises the null-track guard in LastfmScrobbler::handlePlaybackStopped().
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
            new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(10), true)
        );
    }

    /**
     * Test that onPlaybackStarted skips when session exists but track has no artist.
     * Exercises the null title/artist guard in LastfmScrobbler::handlePlaybackStarted().
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
     * The scrobble timestamp marks when LISTENING happened, measured from the
     * cached start offset (60s), not from zero: a stop at 170s watched 110s,
     * so the submitted timestamp sits at (now - 110).
     */
    public function testScrobbleTimestampSubtractsTheStartOffset(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('updateNowPlaying')->willReturn(LastfmOutcome::success());

        $capturedTimestamp = null;
        $this->api->expects(self::once())
            ->method('scrobble')
            ->willReturnCallback(function (string $sk, string $track, string $artist, ?string $album, ?int $timestamp) use (&$capturedTimestamp) {
                $capturedTimestamp = $timestamp;
                return LastfmOutcome::success();
            });

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $beforeTime = time();
        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(60)));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(170), false));
        $afterTime = time();

        $watchedSeconds = 110;
        $this->assertGreaterThanOrEqual($beforeTime - $watchedSeconds, $capturedTimestamp);
        $this->assertLessThanOrEqual($afterTime - $watchedSeconds, $capturedTimestamp);
    }

    /**
     * The false-positive the start-offset cure kills: a session resumed at
     * 75 % of a 200s track and stopped at the end advanced the playhead past
     * the half-way line after only 50 seconds of listening. The old proxy
     * scrobbled; the remembered offset must refuse.
     */
    public function testResumedSessionIsMeasuredByWatchedTimeNotPlayhead(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(150)));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(200), true));
    }

    /**
     * reachedEnd is deliberately NOT consulted: it states the host's
     * item-consumment verdict (>= 90 % watched across history), not this
     * session's listening time — treating it as a pass would re-admit the
     * resume-then-finish false scrobble above. Pinned by refusal despite
     * the flag being true.
     */
    public function testReachedEndDoesNotExemptTheWatchedTimeRule(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(190)));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(200), true));
    }

    /**
     * A stop event whose start the process never saw (worker restart, plugin
     * enabled mid-track) falls back to measuring from position zero — the
     * documented degradation, pinned so the fallback itself stays honest.
     */
    public function testStopWithoutStartFallsBackToZeroOffset(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::once())->method('scrobble')
            ->willReturn(LastfmOutcome::success());

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), false));
    }

    /**
     * The offset is remembered per (sessionId, mediaItemId) pair: a stop for
     * a DIFFERENT item must not inherit another item's start position, and
     * the tracked pair keeps its own (10s watched → refusal).
     */
    public function testStartOffsetIsKeyedBySessionAndItem(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('updateNowPlaying')->willReturn(LastfmOutcome::success());
        $this->api->expects(self::exactly(1))->method('scrobble')
            ->willReturn(LastfmOutcome::success());

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(new PlaybackStarted('sess-A', 'u1', 'media-X', 'd', PlaybackTicks::fromSeconds(150)));
        // Item Y was never started here → zero-offset fallback → 101s watched → scrobble.
        $scrobbler->onPlaybackStopped(new PlaybackStopped('sess-A', 'u1', 'media-Y', 'd', PlaybackTicks::fromSeconds(101), false));
        // Item X keeps its own offset → 10s watched → refusal.
        $scrobbler->onPlaybackStopped(new PlaybackStopped('sess-A', 'u1', 'media-X', 'd', PlaybackTicks::fromSeconds(160), false));
    }

    /**
     * A second stop for the same pair (the host can fire stopped more than
     * once per session, e.g. markAsWatched and a later clearProgress) keeps
     * measuring from the remembered start instead of silently reverting to
     * the zero fallback — the entry is retained, not consumed.
     */
    public function testRepeatedStopsKeepMeasuringFromTheRememberedStart(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('updateNowPlaying')->willReturn(LastfmOutcome::success());
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(150)));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(160), false));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(200), true));

        $this->assertCount(1, $this->readStartCache($scrobbler), 'the pair stays tracked for repeat stops');
    }

    /**
     * LFM-2: a repeated stop for the same (session, item) after a successful
     * scrobble submits nothing; a new start for the pair re-arms it.
     */
    public function testRepeatedStopAfterSuccessDoesNotScrobbleTwiceAndNewStartRearms(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('updateNowPlaying')->willReturn(LastfmOutcome::success());
        $this->api->expects(self::exactly(2))->method('scrobble')->willReturn(LastfmOutcome::success());

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', 0));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(150), false));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(200), true));
        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', 0));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(150), false));
    }

    /**
     * LFM-S2: a TRANSIENT live failure marks the pair as handled, enqueues
     * the submission exactly once, and a repeat stop for the pair neither
     * re-submits live nor duplicates the queue entry (the queued job is not
     * yet due — Retry-After 30s).
     */
    public function testTransientFailureMarksPairEnqueuesOnceAndRepeatStopDoesNotResubmit(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('updateNowPlaying')->willReturn(LastfmOutcome::success());
        $this->api->expects(self::once())->method('scrobble')
            ->willReturn(LastfmOutcome::failure(null, null, true, 30));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', 0));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(150), false));

        $this->assertCount(1, $this->readProperty($scrobbler, 'scrobbleRetryQueue'), 'enqueued once');
        $this->assertArrayHasKey('s|m', $this->readProperty($scrobbler, 'scrobbledPairs'), 'pair marked');

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(200), true));

        $this->assertCount(1, $this->readProperty($scrobbler, 'scrobbleRetryQueue'), 'repeat stop does not re-enqueue');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readProperty(LastfmScrobbler $scrobbler, string $name): array
    {
        /** @var array<array-key, mixed> $value */
        $value = (new \ReflectionProperty(LastfmScrobbler::class, $name))->getValue($scrobbler);
        return $value;
    }

    /**
     * A rewound stop (final position before the remembered start — client
     * quirk or clock weirdness) clamps to zero watched time instead of
     * producing a negative duration or a bogus back-dated timestamp.
     */
    public function testBackwardStopClampsToZeroWatchedTime(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('updateNowPlaying')->willReturn(LastfmOutcome::success());
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(100)));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(40), false));
    }

    /**
     * The start cache is bounded: once the ceiling is reached the oldest
     * remembered pair is evicted (FIFO), so a resident worker cannot grow
     * the map without limit across abandoned sessions. Pinned behaviorally:
     * after the eviction the first pair behaves like a stop-without-start
     * (zero fallback → scrobble) while a still-cached pair keeps its 150s
     * offset (50s watched → refusal).
     */
    public function testStartCacheIsBoundedAndEvictsOldest(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('updateNowPlaying')->willReturn(LastfmOutcome::success());
        $this->api->expects(self::once())->method('scrobble')
            ->willReturn(LastfmOutcome::success());

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $pairs = LastfmScrobbler::MAX_START_CACHE + 1;
        for ($i = 0; $i < $pairs; $i++) {
            $scrobbler->onPlaybackStarted(new PlaybackStarted('sess-' . $i, 'u1', 'm', 'd', PlaybackTicks::fromSeconds(150)));
        }
        $this->assertCount(LastfmScrobbler::MAX_START_CACHE, $this->readStartCache($scrobbler));

        $scrobbler->onPlaybackStopped(new PlaybackStopped('sess-0', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(200), true));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('sess-1', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(200), true));
    }

    /**
     * @return array<string, int>
     */
    private function readStartCache(LastfmScrobbler $scrobbler): array
    {
        /** @var array<string, int> $cache */
        $cache = (new \ReflectionProperty(LastfmScrobbler::class, 'startPositions'))->getValue($scrobbler);
        return $cache;
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

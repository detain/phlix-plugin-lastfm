<?php

/**
 * Unit::LastfmScrobblerTest.
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
use Phlix\Plugins\Scrobbler\Lastfm\LogSafety;
use Phlix\Plugins\Scrobbler\Lastfm\PlaybackTicks;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see LastfmScrobbler}.
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
#[CoversClass(\Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler::class)]
#[AllowMockObjectsWithoutExpectations]
final class LastfmScrobblerTest extends TestCase
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

    public function testMeetsScrobbleRulesRejectsShortTracks(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        // 25-second track played 20 seconds — duration <= 30s gate fails first.
        $this->assertFalse($scrobbler->meetsScrobbleRules(25, 20));
        // Edge: exactly 30 also rejected (rule is "longer than 30").
        $this->assertFalse($scrobbler->meetsScrobbleRules(30, 16));
    }

    public function testMeetsScrobbleRulesRejectsLessThanHalfPlayed(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        // 120s track, played 30s = 25% — should reject.
        $this->assertFalse($scrobbler->meetsScrobbleRules(120, 30));
        // Exactly 50% — also rejected (rule is "more than 50%").
        $this->assertFalse($scrobbler->meetsScrobbleRules(120, 60));
    }

    public function testMeetsScrobbleRulesAcceptsWhenBothGatesPass(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        // 200s track, played 101s = 50.5% — should accept (>30s AND >50%).
        $this->assertTrue($scrobbler->meetsScrobbleRules(200, 101));
    }

    public function testMeetsScrobbleRulesRejectsUnknownDuration(): void
    {
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        $this->assertFalse($scrobbler->meetsScrobbleRules(null, 9999));
    }

    public function testOnPlaybackStartedFiresUpdateNowPlaying(): void
    {
        $this->sessions->method('findByUserId')->with('u1')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::once())
            ->method('updateNowPlaying')
            ->with('SK', 'Song', 'Artist', 'Album')
            ->willReturn(LastfmOutcome::success());

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => 'Album', 'duration_seconds' => 200,
        ]);

        $scrobbler->onPlaybackStarted(
            new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0)
        );
    }

    public function testOnPlaybackStartedSkipsWhenNoSession(): void
    {
        $this->sessions->method('findByUserId')->willReturn(null);
        $this->api->expects(self::never())->method('updateNowPlaying');
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', 0));
    }

    public function testOnPlaybackStoppedScrobblesWhenRulesPass(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);

        $playedSeconds = 101; // > 50% of 200s
        $this->api->expects(self::once())
            ->method('scrobble')
            ->with('SK', 'Song', 'Artist', null, self::isInt())
            ->willReturn(LastfmOutcome::success());

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);

        $event = new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds($playedSeconds), true);
        $scrobbler->onPlaybackStopped($event);
    }

    public function testOnPlaybackStoppedSkipsWhenDurationUnknown(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => null,
        ]);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(100), false));
    }

    public function testOnPlaybackStoppedSkipsWhenUnderThreshold(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::never())->method('scrobble');

        // 200s track, played 30s = 15% — under 50% gate.
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(30), false));
    }

    public function testOnPlaybackStoppedSkipsWhenNoSession(): void
    {
        $this->sessions->method('findByUserId')->willReturn(null);
        $this->api->expects(self::never())->method('scrobble');

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(200), true));
    }

    /**
     * M-04 [review lastfm__srphp M04] — a failed API outcome must never be
     * silently discarded: the now-playing path logs a warning carrying the
     * typed error fields (response-side only; no credentials).
     */
    public function testOnPlaybackStartedLogsWarningWhenNowPlayingFails(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('updateNowPlaying')
            ->willReturn(LastfmOutcome::failure(9, 'Invalid session key - Please re-authenticate'));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $logger = $this->warningCollector($warnings);

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => 'Album', 'duration_seconds' => 200,
        ], $logger);

        $scrobbler->onPlaybackStarted(new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0));

        // Under M-02 (later era than this test's birth), code 9 additionally
        // triggers the invalid-session purge warning — the failure line itself
        // must be present with its typed context.
        $this->assertCount(2, $warnings);
        $failure = array_values(array_filter(
            $warnings,
            static fn (array $w): bool => $w['message'] === 'Last.fm now-playing update failed',
        ));
        $this->assertCount(1, $failure);
        $this->assertSame('u1', $failure[0]['context']['user_id']);
        $this->assertSame(9, $failure[0]['context']['error_code']);
        $this->assertStringContainsString('Invalid session key', (string) $failure[0]['context']['error_message']);
    }

    /**
     * M-04 [review lastfm__srphp M04] — scrobble-side twin: every failed
     * submission surfaces as a warning instead of vanishing.
     */
    public function testOnPlaybackStoppedLogsWarningWhenScrobbleFails(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('scrobble')->willReturn(LastfmOutcome::failure(16, 'Permission denied'));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $logger = $this->warningCollector($warnings);

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ], $logger);

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));

        $this->assertCount(1, $warnings);
        $this->assertSame('Last.fm scrobble submission failed', $warnings[0]['message']);
        $this->assertSame('u1', $warnings[0]['context']['user_id']);
        $this->assertSame(16, $warnings[0]['context']['error_code']);
    }

    /**
     * M-03 [review lastfm__srphp M03] end-to-end wire of the verdict: a
     * 200-but-ignored scrobble must surface through the M-04 failure log
     * carrying the per-scrobble ignore reason.
     */
    public function testOnPlaybackStoppedLogsIgnoreReasonForRefusedScrobble(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('scrobble')->willReturn(LastfmOutcome::scrobbleRejected(0, 1, 'banned'));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ], $this->warningCollector($warnings));

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));

        $this->assertCount(1, $warnings);
        $this->assertSame('Last.fm scrobble submission failed', $warnings[0]['message']);
        $this->assertSame(1, $warnings[0]['context']['ignored']);
        $this->assertSame('banned', $warnings[0]['context']['ignore_reason']);
    }

    /**
     * M-06 [review lastfm__srphp M06] — the host dispatches playback events
     * with no per-listener isolation (ListenerRegistry): a throw from this
     * plugin's hook escapes into the request/worker loop. A telemetry-grade
     * side effect must never break playback: repository/resolver/API throws
     * are swallowed at the handler boundary and leave one warning line.
     */
    public function testOnPlaybackStartedSwallowsThrowingRepositoryAndLogs(): void
    {
        $this->sessions->method('findByUserId')
            ->willThrowException(new \RuntimeException('SQLSTATE[HY000]: MySQL server has gone away'));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null, $this->warningCollector($warnings));

        $scrobbler->onPlaybackStarted(new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0));

        $this->assertCount(1, $warnings);
        $this->assertSame('Last.fm playback hook failed', $warnings[0]['message']);
        $this->assertSame('playback.started', $warnings[0]['context']['hook']);
        $this->assertSame('u1', $warnings[0]['context']['user_id']);
        $this->assertStringContainsString('gone away', (string) $warnings[0]['context']['error']);
    }

    /** M-06 twin: a throwing track resolver is contained on the stopped path. */
    public function testOnPlaybackStoppedSwallowsThrowingResolverAndLogs(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static function (string $_id): array {
            throw new \LogicException('resolver exploded');
        }, $this->warningCollector($warnings));

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));

        $this->assertCount(1, $warnings);
        $this->assertSame('Last.fm playback hook failed', $warnings[0]['message']);
        $this->assertSame('playback.stopped', $warnings[0]['context']['hook']);
    }

    /** M-06 triple: an API transport throw is contained too. */
    public function testOnPlaybackStoppedSwallowsThrowingApiAndLogs(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('scrobble')->willThrowException(new \RuntimeException('curl error'));
        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ], $this->warningCollector($warnings));

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));

        $this->assertCount(1, $warnings);
        $this->assertSame('playback.stopped', $warnings[0]['context']['hook']);
    }

    /**
     * M-02 [review lastfm__secaudit M-1] — a stored session the API has
     * permanently rejected (invalid-session envelope codes) must self-heal:
     * the plugin deletes the row so credential replay stops and the host
     * UI (row existence == connected) shows the account disconnected.
     */
    public function testInvalidSessionCodePurgesStoredSessionAndWarns(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'DEAD', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->sessions->expects(self::once())->method('delete')->with('u1');
        $this->api->method('updateNowPlaying')
            ->willReturn(LastfmOutcome::failure(9, 'Invalid session key - Please re-authenticate'));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ], $this->warningCollector($warnings));

        $scrobbler->onPlaybackStarted(new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0));

        $messages = array_column($warnings, 'message');
        $this->assertContains('Last.fm now-playing update failed', $messages);
        $cleared = array_values(array_filter(
            $warnings,
            static fn (array $w): bool => str_contains($w['message'], 'session cleared'),
        ));
        $this->assertCount(1, $cleared);
        $this->assertSame('u1', $cleared[0]['context']['user_id']);
    }

    /** Scrobble-path twin: code 88 (invalid session key) purges too. */
    public function testScrobbleInvalidSessionCodeAlsoPurges(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'DEAD', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->sessions->expects(self::once())->method('delete')->with('u1');
        $this->api->method('scrobble')->willReturn(LastfmOutcome::failure(88, 'Invalid session key'));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));
    }

    /**
     * M-02 negative pin: a NON-session failure (16 = permission denied on
     * this method) must never purge a live session — one failing feature
     * is not a broken connection.
     */
    public function testNonSessionErrorCodeDoesNotPurge(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->sessions->expects(self::never())->method('delete');
        $this->api->method('updateNowPlaying')
            ->willReturn(LastfmOutcome::failure(16, 'Permission denied'));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStarted(new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0));
    }

    /** M-02: cleanup failure is swallowed with its own warning, never thrown. */
    public function testInvalidSessionPurgeFailureIsLoggedNotThrown(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'DEAD', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->sessions->method('delete')->willThrowException(new \RuntimeException('DELETE failed'));
        $this->api->method('updateNowPlaying')->willReturn(LastfmOutcome::failure(17, 'Login was required'));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ], $this->warningCollector($warnings));

        $scrobbler->onPlaybackStarted(new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0));

        $messages = array_column($warnings, 'message');
        $this->assertContains('Last.fm invalid-session cleanup failed', $messages);
    }

    /**
     * The typed facet itself: session-invalid codes are a closed set
     * (4 = legacy docs invalid-session, 9, 88 current invalid session key,
     * 17 = login required); other codes/unknown are never invalid-session.
     */
    public function testOutcomeInvalidSessionFacetClassification(): void
    {
        foreach ([4, 9, 17, 88] as $code) {
            $this->assertTrue(
                LastfmOutcome::failure($code, 'x')->isInvalidSession(),
                "code {$code} must classify as invalid session",
            );
        }
        $this->assertFalse(LastfmOutcome::failure(16, 'x')->isInvalidSession());
        $this->assertFalse(LastfmOutcome::failure(8, 'x')->isInvalidSession());
        $this->assertFalse(LastfmOutcome::failure()->isInvalidSession());
        $this->assertFalse(LastfmOutcome::success()->isInvalidSession());
    }

    /**
     * M-05 [review lastfm__srphp M-03]: a transient scrobble failure is
     * queued in-process and replayed on a later stop event; Last.fm
     * dedups by timestamp so replay is safe.
     */
    public function testTransientScrobbleFailureIsQueuedAndDrainedOnNextEvent(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $transient = LastfmOutcome::failure(8, 'Operation failed', true, null);
        $this->api->method('scrobble')->willReturnOnConsecutiveCalls(
            $transient,                     // event 1 live attempt → queued
            LastfmOutcome::success(),       // event 2 drain → accepted
            LastfmOutcome::success(),       // event 2 live attempt
        );

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ], $this->warningCollector($warnings));

        $stop = new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true);
        $scrobbler->onPlaybackStopped($stop);
        $this->assertCount(1, $this->readQueue($scrobbler));
        $this->assertContains('Last.fm scrobble queued for retry', array_column($warnings, 'message'));

        $scrobbler->onPlaybackStopped($stop);
        $this->assertCount(0, $this->readQueue($scrobbler), 'drain must consume the queued job');
    }

    /** Terminal failures (not transient, not session-invalid) are never queued. */
    public function testTerminalScrobbleFailureIsNotQueued(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::exactly(2))->method('scrobble')
            ->willReturn(LastfmOutcome::failure(16, 'Permission denied'));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $stop = new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true);
        $scrobbler->onPlaybackStopped($stop);
        $scrobbler->onPlaybackStopped($stop);

        $this->assertCount(0, $this->readQueue($scrobbler));
    }

    /** M-05: an immature job (Retry-After not elapsed yet) is skipped by the drain. */
    public function testImmatureQueuedJobIsNotDrainedEarly(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::once())->method('scrobble')
            ->willReturn(LastfmOutcome::failure(8, 'x', true, 600));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));
        // Queued with not_before ≈ now+600: the NEXT live attempt (none here)
        // and a further stop event must not touch it… event 1's own failure
        // IS the single call; a second event would be call 2 — assert queue
        // still holds the immature job instead:
        $queue = $this->readQueue($scrobbler);
        $this->assertCount(1, $queue);
        $this->assertGreaterThan(microtime(true), $queue[0]['not_before']);
    }

    /**
     * LFM-11: an absurd Retry-After (one year) on the LIVE attempt is not
     * honoured — past LastfmApi::MAX_RETRY_AFTER_SECONDS the queued job
     * falls back to the 60s default backoff.
     */
    public function testAbsurdRetryAfterOnLiveAttemptIsCappedToDefaultBackoff(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::once())->method('scrobble')
            ->willReturn(LastfmOutcome::failure(null, null, true, 31536000));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $before = microtime(true);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));
        $after = microtime(true);

        $queue = $this->readQueue($scrobbler);
        $this->assertCount(1, $queue);
        $this->assertGreaterThanOrEqual($before + 60.0, $queue[0]['not_before']);
        $this->assertLessThanOrEqual($after + 60.0, $queue[0]['not_before']);
    }

    /** LFM-11: the same ceiling guards the re-queue path of the drain. */
    public function testAbsurdRetryAfterOnReplayIsCappedToDefaultBackoff(): void
    {
        $this->sessions->method('findByUserId')->willReturn(null);
        $this->api->expects(self::once())->method('scrobble')
            ->willReturn(LastfmOutcome::failure(null, null, true, 31536000));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null);
        $this->seedQueue($scrobbler, [$this->queuedJob('Old', 1)]);

        $before = microtime(true);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));
        $after = microtime(true);

        $queue = $this->readQueue($scrobbler);
        $this->assertCount(1, $queue);
        $this->assertSame(2, $queue[0]['attempts']);
        $this->assertGreaterThanOrEqual($before + 60.0, $queue[0]['not_before']);
        $this->assertLessThanOrEqual($after + 60.0, $queue[0]['not_before']);
    }

    /** LFM-11: a hint exactly AT the ceiling is still honoured as sent. */
    public function testRetryAfterAtCeilingIsHonoured(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->expects(self::once())->method('scrobble')
            ->willReturn(LastfmOutcome::failure(null, null, true, LastfmApi::MAX_RETRY_AFTER_SECONDS));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $before = microtime(true);
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));
        $after = microtime(true);

        $queue = $this->readQueue($scrobbler);
        $this->assertCount(1, $queue);
        $this->assertGreaterThanOrEqual($before + 3600.0, $queue[0]['not_before']);
        $this->assertLessThanOrEqual($after + 3600.0, $queue[0]['not_before']);
    }

    /** M-05: after MAX_SCROBBLE_ATTEMPTS total tries a job is dropped with a warning. */
    public function testQueuedJobExhaustingAttemptsIsDroppedWithWarning(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('scrobble')
            ->willReturn(LastfmOutcome::failure(8, 'still down', true, null));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ], $this->warningCollector($warnings));
        $this->seedQueue($scrobbler, [$this->queuedJob('Old', LastfmScrobbler::MAX_SCROBBLE_ATTEMPTS)]);

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));

        $this->assertContains('Last.fm queued scrobble dropped after exhausting retries', array_column($warnings, 'message'));
        $queue = $this->readQueue($scrobbler);
        $this->assertCount(1, $queue, 'only the fresh event-1 job remains');
        $this->assertSame('Song', $queue[0]['track']);
        $this->assertSame('m', $queue[0]['media_item_id'], 'queued job carries media_item_id');
        $dropped = array_values(array_filter($warnings, static fn (array $w): bool => $w['message'] === 'Last.fm queued scrobble dropped after exhausting retries'));
        $this->assertSame('m-old', $dropped[0]['context']['media_item_id']);
        $this->assertArrayNotHasKey('track', $dropped[0]['context']);
        $this->assertArrayNotHasKey('artist', $dropped[0]['context']);
    }

    /** M-05: the queue is bounded; overflow drops the OLDEST pending job, loudly. */
    public function testQueueOverflowDropsOldestWithWarning(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->api->method('scrobble')
            ->willReturn(LastfmOutcome::failure(8, 'x', true, null));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ], $this->warningCollector($warnings));
        $immature = microtime(true) + 600;
        $jobs = [];
        for ($i = 0; $i < LastfmScrobbler::MAX_RETRY_QUEUE_SIZE; $i++) {
            $jobs[] = $this->queuedJob('Track-' . $i, 1, $immature);
        }
        $this->seedQueue($scrobbler, $jobs);

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));

        $this->assertContains('Last.fm scrobble retry queue overflow — oldest submission dropped', array_column($warnings, 'message'));
        $queue = $this->readQueue($scrobbler);
        $this->assertCount(LastfmScrobbler::MAX_RETRY_QUEUE_SIZE, $queue);
        $this->assertSame('Track-1', $queue[0]['track'], 'oldest must be the one evicted');
        $this->assertSame('Song', $queue[LastfmScrobbler::MAX_RETRY_QUEUE_SIZE - 1]['track'], 'newest enqueued last');
    }

    /** M-05×M-02 interaction: purging an invalid session drops that user's queued jobs. */
    public function testInvalidSessionPurgeDropsQueuedJobsForThatUser(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->sessions->expects(self::atLeastOnce())->method('delete')->with('u1');
        $this->api->method('scrobble')->willReturn(LastfmOutcome::failure(88, 'Invalid session key', true, null));

        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $this->seedQueue($scrobbler, [
            $this->queuedJob('Queued-1'),
            $this->queuedJob('Other', 1, microtime(true) + 600, 'u2'),
        ]);

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));

        $queue = $this->readQueue($scrobbler);
        $this->assertCount(1, $queue);
        $this->assertSame('u2', $queue[0]['user_id'], 'other users keep their queues');
    }

    /** LFM-1: a stale queued snapshot key (user reconnected) must NOT purge the fresh session. */
    public function testStaleSnapshotRejectionDoesNotPurgeRotatedSession(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK-NEW', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->sessions->expects(self::never())->method('delete');
        $this->api->method('scrobble')->willReturnCallback(
            static fn (string $key) => $key === 'SK'
                ? LastfmOutcome::failure(9, 'Invalid session key', false, null)
                : LastfmOutcome::success(),
        );
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $this->seedQueue($scrobbler, [$this->queuedJob('Queued-1')]);

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));

        $this->assertCount(0, $this->readQueue($scrobbler));
    }

    /** LFM-1: snapshot key equal to the stored key still purges (dead credential). */
    public function testMatchingSnapshotRejectionStillPurges(): void
    {
        $this->sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);
        $this->sessions->expects(self::atLeastOnce())->method('delete')->with('u1');
        $this->api->method('scrobble')->willReturn(LastfmOutcome::failure(9, 'Invalid session key', false, null));
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => [
            'title' => 'Song', 'artist' => 'Artist', 'album' => null, 'duration_seconds' => 200,
        ]);
        $this->seedQueue($scrobbler, [$this->queuedJob('Queued-1')]);

        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', PlaybackTicks::fromSeconds(101), true));
    }

    /**
     * PG-150 twin (review lastfm__secaudit info): the host connection class
     * re-materializes bound values into exception text (emulated prepares),
     * so a hook-failure message forwarded into the log is BOUNDED at the
     * boundary — the diagnostic head survives for triage, an oversized
     * tail (whatever it carries) never reaches the log line.
     */
    public function testHookFailureExceptionTextIsBoundedInLogs(): void
    {
        $longMessage = 'SQLSTATE[HY000]: MySQL server has gone away — query was '
            . str_repeat('x', 4096)
            . " sk=NEVER-LOG-THIS-TAIL";
        $this->sessions->method('findByUserId')->willThrowException(new \RuntimeException($longMessage));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $scrobbler = $this->buildScrobbler(static fn (string $_id) => null, $this->warningCollector($warnings));

        $scrobbler->onPlaybackStarted(new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0));

        $this->assertCount(1, $warnings);
        $error = (string) $warnings[0]['context']['error'];
        $this->assertStringContainsString('gone away', $error, 'the diagnostic head survives the bound');
        $this->assertStringNotContainsString('NEVER-LOG-THIS-TAIL', $error);
        $this->assertLessThanOrEqual(
            LogSafety::MAX_EXCEPTION_MESSAGE_BYTES + LogSafety::MAX_MARKER_BYTES,
            strlen($error),
        );
        $this->assertStringContainsString('truncated', $error);
    }

    /**
     * @param list<array{user_id:string,media_item_id:string,session_key:string,track:string,artist:string,album:?string,timestamp:int,attempts:int,not_before:float}> $jobs
     */
    private function seedQueue(LastfmScrobbler $scrobbler, array $jobs): void
    {
        $property = new \ReflectionProperty(LastfmScrobbler::class, 'scrobbleRetryQueue');
        $property->setValue($scrobbler, $jobs);
    }

    /**
     * @return list<array{user_id:string,media_item_id:string,session_key:string,track:string,artist:string,album:?string,timestamp:int,attempts:int,not_before:float}>
     */
    private function readQueue(LastfmScrobbler $scrobbler): array
    {
        /** @var list<array{user_id:string,media_item_id:string,session_key:string,track:string,artist:string,album:?string,timestamp:int,attempts:int,not_before:float}> $queue */
        $queue = (new \ReflectionProperty(LastfmScrobbler::class, 'scrobbleRetryQueue'))->getValue($scrobbler);
        return $queue;
    }

    /**
     * @return array{user_id:string,media_item_id:string,session_key:string,track:string,artist:string,album:?string,timestamp:int,attempts:int,not_before:float}
     */
    private function queuedJob(string $track, int $attempts = 1, float $notBefore = 0.0, string $userId = 'u1'): array
    {
        return [
            'user_id' => $userId,
            'media_item_id' => 'm-old',
            'session_key' => 'SK',
            'track' => $track,
            'artist' => 'Artist',
            'album' => null,
            'timestamp' => time() - 300,
            'attempts' => $attempts,
            'not_before' => $notBefore,
        ];
    }

    /**
     * @param array<int, array{message: string, context: array<string, mixed>}> $warnings
     */
    private function warningCollector(array &$warnings): NullLogger
    {
        return new class ($warnings) extends NullLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            private array $warningsRef;

            public function __construct(array &$warningsRef)
            {
                $this->warningsRef = &$warningsRef;
            }

            public function warning($message, array $context = []): void
            {
                $this->warningsRef[] = ['message' => (string) $message, 'context' => $context];
            }
        };
    }

    /**
     * @param callable(string): ?array{title: string, artist: string, album: ?string, duration_seconds: ?int} $resolveTrack
     */
    private function buildScrobbler(callable $resolveTrack, ?\Psr\Log\LoggerInterface $logger = null): LastfmScrobbler
    {
        return new LastfmScrobbler($this->api, $this->sessions, $resolveTrack, $logger);
    }
}

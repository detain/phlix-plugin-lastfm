<?php

/**
 * LastfmScrobbler.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PSR-14 listener that fans Phlix playback events out to Last.fm.
 *
 * On `phlix.playback.started` the listener calls
 * {@see LastfmApi::updateNowPlaying()} so the user's Last.fm profile shows
 * what they are listening to in real time.
 *
 * On `phlix.playback.stopped` the listener applies the official Last.fm
 * scrobble rules:
 *
 *  - the track must be longer than 30 seconds, AND
 *  - the user must have listened to more than 50 % of it.
 *
 * Only when both gates pass is {@see LastfmApi::scrobble()} invoked.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
class LastfmScrobbler
{
    /** Minimum track duration (seconds) before a scrobble is eligible. */
    public const MIN_DURATION_SECONDS = 30;

    /** Minimum fraction of the track that must be played. */
    public const MIN_PLAYED_FRACTION = 0.5;

    /** Conversion factor: 100-ns ticks → seconds. */
    private const TICKS_PER_SECOND = 10_000_000;

    private LoggerInterface $logger;

    /**
     * Track metadata resolver — the host wires this with an
     * `ItemRepository`-backed callable that takes a media-item UUID and
     * returns the lookup data the scrobbler needs.
     *
     * @var (callable(string $mediaItemId): ?array{
     *     title: string|null, artist: string|null, album: ?string, duration_seconds: ?int
     * })
     */
    private $resolveTrack;

    /**
     * Deferred, idempotent schema-ensure hook — runs the plugin's DB
     * migrations lazily on **first actual event**, NOT at boot/enable.
     *
     * This is the boot-safety split: {@see LastfmPlugin::onEnable()} does
     * zero I/O (no migrations, no queries, no network); the first playback
     * event that reaches this scrobbler triggers `CREATE TABLE IF NOT
     * EXISTS` exactly once per instance via {@see self::ensureSchema()}.
     *
     * @var (callable(): void)|null
     */
    private $ensureSchema;

    /** One-shot guard so the deferred schema-ensure runs at most once. */
    private bool $schemaEnsured = false;

    /**
     * @param LastfmApi               $api          Last.fm HTTP client.
     * @param LastfmSessionRepository $sessions     Per-user session-key store.
     * @param callable(string): ?array{
     *     title: string|null, artist: string|null, album: ?string, duration_seconds: ?int
     * } $resolveTrack Resolver that maps a `mediaItemId` to track metadata.
     * @param LoggerInterface|null    $logger       Optional PSR-3 logger.
     * @param (callable(): void)|null $ensureSchema Deferred, idempotent
     *        schema-ensure hook invoked lazily on the first event (see
     *        {@see self::$ensureSchema}). Null = schema managed elsewhere.
     */
    public function __construct(
        private readonly LastfmApi $api,
        private readonly LastfmSessionRepository $sessions,
        callable $resolveTrack,
        ?LoggerInterface $logger = null,
        ?callable $ensureSchema = null,
    ) {
        $this->resolveTrack = $resolveTrack;
        $this->logger = $logger ?? new NullLogger();
        $this->ensureSchema = $ensureSchema;
    }

    /**
     * Run the deferred schema-ensure hook exactly once, on first use.
     *
     * Called at the top of each event handler BEFORE any DB access. The
     * one-shot guard ({@see self::$schemaEnsured}) makes repeated calls a
     * no-op, so a busy worker triggers the (idempotent) migration only on
     * the first playback event it processes — never at boot, never per
     * event.
     */
    private function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $this->schemaEnsured = true;
        if ($this->ensureSchema !== null) {
            ($this->ensureSchema)();
        }
    }

    /**
     * Handle `phlix.playback.started` — sends Now Playing if the user has
     * a Last.fm session.
     *
     * M-06 [review lastfm__srphp M06]: the entire body runs inside a
     * `Throwable` boundary. The host event dispatcher (ListenerRegistry)
     * invokes each listener WITHOUT isolation, so an escaped throw here —
     * a dropped MySQL connection under workerman's persistent connections,
     * a resolver explosion, a transport failure — would break the user's
     * playback request over a fire-and-forget telemetry side effect. All
     * failures degrade to one warning line and the event is dropped.
     */
    public function onPlaybackStarted(PlaybackStarted $event): void
    {
        try {
            $this->handlePlaybackStarted($event);
        } catch (\Throwable $t) {
            $this->logHookFailure('playback.started', $event->userId, $t);
        }
    }

    private function handlePlaybackStarted(PlaybackStarted $event): void
    {
        $this->ensureSchema();
        $session = $this->sessions->findByUserId($event->userId);
        if ($session === null) {
            return;
        }
        $track = ($this->resolveTrack)($event->mediaItemId);
        if ($track === null) {
            return;
        }
        $title = is_string($track['title'] ?? null) ? $track['title'] : null;
        $artist = is_string($track['artist'] ?? null) ? $track['artist'] : null;
        if ($title === null || $artist === null) {
            return;
        }
        $outcome = $this->api->updateNowPlaying(
            $session['session_key'],
            $title,
            $artist,
            is_string($track['album'] ?? null) ? $track['album'] : null,
        );
        // M-04 [review lastfm__srphp M04]: the bool verdict used to be
        // discarded — a rejected now-playing update vanished without a
        // trace. Every failure now leaves a log line (typed outcome
        // context is response-side only: no credentials).
        if (!$outcome->success) {
            $this->logger->warning('Last.fm now-playing update failed', $outcome->logContext() + [
                'user_id' => $event->userId,
            ]);
            $this->purgeSessionIfInvalid($outcome, $event->userId);
        }
    }

    /**
     * Handle `phlix.playback.stopped` — scrobbles when Last.fm's official
     * `>30s AND >50%` rule is satisfied.
     *
     * The rule is enforced here (the underlying {@see LastfmApi::scrobble()}
     * does no gating of its own).
     *
     * M-06 [review lastfm__srphp M06] twin of the started path: full
     * `Throwable` containment — a scrobble failure must never surface in
     * the user's stop-request flow.
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        try {
            $this->handlePlaybackStopped($event);
        } catch (\Throwable $t) {
            $this->logHookFailure('playback.stopped', $event->userId, $t);
        }
    }

    /**
     * Log one containment warning for a failed playback hook.
     *
     * Context carries only exception class/message + user UUID (the
     * session key is request-side state and is deliberately absent;
     * DB exception messages contain no credentials).
     */
    private function logHookFailure(string $hook, string $userId, \Throwable $t): void
    {
        $this->logger->warning('Last.fm playback hook failed', [
            'hook'      => $hook,
            'user_id'   => $userId,
            'exception' => get_class($t),
            'error'     => $t->getMessage(),
        ]);
    }

    private function handlePlaybackStopped(PlaybackStopped $event): void
    {
        $this->ensureSchema();
        $session = $this->sessions->findByUserId($event->userId);
        if ($session === null) {
            return;
        }

        $track = ($this->resolveTrack)($event->mediaItemId);
        if ($track === null) {
            return;
        }
        $title = is_string($track['title'] ?? null) ? $track['title'] : null;
        $artist = is_string($track['artist'] ?? null) ? $track['artist'] : null;
        if ($title === null || $artist === null) {
            return;
        }

        $duration = is_int($track['duration_seconds'] ?? null) ? $track['duration_seconds'] : null;
        $playedSeconds = (int) ($event->finalPositionTicks / self::TICKS_PER_SECOND);

        if (!$this->meetsScrobbleRules($duration, $playedSeconds)) {
            $this->logger->debug('Last.fm scrobble skipped: rule not satisfied', [
                'media_item_id'        => $event->mediaItemId,
                'duration_seconds'     => $duration,
                'played_seconds'       => $playedSeconds,
                'min_duration_seconds' => self::MIN_DURATION_SECONDS,
                'min_played_fraction'  => self::MIN_PLAYED_FRACTION,
            ]);
            return;
        }

        $outcome = $this->api->scrobble(
            $session['session_key'],
            $title,
            $artist,
            is_string($track['album'] ?? null) ? $track['album'] : null,
            time() - $playedSeconds,
        );
        // M-04 [review lastfm__srphp M04] twin of the now-playing path:
        // failed submissions are logged, never dropped.
        if (!$outcome->success) {
            $this->logger->warning('Last.fm scrobble submission failed', $outcome->logContext() + [
                'user_id' => $event->userId,
            ]);
            $this->purgeSessionIfInvalid($outcome, $event->userId);
        }
    }

    /**
     * Self-heal a permanently rejected session [review lastfm__secaudit M-1].
     *
     * When the API answers with an invalid-session envelope code, the
     * stored `session_key` will fail on EVERY future event: the plugin
     * keeps replaying a dead credential (log spam, rate-limit pressure)
     * while the host UI still shows the account connected. Deleting the
     * row stops the replay and flips the UI to disconnected (existence ==
     * connected is the host's read law); the user re-authenticates through
     * the normal OAuth flow. Cleanup failure is contained locally with its
     * own warning — it must never replace the diagnostic that already
     * fired, and never throws into the (M-06) boundary.
     */
    private function purgeSessionIfInvalid(LastfmOutcome $outcome, string $userId): void
    {
        if (!$outcome->isInvalidSession()) {
            return;
        }
        try {
            $this->sessions->delete($userId);
            $this->logger->warning('Last.fm session cleared — the API rejected the stored session key; reconnect required', [
                'user_id'    => $userId,
                'error_code' => $outcome->errorCode,
            ]);
        } catch (\Throwable $t) {
            $this->logger->warning('Last.fm invalid-session cleanup failed', [
                'user_id'   => $userId,
                'exception' => get_class($t),
                'error'     => $t->getMessage(),
            ]);
        }
    }

    /**
     * Apply Last.fm's official rule: the track must be longer than 30
     * seconds AND the user must have listened to more than 50 % of it.
     *
     * Returns false when the track duration is unknown — we are
     * deliberately conservative because mis-counted scrobbles are worse
     * than missing ones.
     *
     * @param int|null $durationSeconds Track duration in seconds, or null when unknown.
     * @param int      $playedSeconds   Seconds of audio actually played.
     */
    public function meetsScrobbleRules(?int $durationSeconds, int $playedSeconds): bool
    {
        if ($durationSeconds === null || $durationSeconds <= self::MIN_DURATION_SECONDS) {
            return false;
        }
        $fractionPlayed = $playedSeconds / $durationSeconds;
        return $fractionPlayed > self::MIN_PLAYED_FRACTION;
    }
}

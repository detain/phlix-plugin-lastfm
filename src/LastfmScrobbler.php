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
 * "Listened" is MEASURED, not assumed: the initial `positionTicks` each
 * `phlix.playback.started` carries is remembered per (sessionId,
 * mediaItemId) and subtracted from the stop event's final position, so a
 * session resumed at 75 % is charged the quarter it actually played. Two
 * approximations remain, both deliberate: within one remembered session a
 * scrub forward still counts as listening (the event contract carries no
 * progress stream to do better), and a stop whose start this process never
 * saw (worker restart, plugin enabled mid-track) measures from position
 * zero. `reachedEnd` is deliberately NOT consulted: it states the host's
 * item-consumption verdict (>= 90 % across history), not this session's
 * listening time — passing on it would re-admit exactly the
 * resume-then-finish false scrobble the rule exists to refuse.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 * @since 1.1.0
 */
class LastfmScrobbler
{
    /** Minimum track duration (seconds) before a scrobble is eligible. */
    public const MIN_DURATION_SECONDS = 30;

    /** Minimum fraction of the track that must be played. */
    public const MIN_PLAYED_FRACTION = 0.5;

    /**
     * Ceiling of the in-process start-offset cache. Abandoned pairs (started,
     * never stopped) are evicted FIFO past the ceiling — a resident worker's
     * map stays bounded at a few tens of kilobytes.
     */
    public const MAX_START_CACHE = 256;

    /**
     * Where each playback session STARTED, keyed `sessionId|mediaItemId`,
     * valued the initial `positionTicks` of {@see PlaybackStarted}.
     * Process-local by design (a restart loses it → the documented zero
     * fallback). A repeat {@see PlaybackStopped} for the same pair keeps
     * measuring from it — the host can end a session more than once
     * (markAsWatched, a later clearProgress) — and a new start overwrites.
     *
     * @var array<string, int>
     */
    private array $startPositions = [];

    /**
     * Bound of the in-process transient scrobble retry queue. Overflow
     * drops the OLDEST pending submission (with a warning) — a bounded
     * memory ceiling beats unbounded growth during a long Last.fm outage.
     */
    public const MAX_RETRY_QUEUE_SIZE = 50;

    /**
     * Total attempts per scrobble (the live one that queued it plus
     * replays). Scrobble timestamps are explicit and Last.fm dedups, so
     * late replay is safe; after the cap the job is dropped with a warning.
     */
    public const MAX_SCROBBLE_ATTEMPTS = 3;

    /** Replay budget per stop event — keeps one event from blocking the worker. */
    private const MAX_DRAIN_PER_EVENT = 5;

    /**
     * Backoff applied to a re-queued job when the server sent no
     * Retry-After, and to ANY queued job whose Retry-After exceeds
     * {@see LastfmApi::MAX_RETRY_AFTER_SECONDS}.
     */
    private const DEFAULT_RETRY_BACKOFF_SECONDS = 60.0;

    /**
     * Pending scrobbles whose live attempt hit a TRANSIENT failure.
     * Process-local by design: a restart loses the queue (each eventual
     * drop is at least loud in logs; durable offline queues are a
     * host-level concern, out of plugin scope).
     *
     * @var list<array{user_id:string,session_key:string,track:string,artist:string,album:?string,timestamp:int,attempts:int,not_before:float}>
     */
    private array $scrobbleRetryQueue = [];

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
     * The entire body runs inside a
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
        // Recorded BEFORE any gate: the offset matters at stop time even for
        // a user who connects Last.fm (or resolves metadata) only later.
        $this->recordStartOffset($event);
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
        // The bool verdict used to be
        // discarded — a rejected now-playing update vanished without a
        // trace. Every failure now leaves a log line (typed outcome
        // context is response-side only: no credentials).
        if (!$outcome->success) {
            $this->logger->warning('Last.fm now-playing update failed', $outcome->logContext() + [
                'user_id' => $event->userId,
            ]);
            $this->purgeSessionIfInvalid($outcome, $event->userId);
            // Note: transient now-playing failures are deliberately NOT
            // retried — "now playing" is ephemeral state that the next
            // event supersedes; replaying stale status would be a lie.
        }
    }

    /**
     * Remember where a session started (see {@see self::$startPositions}).
     *
     * A negative client offset is clamped at the source: subtracting a
     * negative would inflate the listened time measured at stop.
     */
    private function recordStartOffset(PlaybackStarted $event): void
    {
        $key = $event->sessionId . '|' . $event->mediaItemId;
        $this->startPositions[$key] = max(0, $event->positionTicks);

        while (count($this->startPositions) > self::MAX_START_CACHE) {
            array_shift($this->startPositions);
        }
    }

    /**
     * Handle `phlix.playback.stopped` — scrobbles when Last.fm's official
     * `>30s AND >50%` rule is satisfied.
     *
     * The rule is enforced here (the underlying {@see LastfmApi::scrobble()}
     * does no gating of its own).
     *
     * Twin of the started path: full `Throwable` containment — a scrobble
     * failure must never surface in
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
     * session key is request-side state and is deliberately absent; the
     * message is bounded at the boundary — {@see LogSafety::boundedMessage}
     * — because the host connection's emulated prepares can re-materialize
     * bound values into SQL exception text).
     */
    private function logHookFailure(string $hook, string $userId, \Throwable $t): void
    {
        $this->logger->warning('Last.fm playback hook failed', [
            'hook'      => $hook,
            'user_id'   => $userId,
            'exception' => get_class($t),
            'error'     => LogSafety::boundedMessage($t),
        ]);
    }

    private function handlePlaybackStopped(PlaybackStopped $event): void
    {
        $this->ensureSchema();
        // Replay due transients first — the queue rides on real stop
        // events, so no timer/worker is needed (scrobbles carry explicit
        // timestamps; Last.fm dedups, late arrival is spec-legal).
        $this->drainRetryQueue();
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
        // Listened time, not playhead position: subtract where this session
        // started. An unknown start (never recorded here) is the documented
        // zero-offset fallback.
        $startTicks = $this->startPositions[$event->sessionId . '|' . $event->mediaItemId] ?? 0;
        $playedSeconds = PlaybackTicks::toSeconds(max(0, $event->finalPositionTicks - $startTicks));

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

        // Last.fm wants the listen-start moment: anchor it to the listened
        // seconds, not to the playhead position.
        $startedAt = time() - $playedSeconds;
        $outcome = $this->api->scrobble(
            $session['session_key'],
            $title,
            $artist,
            is_string($track['album'] ?? null) ? $track['album'] : null,
            $startedAt,
        );
        // Twin of the now-playing path: failed submissions are logged,
        // never dropped.
        if ($outcome->success) {
            return;
        }
        $this->logger->warning('Last.fm scrobble submission failed', $outcome->logContext() + [
            'user_id' => $event->userId,
        ]);
        if ($outcome->isInvalidSession()) {
            $this->purgeSessionIfInvalid($outcome, $event->userId);
            return;
        }
        // A transient failure is NOT the end of the story — queue the
        // submission for replay (attempt 1 spent).
        if ($outcome->transient) {
            $this->enqueueScrobble([
                'user_id'     => $event->userId,
                'session_key' => $session['session_key'],
                'track'       => $title,
                'artist'      => $artist,
                'album'       => is_string($track['album'] ?? null) ? $track['album'] : null,
                'timestamp'   => $startedAt,
                'attempts'    => 1,
                'not_before'  => microtime(true) + self::retryBackoffSeconds($outcome->retryAfterSeconds, 0.0),
            ]);
            $this->logger->warning('Last.fm scrobble queued for retry', [
                'user_id'            => $event->userId,
                'attempt'            => 1,
                'retry_after_seconds' => $outcome->retryAfterSeconds,
                'queue_size'         => count($this->scrobbleRetryQueue),
            ]);
        }
    }

    /**
     * Seconds to wait before replaying a queued scrobble.
     *
     * An absent hint takes the call site's `$whenAbsent` (0 for the first
     * queueing — replay on the next drain — and the default backoff for a
     * re-queue). A hint above {@see LastfmApi::MAX_RETRY_AFTER_SECONDS}
     * is not trusted: it falls back to
     * {@see self::DEFAULT_RETRY_BACKOFF_SECONDS} instead of parking the
     * job for as long as the server claims.
     */
    private static function retryBackoffSeconds(?int $retryAfterSeconds, float $whenAbsent): float
    {
        if ($retryAfterSeconds === null) {
            return $whenAbsent;
        }
        if ($retryAfterSeconds > LastfmApi::MAX_RETRY_AFTER_SECONDS) {
            return self::DEFAULT_RETRY_BACKOFF_SECONDS;
        }
        return (float) $retryAfterSeconds;
    }

    /**
     * Replay due entries of the transient retry queue.
     *
     * Budgeted ({@see self::MAX_DRAIN_PER_EVENT} per event) so a long
     * outage cannot turn one stop event into a blocking burst; immature
     * jobs (Retry-After not elapsed) stay put. Runs inside the
     * `Throwable` containment boundary of the stop handler.
     */
    private function drainRetryQueue(): void
    {
        if ($this->scrobbleRetryQueue === []) {
            return;
        }
        $pending = $this->scrobbleRetryQueue;
        $this->scrobbleRetryQueue = [];
        $budget = self::MAX_DRAIN_PER_EVENT;
        $now = microtime(true);

        foreach ($pending as $job) {
            if ($budget <= 0 || $job['not_before'] > $now) {
                $this->scrobbleRetryQueue[] = $job;
                continue;
            }
            $budget--;
            $outcome = $this->api->scrobble(
                $job['session_key'],
                $job['track'],
                $job['artist'],
                $job['album'],
                $job['timestamp'],
            );
            if ($outcome->success) {
                continue;
            }
            $this->logger->warning('Last.fm queued scrobble attempt failed', $outcome->logContext() + [
                'user_id'  => $job['user_id'],
                'attempts' => $job['attempts'],
            ]);
            if ($outcome->isInvalidSession()) {
                $this->purgeSessionIfInvalid($outcome, $job['user_id']);
                continue;
            }
            if ($outcome->transient && $job['attempts'] < self::MAX_SCROBBLE_ATTEMPTS) {
                $job['attempts']++;
                $job['not_before'] = microtime(true)
                    + self::retryBackoffSeconds($outcome->retryAfterSeconds, self::DEFAULT_RETRY_BACKOFF_SECONDS);
                $this->enqueueScrobble($job);
                continue;
            }
            $this->logger->warning('Last.fm queued scrobble dropped after exhausting retries', [
                'user_id'       => $job['user_id'],
                'attempts'      => $job['attempts'],
                'track'         => $job['track'],
                'artist'        => $job['artist'],
                'max_attempts'  => self::MAX_SCROBBLE_ATTEMPTS,
            ]);
        }
    }

    /**
     * Append to the bounded queue, evicting the oldest entry (loudly) past
     * the ceiling.
     *
     * @param array{user_id:string,session_key:string,track:string,artist:string,album:?string,timestamp:int,attempts:int,not_before:float} $job
     */
    private function enqueueScrobble(array $job): void
    {
        $this->scrobbleRetryQueue[] = $job;
        while (count($this->scrobbleRetryQueue) > self::MAX_RETRY_QUEUE_SIZE) {
            array_shift($this->scrobbleRetryQueue);
            $this->logger->warning('Last.fm scrobble retry queue overflow — oldest submission dropped', [
                'max_queue_size' => self::MAX_RETRY_QUEUE_SIZE,
            ]);
        }
    }

    /**
     * A dead credential's pending replays are dead too: drop the user's
     * queued jobs when their session is purged.
     */
    private function dropQueuedForUser(string $userId): void
    {
        $this->scrobbleRetryQueue = array_values(array_filter(
            $this->scrobbleRetryQueue,
            static fn (array $job): bool => $job['user_id'] !== $userId,
        ));
    }

    /**
     * Self-heal a permanently rejected session.
     *
     * When the API answers with an invalid-session envelope code, the
     * stored `session_key` will fail on EVERY future event: the plugin
     * keeps replaying a dead credential (log spam, rate-limit pressure)
     * while the host UI still shows the account connected. Deleting the
     * row stops the replay and flips the UI to disconnected (existence ==
     * connected is the host's read law); the user re-authenticates through
     * the normal OAuth flow. Cleanup failure is contained locally with its
     * own warning — it must never replace the diagnostic that already
     * fired, and never throws into the `Throwable` boundary.
     */
    private function purgeSessionIfInvalid(LastfmOutcome $outcome, string $userId): void
    {
        if (!$outcome->isInvalidSession()) {
            return;
        }
        try {
            $this->sessions->delete($userId);
            $this->dropQueuedForUser($userId);
            $this->logger->warning('Last.fm session cleared — the API rejected the stored session key; reconnect required', [
                'user_id'    => $userId,
                'error_code' => $outcome->errorCode,
            ]);
        } catch (\Throwable $t) {
            $this->logger->warning('Last.fm invalid-session cleanup failed', [
                'user_id'   => $userId,
                'exception' => get_class($t),
                'error'     => LogSafety::boundedMessage($t),
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

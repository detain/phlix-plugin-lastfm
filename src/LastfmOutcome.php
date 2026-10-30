<?php

/**
 * LastfmOutcome.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

/**
 * Typed verdict of one Last.fm write call (`track.updateNowPlaying` /
 * `track.scrobble`), parsed once at the API boundary so callers consume
 * structured data instead of a bare boolean.
 *
 * Last.fm signals failures as an HTTP 200 whose body carries an error
 * envelope (`{"error":N,"message":"..."}`); the previous bool surface
 * collapsed "rejected with a reason" and "transport never answered" into
 * the same indistinguishable `false`, and callers discarded it entirely
 * [review lastfm__srphp M-04]. The outcome keeps the reason (response-side
 * fields only — Last.fm error envelopes never carry request credentials, so
 * {@see self::logContext()} is safe to hand to a logger).
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 * @since 1.2.1
 */
final class LastfmOutcome
{
    /**
     * Last.fm envelope codes that mean the STORED SESSION is permanently
     * rejected (re-authentication required): 4 = invalid session key per
     * the legacy API docs, 9 = invalid session key / re-authenticate,
     * 17 = login was required, 88 = invalid session key per the current
     * docs. Everything else (permission, throttling, item availability)
     * says nothing about session liveness. The typed classification
     * replaces raw code branching in callers [review lastfm__secaudit M-1].
     *
     * @var list<int>
     */
    public const INVALID_SESSION_ERROR_CODES = [4, 9, 17, 88];

    /**
     * @param bool        $success      True only when Last.fm accepted the call outright.
     * @param int|null    $errorCode    Envelope `error` code when the API answered with one.
     * @param string|null $errorMessage Envelope `message` when present.
     * @param int|null    $accepted     Scrobble verdict: `@attr` accepted count
     *                                  (null = not reported — shape variance kept permissive).
     * @param int|null    $ignored      Scrobble verdict: `@attr` ignored count (null = not reported).
     * @param string|null $ignoreReason Per-scrobble ignore reason (banned/duplicate/…) when reported.
     * @param bool        $transient    M-05: the failure is retryable (transport drop,
     *                                  429/>=500, or envelope rate-limit 29) rather than terminal.
     * @param int|null    $retryAfterSeconds Seconds the server asked us to wait (Retry-After
     *                                  header, seconds form), when it said so.
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?int $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $accepted = null,
        public readonly ?int $ignored = null,
        public readonly ?string $ignoreReason = null,
        public readonly bool $transient = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
    }

    /** The call was accepted (2xx, no top-level error envelope). */
    public static function success(): self
    {
        return new self(true);
    }

    /**
     * The call was not accepted — by envelope code (when the API answered)
     * or silently (transport failure / non-2xx / unparseable body).
     *
     * `$transient`/`$retryAfterSeconds` carry the M-05 retry verdict so
     * callers branch on the typed facet instead of re-reading codes; the
     * defaults keep every pre-M-05 call site (and terminal failure) honest.
     */
    public static function failure(
        ?int $errorCode = null,
        ?string $errorMessage = null,
        bool $transient = false,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self(false, $errorCode, $errorMessage, null, null, null, $transient, $retryAfterSeconds);
    }

    /**
     * Scrobble whose recorded `@attr` verdict reports full acceptance.
     *
     * @since M-03 [review lastfm__srphp M03]
     */
    public static function scrobbleAccepted(int $accepted): self
    {
        return new self(true, null, null, $accepted, 0);
    }

    /**
     * Scrobble answered 200 with no top-level error whose `@attr` reports
     * ignored > 0 — Last.fm REFUSED the submission (banned channel,
     * timestamp rules, quality filter). Reporting this as success left
     * users with unexplained holes in their history [review lastfm__srphp M03].
     *
     * @since M-03 [review lastfm__srphp M03]
     */
    public static function scrobbleRejected(int $accepted, int $ignored, ?string $ignoreReason): self
    {
        return new self(
            false,
            null,
            'Last.fm ignored ' . $ignored . ' of ' . ($accepted + $ignored) . ' scrobble submissions',
            $accepted,
            $ignored,
            $ignoreReason,
        );
    }

    /**
     * Whether this failure means the STORED SESSION is permanently invalid
     * (the user must reconnect) — see {@see self::INVALID_SESSION_ERROR_CODES}.
     * Successes and code-less transport failures are never session-invalid.
     */
    public function isInvalidSession(): bool
    {
        return $this->errorCode !== null
            && in_array($this->errorCode, self::INVALID_SESSION_ERROR_CODES, true);
    }

    /**
     * Log-safe context for this outcome: response-side fields only.
     *
     * @return array<string, int|string>
     */
    public function logContext(): array
    {
        $context = [];
        if ($this->errorCode !== null) {
            $context['error_code'] = $this->errorCode;
        }
        if ($this->errorMessage !== null) {
            $context['error_message'] = $this->errorMessage;
        }
        if ($this->accepted !== null) {
            $context['accepted'] = $this->accepted;
        }
        if ($this->ignored !== null) {
            $context['ignored'] = $this->ignored;
        }
        if ($this->ignoreReason !== null) {
            $context['ignore_reason'] = $this->ignoreReason;
        }
        if ($this->transient) {
            $context['transient'] = 1;
            if ($this->retryAfterSeconds !== null) {
                $context['retry_after_seconds'] = $this->retryAfterSeconds;
            }
        }
        return $context;
    }
}

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
     * @param bool          $success      True only when Last.fm accepted the call outright.
     * @param int|null      $errorCode    Envelope `error` code when the API answered with one.
     * @param string|null   $errorMessage Envelope `message` when present.
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?int $errorCode = null,
        public readonly ?string $errorMessage = null,
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
     */
    public static function failure(?int $errorCode = null, ?string $errorMessage = null): self
    {
        return new self(false, $errorCode, $errorMessage);
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
        return $context;
    }
}

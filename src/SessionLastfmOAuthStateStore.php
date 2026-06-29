<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\Support\Session;

/**
 * `Support\Session`-backed implementation of {@see LastfmOAuthStateStore}.
 *
 * Stores the per-request CSRF `state` alongside the initiating user UUID
 * in a request-scoped {@see Session} instance, honoring the one-shot contract:
 * {@see consume()} removes the entry before returning it so a captured state
 * value cannot be replayed.
 *
 * This replaces the previous `$_SESSION`-backed implementation, fixing the
 * cross-user contamination bug (S5) that occurred in Workerman resident
 * runtime where `$_SESSION` persists across requests within the same
 * worker process.
 *
 * Mirrors {@see \Phlix\Plugins\Scrobbler\Trakt\SessionTraktOAuthStateStore}.
 *
 * @since 0.32.0
 */
final class SessionLastfmOAuthStateStore implements LastfmOAuthStateStore
{
    private const STATE_KEY = 'lastfm_oauth_state';

    /**
     * @param Session $session Request-scoped session store.
     */
    public function __construct(
        private readonly Session $session,
    ) {
    }

    public function put(string $state, string $userId): void
    {
        // Pack state and userId together so consume() can return userId in
        // one operation while still validating the state (one-shot).
        $this->session->put(self::STATE_KEY, $state . '|' . $userId);
    }

    public function consume(string $state): ?string
    {
        $packed = $this->session->get(self::STATE_KEY, '');
        // One-shot: regardless of outcome we wipe the stored value so a
        // replay attempt cannot reuse it.
        $this->session->forget(self::STATE_KEY);

        if (!is_string($packed) || $packed === '') {
            return null;
        }

        $pos = strpos($packed, '|');
        if ($pos === false) {
            return null;
        }

        $saved = substr($packed, 0, $pos);
        $userId = substr($packed, $pos + 1);

        if ($saved === '' || $userId === '') {
            return null;
        }

        if (!hash_equals($saved, $state)) {
            return null;
        }

        return $userId;
    }
}

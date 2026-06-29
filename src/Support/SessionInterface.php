<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm\Support;

/**
 * Contract for a request-scoped session store.
 *
 * Implementations replace global `$_SESSION` usage in Workerman resident
 * runtimes to prevent cross-user state contamination.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm\Support
 * @since 0.15.0
 */
interface SessionInterface
{
    /**
     * Store a value under the given key.
     *
     * @param string $key   Cache key (namespace is handled by implementation).
     * @param mixed  $value Value to store.
     */
    public function put(string $key, mixed $value): void;

    /**
     * Retrieve a value by key.
     *
     * @param string $key     Cache key.
     * @param mixed  $default Default value when key is absent.
     *
     * @return mixed The stored value or $default.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Remove a value by key.
     *
     * @param string $key Cache key.
     */
    public function forget(string $key): void;
}

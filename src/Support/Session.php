<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm\Support;

/**
 * Request-scoped session store that replaces the global `$_SESSION`.
 *
 * In a Workerman resident-process runtime, `$_SESSION` persists in memory
 * across requests within the same worker process, causing cross-user
 * contamination when one user's data bleeds into another user's request.
 * This class provides per-request isolation by storing data in an
 * in-memory map that is cleared at the end of each request.
 *
 * For production deployments this class should be replaced with a
 * host-provided implementation (e.g. JWT/coocie-backed session) by
 * registering a custom implementation of {@see SessionInterface} in the
 * PSR-11 container under the {@see Session::class} key.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm\Support
 * @since 0.15.0
 */
class Session implements SessionInterface
{
    /**
     * Per-request in-memory store, keyed by fully-qualified cache key.
     *
     * @var array<string, mixed>
     */
    private static array $store = [];

    /**
     * Namespace prefix for all keys stored by this class.
     *
     * @var string
     */
    private string $namespace;

    /**
     * @param string $namespace Prefix for all keys (e.g. 'lastfm').
     */
    public function __construct(string $namespace = 'lastfm')
    {
        $this->namespace = $namespace;
    }

    /**
     * Build a fully-qualified cache key.
     */
    private function key(string $key): string
    {
        return $this->namespace . '.' . $key;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $key, mixed $value): void
    {
        self::$store[$this->key($key)] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return self::$store[$this->key($key)] ?? $default;
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $key): void
    {
        unset(self::$store[$this->key($key)]);
    }

    /**
     * Clear all keys stored under this instance's namespace.
     *
     * Useful for testing or when a request-scoped session is reused
     * across different logical "sub-requests" within the same process.
     */
    public function flush(): void
    {
        $prefix = $this->namespace . '.';
        foreach (array_keys(self::$store) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset(self::$store[$k]);
            }
        }
    }

    /**
     * Clear ALL sessions stored in the static map.
     *
     * This method is intended for use in PHPUnit setUp/tearDown to
     * guarantee isolation between tests. Do NOT call this in production.
     *
     * @internal
     */
    public static function resetAll(): void
    {
        self::$store = [];
    }
}

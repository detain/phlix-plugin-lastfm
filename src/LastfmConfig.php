<?php

/**
 * LastfmConfig.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

/**
 * Typed wrapper over `config/lastfm.php`.
 *
 * Centralises the read of the Last.fm config slice so other classes do
 * not duplicate the `is_string()`/`is_bool()` ladder. The wrapped array
 * is expected to contain — at minimum — `api_key` and `shared_secret`;
 * see `config/lastfm.php` for the full schema.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmConfig
{
    /**
     * @param string $apiKey       Last.fm API key.
     * @param string $sharedSecret Shared secret used for `api_sig`.
     * @param bool   $enabled      Whether the plugin is enabled.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $sharedSecret,
        public readonly bool $enabled = false,
    ) {
    }

    /**
     * Build a {@see LastfmConfig} from a raw config array.
     *
     * Values are coerced defensively — string defaults to `''`, bool to
     * `false`.
     *
     * @param array<string, mixed> $config Raw config slice (typically the
     *                                     return value of
     *                                     `include config/lastfm.php`).
     */
    public static function fromArray(array $config): self
    {
        return new self(
            apiKey: is_string($config['api_key'] ?? null) ? $config['api_key'] : '',
            sharedSecret: is_string($config['shared_secret'] ?? null) ? $config['shared_secret'] : '',
            enabled: ($config['enabled'] ?? false) === true,
        );
    }

    /**
     * True when the plugin is enabled and both api credentials are present.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->apiKey !== '' && $this->sharedSecret !== '';
    }
}

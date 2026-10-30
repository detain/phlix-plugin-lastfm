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
 * Typed wrapper over the plugin's persisted settings.
 *
 * The settings arrive exclusively via {@see LastfmPlugin::configure()} —
 * the host hydrates them from the DB `plugins.settings_json` column (the
 * manifest's `settings` key-set: `enabled`, `api_key`, `shared_secret`).
 * There is deliberately no config-file path: earlier revisions shipped a
 * bundled `config/lastfm.php` that no code ever loaded (the settings are
 * per-installation DB state, not files), and it was removed as a ghost
 * setup path [review lastfm__docmaint M-01].
 *
 * Centralises the read of the Last.fm settings slice so other classes do
 * not duplicate the `is_string()`/`is_bool()` ladder.
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
     * @param array<string, mixed> $config Raw settings slice as delivered by
     *                                     the host to
     *                                     `LastfmPlugin::configure()`.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            apiKey: is_string($config['api_key'] ?? null) ? $config['api_key'] : '',
            sharedSecret: is_string($config['shared_secret'] ?? null) ? $config['shared_secret'] : '',
            enabled: ($config['enabled'] ?? false) == true,
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

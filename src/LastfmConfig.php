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
     * Values are parsed at this boundary into the declared types — strings
     * default to `''`, and `enabled` accepts a real bool or a canonical
     * boolean-ish scalar ('1', 'true', 'on', 'yes' and their false
     * counterparts, case-insensitively) via strict filter_var parsing. Any
     * other value (junk strings, arrays, null) parses to `false`: the old
     * loose `== true` comparison treated every non-empty string, arrays
     * included, as enabling, which the host's schema can persist.
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
            enabled: self::parseEnabled($config['enabled'] ?? false),
        );
    }

    /**
     * Parse the raw `enabled` setting into a bool without loose comparison.
     *
     * A bool passes through; scalars go through
     * {@see filter_var()} with FILTER_VALIDATE_BOOLEAN (so 'true'/'1'/'on'/'yes'
     * enable and 'false'/'0'/'off'/'no'/'' disable); anything else — arrays,
     * objects, null — is not a switch and parses to false.
     */
    private static function parseEnabled(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (!is_scalar($raw)) {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * True when the plugin is enabled and both api credentials are present.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->apiKey !== '' && $this->sharedSecret !== '';
    }
}

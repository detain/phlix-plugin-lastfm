<?php

/**
 * config::lastfm.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

/**
 * Last.fm plugin configuration.
 *
 * Copy this file to `config/lastfm.php` and fill in your credentials.
 * Obtain them at: https://www.last.fm/api/account/create
 *
 * @see \Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig::fromArray()
 */

return [
    // Set to true to enable the plugin.
    'enabled' => false,

    // Your Last.fm API key (consumer key).
    'api_key' => '',

    // Your Last.fm shared secret (used to sign API requests).
    'shared_secret' => '',
];

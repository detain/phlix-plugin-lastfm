<?php

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

    // OAuth callback URL — must match what you registered in the Last.fm API account settings.
    // This is the route the user lands on after authorizing at Last.fm.
    // Default: /auth/lastfm/callback
    'callback_url' => '/auth/lastfm/callback',

    // Optional: pre-fill the Last.fm username in the "Connected as X" display.
    'username' => '',
];

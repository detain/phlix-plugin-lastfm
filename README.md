# phlix-plugin-lastfm

[![tests](https://github.com/detain/phlix-plugin-lastfm/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-lastfm/actions/workflows/test.yml)

> Last.fm scrobbler plugin for [Phlix](https://github.com/detain/phlix-server) —
> scrobbles your music playback to [Last.fm](https://www.last.fm).

## Overview

Connects a Phlix server to your Last.fm account:

- **Now Playing** — on playback start the plugin calls Last.fm
  `track.updateNowPlaying` so your profile shows what you're listening to in
  real time.
- **Scrobbling** — on playback stop the plugin submits a `track.scrobble`,
  enforcing Last.fm's official rule (track longer than 30 seconds **and** more
  than 50% played) before anything is recorded.
- Authenticates via Last.fm **web auth**, signing every call with your shared
  secret and storing one session key per Phlix user.

It subscribes to `phlix.playback.started` and `phlix.playback.stopped`.

## Install

From the Phlix admin **Plugins** section, paste this repo's URL:

```
https://github.com/detain/phlix-plugin-lastfm
```

…or from the CLI:

```bash
php bin/phlix plugin:install https://github.com/detain/phlix-plugin-lastfm
```

## Settings

Configure these in the Phlix admin **Plugins → Configure** dialog.

| Setting | Type | Required | Default | Description |
|---|---|---|---|---|
| `enabled` | bool | no | `false` | Master on/off for Last.fm scrobbling. |
| `api_key` | string | **yes** | — | Your Last.fm API key. Create a free API account at [last.fm/api/account/create](https://www.last.fm/api/account/create). |
| `shared_secret` | string (secret) | **yes** | — | The "Shared secret" shown next to your API key on your [Last.fm API accounts](https://www.last.fm/api/accounts) page. Used to sign authenticated requests. |
| `callback_url` | string | no | server URL | The Callback URL you registered for your Last.fm API application (OAuth handshake). |
| `username` | string | no | — | Display-only: the Last.fm account scrobbles are sent to (set during authorization). |

### Where to get your credentials

1. Sign in to Last.fm and open [**Create an API account**](https://www.last.fm/api/account/create).
2. Fill in the application name/description; the **Callback URL** should point back at your Phlix server.
3. Copy the **API key** into `api_key` and the **Shared secret** into `shared_secret`.
4. Enable the plugin and toggle `enabled` on.

## Development

```bash
composer install
vendor/bin/phpunit
```

The entry class is `Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin` (implements
`Phlix\Shared\Plugin\LifecycleInterface`). It runs inside a Phlix server host,
which provides the playback/library services at runtime.

## License

MIT — see [LICENSE](LICENSE).

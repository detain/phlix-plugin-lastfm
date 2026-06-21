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

| Setting | Type | Description |
|---|---|---|
| `enabled` | bool | Enable Last.fm scrobbling. |
| `api_key` | string | Your Last.fm API key. |
| `shared_secret` | string | Your Last.fm shared secret (used to sign requests). |
| `callback_url` | string | OAuth callback URL the user returns to after approving access. |
| `username` | string | Your Last.fm username (display only). |

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

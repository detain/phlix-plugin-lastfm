# phlix-plugin-lastfm

[![tests](https://github.com/detain/phlix-plugin-lastfm/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-lastfm/actions/workflows/test.yml)

> Last.fm scrobbler plugin for [Phlix](https://github.com/detain/phlix-server) —
> scrobbles what you play to [Last.fm](https://www.last.fm).

## Overview

Connects a Phlix server to your Last.fm account:

- **Now Playing** — on playback start the plugin calls Last.fm
  `track.updateNowPlaying` so your profile shows what you're listening to in
  real time.
- **Scrobbling** — on playback stop the plugin submits a `track.scrobble`,
  enforcing Last.fm's official rule (track longer than 30 seconds **and** more
  than 50% *played*) measured from where playback started on this server, so
  resuming a half-finished track cannot credit its unplayed beginning.
- Connects each Phlix user to Last.fm through the **web auth** flow served by
  the host: the host performs the authorize + callback handshake and persists
  one session key per user into `lastfm_sessions`; the plugin signs
  authenticated calls with your shared secret and consumes those stored
  session keys when scrobbling.

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
| `enabled` | bool | no | `false` | Master on/off for Last.fm scrobbling. Parsed strictly: a real bool, or a canonical boolean string (`'true'`, `'1'`, `'on'`, `'yes'` — case-insensitive) enables it; the canonical false strings (`'false'`, `'0'`, `'off'`, `'no'`, `''`) disable it, and any other value — junk strings such as `'banana'`, numbers other than `1`/`0`, `null`, arrays — also stays disabled. |
| `api_key` | string | **yes** | — | Your Last.fm API key. Create a free API account at [last.fm/api/account/create](https://www.last.fm/api/account/create). |
| `shared_secret` | string (secret) | **yes** | — | The "Shared secret" shown next to your API key on your [Last.fm API accounts](https://www.last.fm/api/accounts) page. Used to sign authenticated requests. |

> The OAuth "Connect Last.fm" handshake (authorize + callback) is served by the
> **host** at `GET /api/v1/oauth/lastfm` and `GET /api/v1/oauth/lastfm/callback`;
> the callback URL is derived from the request host, so no `callback_url` setting
> is needed. Per-user session keys are stored in `lastfm_sessions` and consumed
> by the scrobbler.

### Where to get your credentials

1. Sign in to Last.fm and open [**Create an API account**](https://www.last.fm/api/account/create).
2. Fill in the application name/description. For the **Callback URL**, register
   the exact path the host derives at auth time —
   `https://<your-phlix-host>/api/v1/oauth/lastfm/callback` (the host sends
   that URL as `cb=` on every auth request, and Last.fm validates the request
   against this registered URL, so a different or query-strewn value fails
   the handshake).
3. Copy the **API key** into `api_key` and the **Shared secret** into `shared_secret`.
4. Enable the plugin and toggle `enabled` on.

`api_key` is deliberately **not** marked `secret` while `shared_secret` is:
a Last.fm API key is a public client identifier — the web-auth flow embeds it
in the browser-facing authorization URL by design — whereas the shared secret
is signing material that never leaves the server and is masked in the admin UI.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
php scripts/security-audit-check.php
```

Static analysis is configured in `phpstan.neon` (level 9 over `src`).

`scripts/security-audit-check.php` audits the whole of `composer.lock` — runtime
**and** `require-dev` dependencies — via `composer audit --locked`, and runs in CI
as the blocking `composer-audit` job in `.github/workflows/test.yml`.

The entry class is `Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin` (implements
`Phlix\Shared\Plugin\LifecycleInterface` and
`Phlix\Shared\Plugin\ConfigurableInterface`). It runs inside a Phlix server host,
which provides the playback/library services at runtime. `onEnable()` is
boot-safe — it only wires collaborators and subscribes events — so the
`lastfm_sessions` schema is ensured lazily on the first playback event, when
`LastfmScrobbler` invokes the deferred `LastfmMigrationRunner` closure.

## License

MIT — see [LICENSE](LICENSE).

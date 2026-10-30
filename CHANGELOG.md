# Changelog — phlix-plugin-lastfm

All notable changes to this plugin are documented here. The format follows
Keep a Changelog; versions follow SemVer. Catalog release entries are built
from git tags, so the `1.2.0` work below becomes visible to consumers when
its tag lands (tagging is an owner action).

## [1.2.0] — unreleased (plugin.json ahead of the published `v1.1.0` tag)

### Fixed

- W-min49 minors batch: truthful `api_sig` exclusion docs, honest deferred
  migration docblock, documented reserved `expires_at` column, exact
  callback-URL registration step, documented `api_key` non-mask judgment,
  and a debug breadcrumb for now-playing replies missing the documented
  `nowplaying` echo.
- Invalid-session self-heal: a stored session key the API permanently
  rejects (codes 4/9/17/88) now deletes its row so credential replay stops
  and the host UI shows disconnected; cleanup failure is contained with its
  own warning.
- Transient vs terminal classification at the API boundary (transport drop,
  429, 5xx, envelope-29 retryable; permission/item/parse terminal),
  `Retry-After` honored, a per-key pacing floor, and a bounded in-process
  scrobble replay queue drained on stop events — transient failures no
  longer silently drop a submission.
- Every playback-hook throw is contained: the host `ListenerRegistry`
  dispatches without per-listener isolation, so repository/resolver/API
  explosions degrade to one warning line instead of breaking the user's
  playback request.
- Last.fm's per-submission `accepted`/`ignored` verdict is parsed: a 200
  with `scrobbles.@attr.ignored > 0` is a `scrobbleRejected` outcome
  carrying the ignore reason, never a false success.
- The 200-with-error-envelope path is logged and every write outcome is
  consumed — typed `LastfmOutcome` replaced the discarded bool.
- Cooperative HTTP runner: the 1 ms busy-spin is gone, replaced by a
  `Channel(1)` rendezvous pop gated on coroutine context (outside one, the
  blocking stream fallback is chosen with one loud warning); the wait
  ceiling is injected rather than consumed by polling.
- The ghost `config/lastfm.php` (loaded by no code) was deleted; settings
  arrive only via `configure()` from the DB.
- Dependency security updates (`2ffb7db`): phpunit 10.5.64→.66,
  phpstan 2.2.5→2.3.1, workerman/* and nikic/php-parser refreshed.
- Codacy upload step synced to the fail-closed family idiom (`4826087`).

### Changed

- Boot semantics: `onEnable()` is wire-only (zero I/O); the
  `lastfm_sessions` schema is ensured lazily on the first playback event
  (`5f4af20`).
- Settings surface: per-field `tier` classifications added (`d7c3883`).
- Static analysis gate raised to PHPStan level 9 (`aa792cd`).

### Removed

- Dead OAuth controller subsystem and the fake `callback_url` setting
  (`e209fdc`); the fake `username` setting (`5f4af20`). The OAuth handshake
  is host-served (`GET /api/v1/oauth/lastfm[/callback]`).

### Added

- S440 whole-lock security-audit gate and its guard test suite
  (`7a908ed`, `4d2dee7`); PHPStan level-9 CI gate (`aa792cd`).

## [1.1.0] — 2026-07-10 (tag `v1.1.0`)

- Baseline scrobbler: Now Playing + scrobble submission with the official
  >30s / >50% rules, per-user `lastfm_sessions` store, shared-secret-signed
  POSTs (credentials in the request body, never the URL). See the git
  history between the initial releases for itemized detail.

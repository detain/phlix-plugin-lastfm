# Changelog — phlix-plugin-lastfm

All notable changes to this plugin are documented here. The format follows
Keep a Changelog; versions follow SemVer. Catalog release entries are built
from git tags; the `1.2.0` tag landed on 2026-11-02.

## [Unreleased]

### Changed
- Dependencies: dev toolchain upgraded to phpunit 10.5 -> 12.5.44 (PHP floor stays >=8.3; PHPUnit 12 needs 8.3), phpstan 2.3.1 -> 2.3.5, workerman 5.2.4 / coroutine 1.1.7. `detain/phlix-shared` pin (^0.54.0) intentionally unchanged. Tests migrated to PHPUnit 12 attributes (`#[CoversClass]`, `#[DataProvider]`, `#[AllowMockObjectsWithoutExpectations]`); GitHub Actions already on current majors.
- Audit gate (MAL-17): `scripts/security-audit-check.php` messages no longer print the slash-stem plurals "advisory/ies" / "repository/ies"; they pluralise correctly (1 advisory, 2 advisories). Estate family wave; test mirrors updated in lockstep.

### Fixed

- Retry-job logs: the exhausted-retries warning logs `media_item_id` instead of free-text track/artist, and queued jobs now carry `media_item_id`.
- Response-sourced envelope message and ignore reason are now control-stripped and capped at 256 bytes.
- Throwing HTTP runner: an injected runner that throws now degrades at LastfmApi::dispatch() to the status-0 transport shape (transient, queued for retry) with one bounded warning, instead of escaping to the playback-hook containment and losing the scrobble.
- Blocking stream fallback TLS: the `LastfmApi::defaultHttp()` runner now
  states `verify_peer`, `verify_peer_name` and `allow_self_signed=false`
  explicitly in its stream context (new `defaultHttpContextOptions()`),
  matching the async client's strict `ssl` subtree instead of relying on
  PHP's stream defaults. No behaviour change under PHP's safe defaults.
- Retry-After ceiling: a server `Retry-After` hint above
  `LastfmApi::MAX_RETRY_AFTER_SECONDS` (3600s) is no longer honoured when a
  transient scrobble failure is queued or re-queued — the job falls back to
  the 60s default backoff instead of being parked for as long as the server
  claims (e.g. `Retry-After: 31536000`). Hints at or below the ceiling are
  honoured as before, and logs still show the raw hint the server sent.
- Log wording: every `LastfmPlugin` log line now uses the same
  "Last.fm <subject> …" prefix as the API and scrobbler, the missing
  workerman/http-client degraded path is named "blocking stream fallback"
  (matching the out-of-coroutine notice) instead of "sync fallback", and
  the missing-session-storage warning now says in plain words that the
  plugin is not enabled and scrobbling stays off. Behaviour is unchanged.
- Docs: the `enabled` setting row in the README no longer calls `'false'` a
  "junk string". `'false'`, `'0'`, `'off'`, `'no'` and `''` are the canonical
  false strings — they disable the plugin by design, the same as `false`.
  Only values outside the canonical set (e.g. `'banana'`, `null`, arrays)
  are junk, and those stay disabled too. This also corrects the 1.2.0
  W-min50 entry below, which used the same wrong wording; the entry is left
  as written because it is release history. Behaviour is unchanged.

## [1.2.0] — 2026-11-02 (tag `v1.2.0`)

### Fixed

- W-min53 minors batch: `LastfmApi::getSession` now parses inbound session
  fields at the boundary (trimmed, non-empty, ≤64 bytes matching the
  `VARCHAR(64)` columns, ASCII control chars rejected) so a hostile or
  compromised Last.fm response can never persist junk into
  `lastfm_sessions`, and `save()`'s upsert moved from the deprecated
  `VALUES()` spelling (MySQL 8.0.20+) to the row-alias `AS new` form
  (8.0.19+), pinned both ways.
- W-min52 minors batch: the workerman/mysql prepared-statement binding path
  is now exercised end-to-end (`LastfmSessionRepositoryBindingTest` drives
  the real vendor `execute()`/`bindMore()` against a socket-free PDO probe —
  placeholder SQL constant, hostile values never in SQL text, emulated
  prepares pinned off) and the scrobble call site gained its missing
  error-in-200 envelope pin plus a recorded Last.fm docs payload exercising
  the `@attr` verdict parser.
- W-min51 minors batch: README now states the real phpstan level (9, matching
  the CI gate) and the `phpstan.neon` `class.notFound` ignore is narrowed from
  a file-wide blanket to the two measured ItemRepository messages — any new
  unknown-class error in `LastfmPlugin.php` now fails the gate; the three
  snake_case test names in `LastfmPluginConfigureTest` are camelCase.
- W-min50 minors batch: `enabled` is parsed strictly (junk strings such as
  `'false'`, `'no'`, `'off'` and non-scalars no longer enable the plugin via
  loose `== true` comparison — canonical booleans still do), the Overview
  states the true auth split (host performs web-auth and persists session
  keys; the plugin signs and consumes), and `LastfmApi::getSession` +
  `LastfmSessionRepository::save` are annotated as host-mirrored reference
  code with zero plugin-runtime callers.
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

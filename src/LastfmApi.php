<?php

/**
 * LastfmApi.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP client for the Last.fm Web Service v2 (`https://ws.audioscrobbler.com/2.0/`).
 *
 * This client implements the three call types needed for scrobbling from
 * an authenticated user's session: `auth.getSession` (token exchange),
 * `track.updateNowPlaying` (real-time status) and `track.scrobble`
 * (history submission).
 *
 * ## Signature
 *
 * Last.fm signs every authenticated call. The signature input is the
 * alphabetically-sorted concatenation of all request parameters
 * (excluding `format`, `callback`, and any pre-existing `api_sig`), with
 * their values appended, and the shared secret tacked on at the end. The
 * MD5 of that UTF-8 byte string becomes the `api_sig` parameter. See the
 * worked example in {@see self::buildApiSig()}'s tests.
 *
 * The class is intentionally framework-agnostic — it uses a pluggable
 * HTTP runner callable so test doubles can swap in a custom implementation
 * without dragging cURL in. The default runner uses `file_get_contents`;
 * production Workerman deployments should inject an async runner such as
 * `workerman/http-client`.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 * @since 1.1.0
 */
class LastfmApi
{
    /**
     * The Last.fm Web Service host — the single source of truth for both the
     * endpoint URL below and the TLS peer identity the async client pins
     * ({@see LastfmPlugin::asyncClientOptions()}).
     */
    public const API_HOST = 'ws.audioscrobbler.com';

    /** Default Last.fm Web Service endpoint. */
    public const API_ROOT = 'https://' . self::API_HOST . '/2.0/';

    /**
     * Hard ceiling on the size of a response body this client will DECODE.
     * Honest Last.fm answers to these three methods are kilobytes; a
     * compromised or DNS-repointed endpoint answering with a giant body
     * would otherwise push `json_decode` — and the retained associative
     * array — through a resident worker. An oversized body is refused at the
     * dispatch normalization step and treated as unparseable, which keeps
     * the failure shape honest (no fabricated envelope verdict).
     *
     * The runner still materializes the bytes it read (a PHP stream read is
     * not size-cappable mid-flight); the ceiling bounds decode and
     * retention, which is where the worker-resident cost lives.
     */
    public const MAX_RESPONSE_BYTES = 1_048_576;

    /**
     * Last.fm is a courtesy-polite API; hammering it during a transient
     * outage converts backoff into harder throttling. One client instance
     * == one API key in this plugin, so a per-instance minimum dispatch
     * interval is the honest pacing seam.
     */
    public const DEFAULT_MIN_INTERVAL_SECONDS = 0.2;

    /**
     * Envelope code that means the APPLICATION (API key) hit its rate
     * limit — the docs list 29 as "API key is disabled", and live traffic
     * also returns it for daily/hourly throttling. Either way the fix is
     * operator/upstream-side and WAITING is the correct client behavior,
     * so it classifies as transient.
     */
    public const RATE_LIMIT_ENVELOPE_CODE = 29;

    /**
     * Byte ceiling for inbound `session_key`/`username` values, matching the
     * VARCHAR(64) columns of `lastfm_sessions`
     * (src/Database/Migrations/CreateLastfmSessionsTable.php) — oversized
     * wire values are refused at the API boundary rather than reaching
     * storage.
     */
    public const MAX_SESSION_FIELD_BYTES = 64;

    private LoggerInterface $logger;

    private float $minIntervalSeconds;

    /** Wall time of the previous dispatch; null until the first call. */
    private ?float $lastDispatchAt = null;

    /**
     * Pluggable HTTP runner for tests. Defaults to {@see self::defaultHttp()}.
     *
     * @var callable(string $url, string $body, array<string, string> $headers): array{status: int, body: string, headers?: array<array-key, mixed>}
     */
    private $http;

    /**
     * @param string                              $apiKey       Last.fm API key.
     * @param string                              $sharedSecret Shared secret used to sign requests.
     * @param LoggerInterface|null                $logger       Optional PSR-3 logger.
     * @param (callable(string, string, array<string, string>): array{status: int, body: string, headers?: array<array-key, mixed>})|null $http
     *        Optional HTTP runner override (test hook).
     * @param float|null                          $minIntervalSeconds
     *        Pacing floor between dispatches. Null = {@see self::DEFAULT_MIN_INTERVAL_SECONDS};
     *        0 disables pacing (unit tests).
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $sharedSecret,
        ?LoggerInterface $logger = null,
        ?callable $http = null,
        ?float $minIntervalSeconds = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->http = $http ?? self::defaultHttp();
        $this->minIntervalSeconds = $minIntervalSeconds ?? self::DEFAULT_MIN_INTERVAL_SECONDS;
    }

    /**
     * Exchange a request token for a long-lived session.
     *
     * Mirrors Last.fm `auth.getSession`. The returned session key never
     * expires unless the user revokes it from their Last.fm settings.
     *
     * Reference implementation only: the connect flow is served by the
     * **host** (its OAuth callback performs this exchange and persists the
     * key), so nothing in plugin runtime calls this method — it documents
     * the wire protocol the host mirrors (pinned by unit tests). Deleting
     * and reading session rows are the plugin-side live operations.
     *
     * @param string $token Request token obtained by redirecting the user
     *                      through `https://www.last.fm/api/auth/?api_key=...&token=...`.
     *
     * @return array{session_key: string, username: string}|null
     *         Hydrated (trimmed, length/control-char checked) session payload,
     *         or null when Last.fm rejected the token or the response fields
     *         fail the inbound boundary parse.
     */
    public function getSession(string $token): ?array
    {
        $params = [
            'method'  => 'auth.getSession',
            'api_key' => $this->apiKey,
            'token'   => $token,
        ];
        $params['api_sig'] = $this->buildApiSig($params);
        $params['format']  = 'json';

        $response = $this->call($params);
        if ($response === null) {
            return null;
        }

        $session = $response['session'] ?? null;
        if (!is_array($session)) {
            return null;
        }
        $key = $session['key'] ?? null;
        $name = $session['name'] ?? null;
        $key = self::normalizeSessionField($key);
        $name = self::normalizeSessionField($name);
        if ($key === null || $name === null) {
            return null;
        }
        return ['session_key' => $key, 'username' => $name];
    }

    /**
     * Parse a session field arriving from the wire into the shape the
     * VARCHAR(64) columns accept — trimmed, non-empty, ≤64 bytes, free of
     * ASCII control characters — or refuse it (null) at the boundary.
     *
     * The byte cap is deliberately stricter than the column's character
     * count: oversized or control-laden values from a hostile/compromised
     * response never reach storage, independent of the server's sql_mode.
     */
    private static function normalizeSessionField(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > self::MAX_SESSION_FIELD_BYTES) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            return null;
        }
        return $trimmed;
    }

    /**
     * Notify Last.fm that the user has just begun listening to a track.
     *
     * Calls `track.updateNowPlaying`. Returns a typed {@see LastfmOutcome}:
     * success requires a 2xx response with no `error` envelope — any other
     * outcome (transport failure, non-2xx, unparseable body, or the
     * error-in-200 that Last.fm uses for revoked sessions) surfaces as a
     * failure carrying the envelope's code/message when available.
     *
     * @param string $sessionKey Authenticated session key from {@see getSession()}.
     * @param string $track      Track title.
     * @param string $artist     Artist name.
     * @param string|null $album Optional album name.
     */
    public function updateNowPlaying(
        string $sessionKey,
        string $track,
        string $artist,
        ?string $album = null,
    ): LastfmOutcome {
        $params = [
            'method'  => 'track.updateNowPlaying',
            'api_key' => $this->apiKey,
            'sk'      => $sessionKey,
            'track'   => $track,
            'artist'  => $artist,
        ];
        if ($album !== null && $album !== '') {
            $params['album'] = $album;
        }
        $params['api_sig'] = $this->buildApiSig($params);
        $params['format']  = 'json';

        $response = $this->dispatch($params);
        $decoded = $response['decoded'];
        if ($decoded !== null && !isset($decoded['error'])) {
            // An "ok-shaped" body that is not the documented `nowplaying`
            // payload still counts as success (the permissive verdict
            // survives upstream shape variance by design) but leaves a
            // debug breadcrumb rather than passing in total silence — the
            // milder twin of the scrobble @attr story.
            if (!isset($decoded['nowplaying'])) {
                $this->logger->debug('Last.fm now-playing reply carried no nowplaying echo', [
                    'method' => 'track.updateNowPlaying',
                ]);
            }
            return LastfmOutcome::success();
        }
        return self::failureOutcome($response);
    }

    /**
     * Submit a single scrobble to Last.fm.
     *
     * Calls `track.scrobble`. Last.fm's scrobble rules
     * (>30s duration AND >50% played) are NOT enforced here — the caller
     * (see {@see LastfmScrobbler::onPlaybackStopped()}) is responsible
     * for that gating.
     *
     * The returned verdict is Last.fm's own: a 200 whose
     * `scrobbles.@attr.ignored` count is positive is a REFUSAL
     * (`scrobbleRejected`, reason carried when the API reports one), never
     * a success — HTTP-level "no error" alone is not acceptance.
     *
     * @param string      $sessionKey Authenticated session key.
     * @param string      $track      Track title.
     * @param string      $artist     Artist name.
     * @param string|null $album      Optional album name.
     * @param int|null    $timestamp  Unix timestamp when the user started
     *                                listening. Defaults to `time()`.
     */
    public function scrobble(
        string $sessionKey,
        string $track,
        string $artist,
        ?string $album = null,
        ?int $timestamp = null,
    ): LastfmOutcome {
        $params = [
            'method'    => 'track.scrobble',
            'api_key'   => $this->apiKey,
            'sk'        => $sessionKey,
            'track'     => $track,
            'artist'    => $artist,
            'timestamp' => (string) ($timestamp ?? time()),
        ];
        if ($album !== null && $album !== '') {
            $params['album'] = $album;
        }
        $params['api_sig'] = $this->buildApiSig($params);
        $params['format']  = 'json';

        $response = $this->dispatch($params);
        $decoded = $response['decoded'];
        if ($decoded !== null && !isset($decoded['error'])) {
            return self::scrobbleOutcome($decoded);
        }
        return self::failureOutcome($response);
    }

    /**
     * Build the `api_sig` parameter per the Last.fm authentication spec.
     *
     * Algorithm:
     *  1. Drop `format`, `callback`, and `api_sig` itself from the parameter
     *     set (a set that already carries a signature must never fold the
     *     old signature into the new one — re-signing has to be idempotent).
     *  2. Sort the remaining keys alphabetically.
     *  3. Concatenate `key . value` for each kept parameter, in order.
     *  4. Append the shared secret.
     *  5. UTF-8 encode then MD5 the resulting string.
     *
     * Exposed (package-private style — public but not part of the public
     * contract) so {@see LastfmApiTest} can exercise the worked example
     * from the Last.fm documentation directly.
     *
     * @param array<string, string> $params Parameter set destined for the API.
     *
     * @return string Lower-case hex-encoded MD5 of the signature input.
     */
    public function buildApiSig(array $params): string
    {
        $filtered = $params;
        unset($filtered['format'], $filtered['callback'], $filtered['api_sig']);
        ksort($filtered);

        $input = '';
        foreach ($filtered as $key => $value) {
            $input .= $key . $value;
        }
        $input .= $this->sharedSecret;

        return md5($input);
    }

    /**
     * Execute one Last.fm API call via POST and decode the JSON body.
     *
     * Thin seam over {@see self::dispatch()} for callers that only need the
     * decoded body (token exchange); the scrobble paths use dispatch()
     * directly to keep the transient/terminal classification honest.
     *
     * @param array<string, string> $params Final parameter set including `api_sig` + `format=json`.
     *
     * @return array<string, mixed>|null Decoded body, or null on failure.
     */
    private function call(array $params): ?array
    {
        return $this->dispatch($params)['decoded'];
    }

    /**
     * One signed API dispatch: pacing, transport, status handling, decode.
     *
     * The old shape returned bare `?array`, which threw away the HTTP
     * status and headers — the very signals that separate a retryable
     * hiccup (transport drop, 429, 5xx, envelope 29) from a terminal
     * rejection. The dispatch result is the parsed boundary the
     * classification layer reads instead of guessing.
     *
     * The runner result is normalized here (contract: `array{status:int,
     * body:string, headers?:array}`): a third-party runner missing keys can
     * only degrade to a zero-status transport failure, never an explosion.
     *
     * @param array<string, string> $params
     *
     * @return array{status: int, decoded: array<string,mixed>|null, headers: array<array-key, mixed>}
     */
    private function dispatch(array $params): array
    {
        $this->pace();

        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $headers = [
            'Content-Type'   => 'application/x-www-form-urlencoded',
            'Content-Length' => (string) strlen($body),
            'User-Agent'     => 'phlix/last.fm (+https://github.com/detain/phlix-server)',
        ];

        /** @var array<array-key, mixed> $raw */
        $raw = ($this->http)(self::API_ROOT, $body, $headers);
        $status = isset($raw['status']) && is_numeric($raw['status']) ? (int) $raw['status'] : 0;
        $payload = isset($raw['body']) && is_string($raw['body']) ? $raw['body'] : '';
        if (strlen($payload) > self::MAX_RESPONSE_BYTES) {
            // The byte ceiling ({@see self::MAX_RESPONSE_BYTES}) refuses to
            // decode an oversized body: collapsing it to the empty string
            // routes the response through the same unparseable path a
            // malformed body takes, so a balloon attack yields an honest
            // transport-shaped failure instead of a huge retained array.
            $this->logger->warning('Last.fm API response exceeded the decode size ceiling', [
                'bytes'     => strlen($payload),
                'max_bytes' => self::MAX_RESPONSE_BYTES,
                'method'    => $params['method'] ?? null,
            ]);
            $payload = '';
        }
        /** @var array<array-key, mixed> $responseHeaders */
        $responseHeaders = isset($raw['headers']) && is_array($raw['headers']) ? $raw['headers'] : [];

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Last.fm API returned non-2xx', [
                'status' => $status,
                'method' => $params['method'] ?? null,
            ]);
            // Last.fm also serves the JSON error envelope on 4xx bodies —
            // decoding it here costs nothing and feeds the classifier. On
            // every runner: the stream default sets `ignore_errors`, so the
            // envelope survives even on this blocking path.
            return ['status' => $status, 'decoded' => self::decodeObject($payload), 'headers' => $responseHeaders];
        }

        $decoded = self::decodeObject($payload);
        if ($decoded !== null && isset($decoded['error'])) {
            // Last.fm signals a revoked or stale session as HTTP 200 +
            // error envelope; the non-2xx branch above was the only site
            // that ever logged, so a permanently broken integration was
            // silent forever. Response bodies carry no request credentials
            // (api_key/sk are request-side), so the code+message are safe
            // to log.
            $this->logger->warning('Last.fm API error envelope', [
                'error'   => self::envelopeCode($decoded),
                'message' => self::envelopeMessage($decoded),
                'method'  => $params['method'] ?? null,
            ]);
        }
        return ['status' => $status, 'decoded' => $decoded, 'headers' => $responseHeaders];
    }

    /**
     * Enforce the per-instance pacing floor before a dispatch.
     *
     * Under Swoole coroutine hooks `usleep` yields instead of blocking the
     * worker, and the fallback stream runner is a blocking path anyway — the
     * wait is correct in both. Non-positive intervals disable the gate.
     *
     * The wait is clamped by {@see self::paceWaitSeconds()}: `microtime()` is
     * wall time, so an NTP correction or manual clock step backwards would
     * otherwise turn `$due - $now` into an hours-long `usleep` that wedges
     * the resident worker. The stamp taken after the (bounded) wait re-anchors
     * the gate on the new clock.
     */
    private function pace(): void
    {
        if ($this->minIntervalSeconds <= 0.0) {
            return;
        }
        $wait = self::paceWaitSeconds($this->lastDispatchAt, microtime(true), $this->minIntervalSeconds);
        if ($wait > 0.0) {
            usleep((int) round($wait * 1_000_000));
        }
        $this->lastDispatchAt = microtime(true);
    }

    /**
     * Seconds {@see self::pace()} must wait before the next dispatch, always
     * within `[0, $minIntervalSeconds]`.
     *
     * - First dispatch (no previous stamp) or a due time already passed: 0.
     * - Normal pacing window: `$due - $now`, which is at most the interval.
     * - Clock stepped backwards (`$now` earlier than the previous stamp, so
     *   `$due - $now` exceeds the interval or is not finite): treated as a
     *   reset — the previous dispatch is taken to have happened at `$now`, so
     *   the wait is exactly one interval, never the size of the clock step.
     */
    private static function paceWaitSeconds(?float $lastDispatchAt, float $now, float $minIntervalSeconds): float
    {
        if ($lastDispatchAt === null || $minIntervalSeconds <= 0.0) {
            return 0.0;
        }
        $wait = ($lastDispatchAt + $minIntervalSeconds) - $now;
        if (!is_finite($wait) || $wait > $minIntervalSeconds) {
            return $minIntervalSeconds;
        }
        return $wait > 0.0 ? $wait : 0.0;
    }

    /**
     * The typed failure verdict for a dispatch that did not succeed.
     *
     * Transient ⟺ transport drop (status 0), HTTP 429, HTTP >= 500, or an
     * envelope carrying {@see self::RATE_LIMIT_ENVELOPE_CODE}. Everything
     * else (permission, invalid params, item shapes, unparseable 2xx) is
     * terminal — replaying it would fail identically. Retry-After (seconds
     * form) is attached only to transient verdicts.
     *
     * @param array{status: int, decoded: array<string,mixed>|null, headers: array<array-key, mixed>} $response
     */
    private static function failureOutcome(array $response): LastfmOutcome
    {
        $decoded = $response['decoded'];
        $status = $response['status'];

        if ($decoded !== null && isset($decoded['error'])) {
            $code = self::envelopeCode($decoded);
            $transient = $code === self::RATE_LIMIT_ENVELOPE_CODE || $status === 429 || $status >= 500;
            return LastfmOutcome::failure(
                $code,
                self::envelopeMessage($decoded),
                $transient,
                $transient ? self::retryAfterSeconds($response['headers']) : null,
            );
        }

        $transient = $status === 0 || $status === 429 || $status >= 500;
        return LastfmOutcome::failure(
            null,
            null,
            $transient,
            $transient ? self::retryAfterSeconds($response['headers']) : null,
        );
    }

    /**
     * `Retry-After` in seconds form, case-insensitively, list-value
     * tolerant (PSR-7 runners hand back arrays). The HTTP-date variant is
     * deliberately unsupported: Last.fm throttles with delta-seconds, and
     * silently mis-parsing a date would be worse than ignoring it.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function retryAfterSeconds(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || strcasecmp($name, 'Retry-After') !== 0) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            return is_numeric($value) ? max(0, (int) $value) : null;
        }
        return null;
    }

    /**
     * Decode a JSON object body, or null for anything else.
     *
     * @return array<string,mixed>|null
     */
    private static function decodeObject(string $payload): ?array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $arr */
        $arr = $decoded;
        return $arr;
    }

    /**
     * Map a decoded 2xx **scrobble** body (no top-level error) to a typed
     * outcome via Last.fm's per-submission verdict channel.
     *
     * `track.scrobble` answers a REFUSED submission with HTTP 200, no
     * `error`, and `scrobbles.@attr` counts (`accepted`/`ignored`) plus a
     * per-scrobble `ignore` reason (banned channel, timestamp rules,
     * quality filter). Treating that shape as success reported listens as
     * recorded while holes appeared in the user's history with zero
     * diagnostic trail.
     *
     * The parse is cast-guarded end to end: an absent or non-numeric
     * `@attr` keeps the permissive verdict, so upstream shape variance can
     * never break the scrobble path — it can only lose the extra detail.
     *
     * @param array<string, mixed> $response
     */
    private static function scrobbleOutcome(array $response): LastfmOutcome
    {
        $scrobbles = $response['scrobbles'] ?? null;
        if (!is_array($scrobbles)) {
            return LastfmOutcome::success();
        }
        $attr = $scrobbles['@attr'] ?? null;
        if (!is_array($attr)) {
            return LastfmOutcome::success();
        }
        $accepted = self::numericField($attr, 'accepted');
        $ignored = self::numericField($attr, 'ignored');
        if ($accepted === null && $ignored === null) {
            return LastfmOutcome::success();
        }
        if ($ignored !== null && $ignored > 0) {
            return LastfmOutcome::scrobbleRejected($accepted ?? 0, $ignored, self::ignoreReason($scrobbles));
        }
        return LastfmOutcome::scrobbleAccepted($accepted ?? 0);
    }

    /**
     * Extract the per-scrobble ignore reason from the recorded response
     * shapes — `ignore.@attr.reason` and `ignore.reason` both occur in the
     * wild; a list-shaped `scrobble` element (multi submissions) is scanned
     * entry-wise.
     *
     * @param array<array-key, mixed> $scrobbles
     */
    private static function ignoreReason(array $scrobbles): ?string
    {
        $element = $scrobbles['scrobble'] ?? null;
        if (!is_array($element)) {
            return null;
        }
        $entries = array_is_list($element) ? $element : [$element];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ignore = $entry['ignore'] ?? null;
            if (!is_array($ignore)) {
                continue;
            }
            $attrReason = is_array($ignore['@attr'] ?? null) ? $ignore['@attr']['reason'] ?? null : null;
            if (is_string($attrReason) && $attrReason !== '') {
                return $attrReason;
            }
            $directReason = $ignore['reason'] ?? null;
            if (is_string($directReason) && $directReason !== '') {
                return $directReason;
            }
        }
        return null;
    }

    /**
     * Numeric map field as int, or null when absent/non-numeric — shape
     * variance must never explode the parser.
     *
     * @param array<array-key, mixed> $row
     */
    private static function numericField(array $row, string $key): ?int
    {
        $raw = $row[$key] ?? null;
        return is_numeric($raw) ? (int) $raw : null;
    }

    /**
     * Envelope `error` as int, or null when absent/non-numeric — shape
     * variance must never explode the parser.
     *
     * @param array<string, mixed> $response
     */
    private static function envelopeCode(array $response): ?int
    {
        return self::numericField($response, 'error');
    }

    /**
     * Envelope `message` as string, or null when absent/non-string.
     *
     * @param array<string, mixed> $response
     */
    private static function envelopeMessage(array $response): ?string
    {
        $raw = $response['message'] ?? null;
        return is_string($raw) ? $raw : null;
    }

    /**
     * Default HTTP runner using PHP streams. Tests can override via the
     * constructor's `$http` parameter.
     *
     * NOTE: This is a **synchronous, blocking** runner. It is NOT safe for
     * use inside a Workerman/event-loop worker — use an async runner
     * (e.g. workerman/http-client) in production. This default exists
     * for CLI scripts and unit tests only.
     *
     * Transport errors (DNS failure, connection refused, timeout) result in
     * a zero-status response so the caller can distinguish them from 2xx
     * responses via the status code. Non-2xx responses are delivered
     * COMPLETE: `ignore_errors` keeps the body file_get_contents() would
     * otherwise discard on any status >= 400, which is exactly where
     * Last.fm's JSON error envelope (and its classifier-relevant code)
     * lives. Response headers are parsed from `$http_response_header` (the
     * retry verdict needs Retry-After; duplicate header names collapse
     * last-wins, which Retry-After never legitimately hits).
     *
     * @return callable(string $url, string $body, array<string, string> $headers): array{status: int, body: string, headers: array<string, string>}
     */
    public static function defaultHttp(): callable
    {
        return static function (string $url, string $body, array $headers): array {
            $headerLines = '';
            foreach ($headers as $name => $value) {
                $headerLines .= $name . ': ' . $value . "\r\n";
            }
            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => $headerLines,
                    'content'       => $body,
                    'timeout'       => 10,
                    // Without this the wrapper treats >= 400 as a failed
                    // READ (false, empty body): the 403 carrying envelope 29
                    // would lose its transient verdict to the classifier.
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
            $statusLine = isset($http_response_header[0])
                ? (string) $http_response_header[0]
                : '';
            $status = 0;
            if ($statusLine !== '' && preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m) === 1) {
                $status = (int) $m[1];
            }
            $responseHeaders = [];
            if (isset($http_response_header[0])) {
                foreach ($http_response_header as $line) {
                    $colon = strpos($line, ':');
                    if ($colon === false) {
                        continue;
                    }
                    $name = trim(substr($line, 0, $colon));
                    if ($name !== '') {
                        $responseHeaders[$name] = ltrim(substr($line, $colon + 1));
                    }
                }
            }
            return [
                'status' => $status,
                'body' => is_string($response) ? $response : '',
                'headers' => $responseHeaders,
            ];
        };
    }
}

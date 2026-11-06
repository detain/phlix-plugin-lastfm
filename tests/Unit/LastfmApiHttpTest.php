<?php

/**
 * Unit::LastfmApiHttpTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for the HTTP runner seam in {@see LastfmApi}.
 *
 * These tests verify that:
 * 1. The injected HTTP runner is called with the correct URL, body, and headers.
 * 2. Non-2xx responses are surfaced as warning logs, not silent nulls.
 * 3. Transport errors result in a distinguishable zero-status response.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmApiHttpTest extends TestCase
{
    public function testHttpRunnerReceivesCorrectUrlBodyAndHeaders(): void
    {
        $captured = ['url' => '', 'body' => '', 'headers' => []];

        $http = static function (string $url, string $body, array $headers) use (&$captured): array {
            $captured['url'] = $url;
            $captured['body'] = $body;
            $captured['headers'] = $headers;
            return ['status' => 200, 'body' => '{"nowplaying":{"track":{"#text":"T"},"artist":{"#text":"A"}}}'];
        };

        $api = new LastfmApi('API_KEY', 'SHARED_SECRET', null, $http);
        $api->updateNowPlaying('SESSION_KEY', 'Track', 'Artist', 'Album');

        $this->assertSame(LastfmApi::API_ROOT, $captured['url']);
        $this->assertNotEmpty($captured['body']);

        // Headers must include Content-Type and Content-Length.
        $this->assertArrayHasKey('Content-Type', $captured['headers']);
        $this->assertSame('application/x-www-form-urlencoded', $captured['headers']['Content-Type']);
        $this->assertArrayHasKey('Content-Length', $captured['headers']);
        $this->assertSame((string) strlen($captured['body']), $captured['headers']['Content-Length']);
    }

    public function testNon2xxResponseSurfacesWarningLogNotSilentNull(): void
    {
        $warnings = [];

        $logger = new class($warnings) extends NullLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            private array $warningsRef;

            public function __construct(array &$warningsRef)
            {
                $this->warningsRef = &$warningsRef;
            }

            public function warning($message, array $context = []): void
            {
                $this->warningsRef[] = ['message' => $message, 'context' => $context];
            }
        };

        $http = static function (): array {
            // Return a 500 response.
            return ['status' => 500, 'body' => '{"error":99,"message":"Service unavailable"}'];
        };

        $api = new LastfmApi('API_KEY', 'SHARED_SECRET', $logger, $http);
        $result = $api->updateNowPlaying('SESSION_KEY', 'Track', 'Artist');

        // Must return a failure outcome on non-2xx, not throw or fake success.
        $this->assertFalse($result->success);

        // Warning log must be emitted, not silent.
        $this->assertCount(1, $warnings);
        $this->assertSame('Last.fm API returned non-2xx', $warnings[0]['message']);
        $this->assertSame(500, $warnings[0]['context']['status']);
    }

    public function testTransportErrorReturnsDistinguishableZeroStatus(): void
    {
        $captured = [];

        $http = static function (string $url, string $body, array $headers) use (&$captured): array {
            $captured['url'] = $url;
            // Simulate transport error: status=0, empty body.
            return ['status' => 0, 'body' => ''];
        };

        $api = new LastfmApi('API_KEY', 'SHARED_SECRET', null, $http);
        $result = $api->updateNowPlaying('SESSION_KEY', 'Track', 'Artist');

        // Transport errors are distinguishable from API errors.
        // status=0 is not 2xx, so the outcome must be a failure (not success).
        $this->assertFalse($result->success);
        $this->assertNotNull($captured['url']);
        $this->assertSame(LastfmApi::API_ROOT, $captured['url']);
    }

    public function testDefaultHttpRunnerIsUsedWhenNoOverrideProvided(): void
    {
        // Create API without passing an $http override.
        $api = new LastfmApi('K', 'S');

        // The default HTTP runner should be set (access via reflection).
        $reflection = new \ReflectionClass($api);
        $prop = $reflection->getProperty('http');
        $prop->setAccessible(true);
        $http = $prop->getValue($api);

        $this->assertIsCallable($http);

        // The default runner should not be null.
        $this->assertNotNull($http);
    }

    /**
     * M-04 [review lastfm__srphp M04] — Last.fm answers a revoked session
     * with HTTP **200** and an error envelope in the body. That path must
     * be logged (the non-2xx branch was the only site that ever was), and
     * the failure must reach the caller as a typed outcome — response
     * fields carry no request credentials, so the envelope is log-safe.
     */
    public function test200ErrorEnvelopeIsLoggedAndSurfacedAsTypedFailure(): void
    {
        $warnings = [];

        $logger = new class ($warnings) extends NullLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            private array $warningsRef;

            public function __construct(array &$warningsRef)
            {
                $this->warningsRef = &$warningsRef;
            }

            public function warning($message, array $context = []): void
            {
                $this->warningsRef[] = ['message' => $message, 'context' => $context];
            }
        };

        $http = static function (): array {
            return [
                'status' => 200,
                'body' => '{"error":9,"message":"Invalid session key - Please re-authenticate"}',
            ];
        };

        $api = new LastfmApi('API_KEY', 'SHARED_SECRET', $logger, $http);
        $outcome = $api->updateNowPlaying('SESSION_KEY', 'Track', 'Artist');

        $this->assertFalse($outcome->success, 'error-in-200 must not surface as success');
        $this->assertSame(9, $outcome->errorCode);
        $this->assertSame('Invalid session key - Please re-authenticate', $outcome->errorMessage);

        $envelopeLogs = array_values(array_filter(
            $warnings,
            static fn (array $w): bool => $w['message'] === 'Last.fm API error envelope',
        ));
        $this->assertCount(1, $envelopeLogs, 'the error-in-200 path must be logged once');
        $this->assertSame(9, $envelopeLogs[0]['context']['error']);
        $this->assertSame('track.updateNowPlaying', $envelopeLogs[0]['context']['method']);
        $this->assertStringContainsString('Invalid session key', (string) $envelopeLogs[0]['context']['message']);
    }

    /**
     * W-min52 M-20 [review lastfm minors W-min52] — the scrobble twin of
     * the nowplaying error-in-200 pin above: the envelope branch lives in
     * the shared dispatch, but the scrobble call site had never been
     * driven through it, so a revoked session during scrobbling could
     * regress into silence without any test noticing.
     */
    public function testScrobble200ErrorEnvelopeIsLoggedAndSurfacedAsTypedFailure(): void
    {
        $warnings = [];

        $logger = new class ($warnings) extends NullLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            private array $warningsRef;

            public function __construct(array &$warningsRef)
            {
                $this->warningsRef = &$warningsRef;
            }

            public function warning($message, array $context = []): void
            {
                $this->warningsRef[] = ['message' => $message, 'context' => $context];
            }
        };

        $http = static function (): array {
            return [
                'status' => 200,
                'body' => '{"error":9,"message":"Invalid session key - Please re-authenticate"}',
            ];
        };

        $api = new LastfmApi('API_KEY', 'SHARED_SECRET', $logger, $http);
        $outcome = $api->scrobble('SESSION_KEY', 'Track', 'Artist', null, 1700000000);

        $this->assertFalse($outcome->success, 'error-in-200 must not surface as a scrobble success');
        $this->assertSame(9, $outcome->errorCode);
        $this->assertSame('Invalid session key - Please re-authenticate', $outcome->errorMessage);
        $this->assertTrue($outcome->isInvalidSession(), 'error 9 must classify for the self-heal purge path');

        $envelopeLogs = array_values(array_filter(
            $warnings,
            static fn (array $w): bool => $w['message'] === 'Last.fm API error envelope',
        ));
        $this->assertCount(1, $envelopeLogs, 'the error-in-200 scrobble path must be logged once');
        $this->assertSame(9, $envelopeLogs[0]['context']['error']);
        $this->assertSame('track.scrobble', $envelopeLogs[0]['context']['method']);
        $this->assertStringContainsString('Invalid session key', (string) $envelopeLogs[0]['context']['message']);
    }

    /**
     * M-23 [review lastfm minors W-min49]: an "ok-shaped" 200 whose body is
     * not the documented `nowplaying` payload still counts as success
     * (permissive verdict survives shape variance) but must leave a debug
     * breadcrumb instead of passing in total silence.
     */
    public function testNowPlayingWithoutDocumentedEchoLogsDebugButStaysSuccess(): void
    {
        $debugs = [];

        $logger = new class ($debugs) extends NullLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            private array $debugsRef;

            public function __construct(array &$debugsRef)
            {
                $this->debugsRef = &$debugsRef;
            }

            public function debug($message, array $context = []): void
            {
                $this->debugsRef[] = ['message' => $message, 'context' => $context];
            }
        };

        $http = static function (): array {
            return ['status' => 200, 'body' => '{"looped":0,"ignored":0}'];
        };

        $api = new LastfmApi('API_KEY', 'SHARED_SECRET', $logger, $http, 0.0);
        $outcome = $api->updateNowPlaying('SESSION_KEY', 'Track', 'Artist');

        $this->assertTrue($outcome->success, 'absent echo must stay permissive (at most a debug line)');
        $this->assertCount(1, $debugs, 'an ok-shaped reply without the `nowplaying` echo logs one debug line');
        $this->assertStringContainsString('nowplaying', $debugs[0]['message']);
        $this->assertStringNotContainsString(
            '`',
            $debugs[0]['message'],
            'a runtime log message carries prose, not documentation markup',
        );
    }

    /** The documented echo path stays silent — no debug noise for healthy replies. */
    public function testNowPlayingWithDocumentedEchoLogsNoDebug(): void
    {
        $debugs = [];

        $logger = new class ($debugs) extends NullLogger {
            /** @var array<int, string> */
            private array $debugsRef;

            public function __construct(array &$debugsRef)
            {
                $this->debugsRef = &$debugsRef;
            }

            public function debug($message, array $context = []): void
            {
                $this->debugsRef[] = (string) $message;
            }
        };

        $http = static function (): array {
            return ['status' => 200, 'body' => '{"nowplaying":{"track":{"#text":"Track"}}}'];
        };

        $api = new LastfmApi('API_KEY', 'SHARED_SECRET', $logger, $http, 0.0);
        $this->assertTrue($api->updateNowPlaying('SESSION_KEY', 'Track', 'Artist')->success);
        $this->assertSame([], $debugs);
    }

    /**
     * A transport/non-2xx failure (decoded body unavailable) surfaces as a
     * typed failure with no error code — still never a success.
     */
    public function testTransportFailureSurfacesAsTypedFailureWithoutCode(): void
    {
        $http = static function (): array {
            return ['status' => 0, 'body' => ''];
        };

        $api = new LastfmApi('API_KEY', 'SHARED_SECRET', null, $http);
        $outcome = $api->updateNowPlaying('SESSION_KEY', 'Track', 'Artist');

        $this->assertFalse($outcome->success);
        $this->assertNull($outcome->errorCode);
        $this->assertNull($outcome->errorMessage);
        $this->assertTrue($outcome->transient, 'transport failure must classify as transient [M-05]');
    }

    /**
     * M-05 [review lastfm__srphp M-03]: transient vs terminal must be a
     * typed verdict on the outcome. Classification law: status 0
     * (transport), 429 and >=500 are transient; a 2xx error envelope with
     * code 29 (application rate limit) is transient; other envelope codes
     * (e.g. 16 permission denied) and unparseable 2xx bodies are terminal.
     * Retry-After is honored from the response headers (seconds form).
     */
    public function testTransientAndTerminalClassificationMatrix(): void
    {
        $script = static fn (array $response): callable => static function () use ($response): array {
            /** @var array{status:int, body:string, headers?:array<string,string|list<string>>} $response */
            return $response;
        };

        $cases = [
            '429 + Retry-After' => [
                ['status' => 429, 'body' => '', 'headers' => ['Retry-After' => '7']],
                true, 7,
            ],
            '5xx' => [['status' => 500, 'body' => ''], true, null],
            '200 + envelope 29' => [
                ['status' => 200, 'body' => '{"error":29,"message":"Application rate limit exceeded"}', 'headers' => []],
                true, null,
            ],
            '200 + envelope 16 (terminal)' => [
                ['status' => 200, 'body' => '{"error":16,"message":"Permission denied"}', 'headers' => []],
                false, null,
            ],
            '200 + unparseable (terminal, code-less)' => [
                ['status' => 200, 'body' => '<html>gateway hiccup</html>', 'headers' => []],
                false, null,
            ],
        ];

        foreach ($cases as $label => [$responses, $expectTransient, $expectRetryAfter]) {
            $api = new LastfmApi('API_KEY', 'SHARED_SECRET', null, $script($responses), 0.0);
            $outcome = $api->updateNowPlaying('SESSION_KEY', 'Track', 'Artist');
            $this->assertFalse($outcome->success, $label);
            $this->assertSame($expectTransient, $outcome->transient, $label);
            $this->assertSame($expectRetryAfter, $outcome->retryAfterSeconds, $label);
        }
    }

    /** Header lookup for Retry-After is case-insensitive and list-tolerant. */
    public function testRetryAfterHeaderIsCaseInsensitiveAcrossRunnerShapes(): void
    {
        $http = static fn (): array => [
            'status' => 503,
            'body' => '',
            'headers' => ['rEtRy-AfTeR' => ['12']],
        ];
        $api = new LastfmApi('K', 'S', null, $http, 0.0);
        $outcome = $api->scrobble('SK', 'T', 'A', null, 123);
        $this->assertTrue($outcome->transient);
        $this->assertSame(12, $outcome->retryAfterSeconds);
    }

    /**
     * M-05 pacing: the API self-paces per instance. With a 0.05s minimum
     * interval, two dispatches must be at least ~50ms apart; with 0.0 the
     * gate is disabled (test hosts use the disabled form).
     */
    public function testMinimumIntervalPacesConsecutiveDispatches(): void
    {
        $calls = 0;
        $http = static function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{"ok":1}'];
        };

        $paced = new LastfmApi('K', 'S', null, $http, 0.05);
        $start = microtime(true);
        $paced->updateNowPlaying('SK', 'T1', 'A');
        $paced->updateNowPlaying('SK', 'T2', 'A');
        $elapsed = microtime(true) - $start;

        $this->assertSame(2, $calls);
        $this->assertGreaterThanOrEqual(0.045, $elapsed, 'second dispatch must wait out the pacing window');

        $unpaced = new LastfmApi('K', 'S', null, $http, 0.0);
        $start = microtime(true);
        $unpaced->updateNowPlaying('SK', 'T1', 'A');
        $unpaced->updateNowPlaying('SK', 'T2', 'A');
        $this->assertLessThan(0.04, microtime(true) - $start, 'interval 0 disables pacing');
    }

    /**
     * PG-149 twin (review lastfm__srphp info): the response payload is
     * bounded BEFORE decode. A compromised or DNS-repointed endpoint that
     * answers with an oversized body can no longer balloon a resident
     * worker through json_decode; the body is treated as unparseable, which
     * keeps the honest failure shape (no fabricated envelope code — the 29
     * inside the giant body must NOT surface, that is the decode-refusal
     * proof) and logs one warning naming the ceiling.
     */
    public function testOversizedResponseBodyIsRefusedBeforeDecode(): void
    {
        $giant = '{"error":29,"message":"' . str_repeat('a', LastfmApi::MAX_RESPONSE_BYTES) . '"}';
        $this->assertGreaterThan(LastfmApi::MAX_RESPONSE_BYTES, strlen($giant));

        /** @var array<int, array{message: string, context: array<string, mixed>}> $warnings */
        $warnings = [];
        $logger = new class ($warnings) extends NullLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            private array $warningsRef;

            public function __construct(array &$warningsRef)
            {
                $this->warningsRef = &$warningsRef;
            }

            public function warning($message, array $context = []): void
            {
                $this->warningsRef[] = ['message' => $message, 'context' => $context];
            }
        };

        $http = static function () use ($giant): array {
            return ['status' => 200, 'body' => $giant];
        };

        $api = new LastfmApi('K', 'S', $logger, $http, 0.0);
        $outcome = $api->updateNowPlaying('SK', 'T', 'A');

        $this->assertFalse($outcome->success);
        $this->assertNull($outcome->errorCode, 'a refused body must never be decoded into an envelope verdict');
        $this->assertFalse($outcome->transient, 'a body the client refuses to read is not a retryable hiccup');

        $messages = array_column($warnings, 'message');
        $this->assertContains('Last.fm API response exceeded the decode size ceiling', $messages);
        $ceiling = array_values(array_filter(
            $warnings,
            static fn (array $w): bool => $w['message'] === 'Last.fm API response exceeded the decode size ceiling',
        ));
        $this->assertSame(LastfmApi::MAX_RESPONSE_BYTES, $ceiling[0]['context']['max_bytes']);
        $this->assertSame(strlen($giant), $ceiling[0]['context']['bytes']);
    }

    /**
     * The ceiling boundary: a body of exactly MAX_RESPONSE_BYTES is honest
     * traffic (rate-limit envelope, code 29 → transient) and must still
     * decode — the cap refuses oversized payloads, not large-but-legal ones.
     */
    public function testBodyAtExactlyTheCeilingStillDecodes(): void
    {
        $prefix = '{"error":29,"message":"';
        $pad = LastfmApi::MAX_RESPONSE_BYTES - strlen($prefix) - 2;
        $body = $prefix . str_repeat('b', $pad) . '"}';
        $this->assertSame(LastfmApi::MAX_RESPONSE_BYTES, strlen($body));

        $http = static function () use ($body): array {
            return ['status' => 200, 'body' => $body];
        };

        $api = new LastfmApi('K', 'S', null, $http, 0.0);
        $outcome = $api->updateNowPlaying('SK', 'T', 'A');

        $this->assertFalse($outcome->success);
        $this->assertSame(29, $outcome->errorCode);
        $this->assertTrue($outcome->transient);
    }
}

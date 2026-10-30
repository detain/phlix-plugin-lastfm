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
    }
}

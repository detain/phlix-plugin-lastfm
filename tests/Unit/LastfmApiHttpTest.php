<?php

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

        // Must return false on non-2xx, not null.
        $this->assertFalse($result);

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
        // status=0 is not 2xx, so result should be false (not null).
        $this->assertFalse($result);
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
}

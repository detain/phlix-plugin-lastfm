<?php

/**
 * Unit::LastfmPluginTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Phlix\Media\Library\ItemRepository;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Additional unit tests for {@see LastfmPlugin}.
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
#[CoversClass(\Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin::class)]
#[AllowMockObjectsWithoutExpectations]
final class LastfmPluginTest extends TestCase
{
    public function testGetPluginTypeReturnsCorrectType(): void
    {
        $plugin = new LastfmPlugin();

        $this->assertSame('scrobbler', $plugin->getPluginType());
    }

    public function testGetPluginNameReturnsCorrectName(): void
    {
        $plugin = new LastfmPlugin();

        $this->assertSame('lastfm', $plugin->getPluginName());
    }

    public function testOnDisableClearsScrobbler(): void
    {
        $conn = $this->createMock(\Workerman\MySQL\Connection::class);
        $sessions = new LastfmSessionRepository($conn);
        $container = new SimpleTestContainer([LastfmSessionRepository::class => $sessions]);

        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);
        $plugin->onEnable($container);

        // Verify scrobbler was created
        $this->assertNotEmpty($plugin->subscribedEvents());

        // Now disable
        $plugin->onDisable();

        // After disable, subscribedEvents should return empty
        $this->assertEmpty($plugin->subscribedEvents());
    }

    public function testSubscribedEventsReturnsEmptyWhenNotEnabled(): void
    {
        $plugin = new LastfmPlugin();

        // Without configure() and onEnable(), scrobbler is null
        $this->assertEmpty($plugin->subscribedEvents());
    }

    public function testOnEnableWithUnusableConfigDoesNotCreateScrobbler(): void
    {
        $plugin = new LastfmPlugin();
        // Configure with missing api_key and shared_secret
        $plugin->configure([
            'enabled'       => false,
            'api_key'       => '',
            'shared_secret' => '',
        ]);

        $container = new SimpleTestContainer([]);

        $plugin->onEnable($container);

        // With unusable config, no scrobbler should be created
        $this->assertEmpty($plugin->subscribedEvents());
    }

    public function testOnEnableWithPartialConfigDoesNotCreateScrobbler(): void
    {
        $plugin = new LastfmPlugin();
        // Configure with only api_key, missing shared_secret
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => '', // missing
        ]);

        $container = new SimpleTestContainer([]);

        $plugin->onEnable($container);

        // With unusable config (missing shared_secret), no scrobbler should be created
        $this->assertEmpty($plugin->subscribedEvents());
    }

    public function testResolveSessionsThrowsNonNotFoundException(): void
    {
        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);

        // Container that throws a generic RuntimeException (not NotFoundExceptionInterface)
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Generic error');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $plugin->onEnable($container);

        // Should not throw, should handle gracefully
        $this->assertEmpty($plugin->subscribedEvents());
    }

    public function testResolveTrackResolverWhenItemRepositoryNotAvailable(): void
    {
        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);

        // Container with sessions but without ItemRepository
        $conn = $this->createMock(\Workerman\MySQL\Connection::class);
        $sessions = new LastfmSessionRepository($conn);
        $container = new SimpleTestContainer([LastfmSessionRepository::class => $sessions]);

        $plugin->onEnable($container);

        // With sessions but no ItemRepository, plugin should still enable
        $this->assertNotEmpty($plugin->subscribedEvents());
    }

    public function testCreateAsyncHttpRunnerFallbackWhenWorkermanHttpClientNotAvailable(): void
    {
        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);

        $conn = $this->createMock(\Workerman\MySQL\Connection::class);
        $sessions = new LastfmSessionRepository($conn);
        $container = new SimpleTestContainer([LastfmSessionRepository::class => $sessions]);

        // This should work without throwing even without workerman/http-client
        $plugin->onEnable($container);

        // The plugin should enable successfully using the fallback HTTP runner
        $this->assertNotEmpty($plugin->subscribedEvents());
    }

    public function testMakeSchemaEnsurerWhenContainerDoesNotHaveMigrationRunner(): void
    {
        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);

        // Container WITHOUT the migration runner
        $conn = $this->createMock(\Workerman\MySQL\Connection::class);
        $sessions = new LastfmSessionRepository($conn);
        $container = new SimpleTestContainer([LastfmSessionRepository::class => $sessions]);

        $plugin->onEnable($container);

        // Plugin should still enable even without migration runner
        $this->assertNotEmpty($plugin->subscribedEvents());
    }

    public function testSubscribedEventsReturnsClosures(): void
    {
        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);

        $conn = $this->createMock(\Workerman\MySQL\Connection::class);
        $sessions = new LastfmSessionRepository($conn);
        $container = new SimpleTestContainer([LastfmSessionRepository::class => $sessions]);

        $plugin->onEnable($container);
        $events = $plugin->subscribedEvents();

        $this->assertArrayHasKey(PlaybackStarted::class, $events);
        $this->assertArrayHasKey(PlaybackStopped::class, $events);
        $this->assertIsCallable($events[PlaybackStarted::class]);
        $this->assertIsCallable($events[PlaybackStopped::class]);
    }

    public function testBuildCooperativeRunnerWithSynchronousThrow(): void
    {
        // Test that when client->request() throws synchronously, it returns zero-status
        /** @var \Workerman\Http\Client&\PHPUnit\Framework\MockObject\MockObject $client */
        $client = $this->createMock(\Workerman\Http\Client::class);
        $client->method('request')->willThrowException(new \RuntimeException('sync error'));

        $runner = LastfmPlugin::buildCooperativeRunner($client);
        $result = $runner('https://example.com', 'body', []);

        // Should return zero status for synchronous throw
        $this->assertSame(0, $result['status']);
        $this->assertSame('', $result['body']);
    }

    public function testBuildCooperativeRunnerTimeoutPath(): void
    {
        // When the rendezvous never settles within the ceiling, the runner
        // must fail fast to the transient zero-status transport shape —
        // without ever spinning (M-07: the 1ms usleep wall is gone).
        /** @var \Workerman\Http\Client&\PHPUnit\Framework\MockObject\MockObject $client */
        $client = $this->createMock(\Workerman\Http\Client::class);

        // Never call success/error, so the await must time out.
        $client->expects(self::once())
            ->method('request')
            ->willReturnCallback(function (string $url, array $options): void {
                // Drop the callbacks entirely - simulate no response arriving.
            });

        $receivedTimeout = null;
        $factory = static function (float $timeout) use (&$receivedTimeout): array {
            $receivedTimeout = $timeout;

            return [
                static function (): void {},
                static fn (): bool => false, // await reports: never settled
            ];
        };

        $runner = LastfmPlugin::buildCooperativeRunner($client, 2.0, $factory);

        $start = microtime(true);
        $result = $runner('https://example.com', 'body', []);
        $elapsed = microtime(true) - $start;

        // The ceiling is handed to the rendezvous factory, not consumed here.
        self::assertSame(2.0, $receivedTimeout);
        // Unsettled await => zero-status transport failure, immediately.
        self::assertSame(['status' => 0, 'body' => '', 'headers' => []], $result);
        self::assertLessThan(0.5, $elapsed, 'timed-out rendezvous must never spin toward the wall');
    }

    public function testCooperativeContextAvailableIsFalseWithoutSwoole(): void
    {
        // Deterministic in this venue: no swoole extension => no context.
        self::assertFalse(LastfmPlugin::cooperativeContextAvailable());
    }

    public function testCooperativeRunnerSignalsRendezvousOnlyOnce(): void
    {
        // success firing, then error firing, must push exactly one slot.
        /** @var \Workerman\Http\Client&\PHPUnit\Framework\MockObject\MockObject $client */
        $client = $this->createMock(\Workerman\Http\Client::class);
        $client->method('request')->willReturnCallback(function (string $url, array $options): void {
            /** @var callable $success */
            $success = $options['success'];
            /** @var callable $error */
            $error = $options['error'];
            $success(new \Workerman\Http\Response(200, [], 'ok'));
            $error(new \RuntimeException('late'));
        });

        $signalCount = 0;
        $factory = static function (float $timeout) use (&$signalCount): array {
            return [
                static function () use (&$signalCount): void {
                    $signalCount++;
                },
                static fn (): bool => true,
            ];
        };

        $runner = LastfmPlugin::buildCooperativeRunner($client, 1.0, $factory);
        $result = $runner('https://example.com', 'body', []);

        self::assertSame(1, $signalCount);
        self::assertSame(200, $result['status']);
        self::assertSame('ok', $result['body']);
    }

    public function testCooperativeRunnerCapturesResponseHeaders(): void
    {
        // C6 seam: Retry-After on a 429 must reach the dispatch() result.
        /** @var \Workerman\Http\Client&\PHPUnit\Framework\MockObject\MockObject $client */
        $client = $this->createMock(\Workerman\Http\Client::class);
        $client->method('request')->willReturnCallback(function (string $url, array $options): void {
            /** @var callable $success */
            $success = $options['success'];
            $success(new \Workerman\Http\Response(429, ['Retry-After' => ['7']], 'slow down'));
        });

        $factory = static fn (float $timeout): array => [
            static function (): void {},
            static fn (): bool => true,
        ];

        $runner = LastfmPlugin::buildCooperativeRunner($client, 1.0, $factory);
        $result = $runner('https://example.com', 'body', []);

        self::assertSame(429, $result['status']);
        self::assertArrayHasKey('Retry-After', $result['headers']);
    }

    public function testDefaultRendezvousOutsideCoroutineFailsFastWithoutSpin(): void
    {
        // Venue law (no swoole here): the default holder reports unsettled
        // until the signal fires - it never blocks and never fakes success.
        /** @var \Workerman\Http\Client&\PHPUnit\Framework\MockObject\MockObject $client */
        $client = $this->createMock(\Workerman\Http\Client::class);
        $client->method('request')->willReturnCallback(function (string $url, array $options): void {
            // fire-and-forget: callbacks never invoked (unreachable async)
        });

        $runner = LastfmPlugin::buildCooperativeRunner($client);

        $start = microtime(true);
        $result = $runner('https://example.com', 'body', []);
        $elapsed = microtime(true) - $start;

        self::assertSame(['status' => 0, 'body' => '', 'headers' => []], $result);
        self::assertLessThan(0.5, $elapsed);
    }

    public function testContextGatedRunnerUsesAsyncPathInsideCoroutine(): void
    {
        $async = static fn (string $u, string $b, array $h): array => ['status' => 1, 'body' => 'async', 'headers' => []];
        $blocking = static fn (string $u, string $b, array $h): array => ['status' => 1, 'body' => 'blocking', 'headers' => []];

        /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $runner = LastfmPlugin::contextGatedRunner($async, $blocking, static fn (): bool => true, $logger);

        self::assertSame('async', $runner('u', 'b', [])['body']);
    }

    public function testContextGatedRunnerFallsBackBlockingWithSingleNotice(): void
    {
        $async = static fn (string $u, string $b, array $h): array => ['status' => 1, 'body' => 'async', 'headers' => []];
        $blocking = static fn (string $u, string $b, array $h): array => ['status' => 1, 'body' => 'blocking', 'headers' => []];

        /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $runner = LastfmPlugin::contextGatedRunner($async, $blocking, static fn (): bool => false, $logger);

        self::assertSame('blocking', $runner('u', 'b', [])['body']);
        self::assertSame('blocking', $runner('u', 'b', [])['body']);
    }

    /**
     * PG-146 (review lastfm__secaudit M-1): the async client must carry an
     * EXPLICIT strict TLS context. The pinned vendor default is
     * verify_peer=false / verify_peer_name=false / allow_self_signed=true
     * (workerman/http-client v3.1.3, ConnectionPool::create()), and the
     * vendor merges our subtree OVER those defaults by REPLACING it whole —
     * so every key that must be on has to be present, including peer_name
     * (the hostname the vendor would auto-extract is discarded with the
     * default subtree). Without this the production POST carries api_key,
     * the per-user session key and api_sig to any self-signed MITM.
     */
    public function testAsyncClientOptionsCarryACompleteStrictTlsContext(): void
    {
        $options = LastfmPlugin::asyncClientOptions();

        self::assertSame(10, $options['timeout']);
        self::assertSame(3, $options['connect_timeout']);

        $ssl = $options['context']['ssl'] ?? null;
        self::assertIsArray($ssl);
        ksort($ssl);
        self::assertSame([
            'allow_self_signed' => false,
            'peer_name'         => 'ws.audioscrobbler.com',
            'verify_peer'       => true,
            'verify_peer_name'  => true,
        ], $ssl, 'the ssl subtree is replaced wholesale by the vendor merge — it must be complete');
    }

    /** The pinned peer identity follows the API endpoint constant, not a stray literal. */
    public function testTlsPeerNameFollowsTheApiRootHost(): void
    {
        $ssl = LastfmPlugin::asyncClientOptions()['context']['ssl'];
        self::assertSame(LastfmApi::API_HOST, $ssl['peer_name']);
        self::assertSame(LastfmApi::API_HOST, parse_url(LastfmApi::API_ROOT, PHP_URL_HOST));
    }

    /**
     * Executed roundtrip through the real pinned vendor: the options the
     * seam returns are exactly what the vendor ConnectionPool stores — the
     * same array its create() merges over the insecure defaults.
     */
    public function testAsyncClientOptionsAreHandedToTheVendorClientVerbatim(): void
    {
        $options = LastfmPlugin::asyncClientOptions();
        $client = new \Workerman\Http\Client($options);

        $pool = (new \ReflectionProperty(\Workerman\Http\Client::class, '_connectionPool'))->getValue($client);
        self::assertInstanceOf(\Workerman\Http\ConnectionPool::class, $pool);

        $stored = (new \ReflectionProperty(\Workerman\Http\ConnectionPool::class, 'options'))->getValue($pool);
        self::assertSame($options['context'], $stored['context']);
        self::assertSame($options['timeout'], $stored['timeout']);
        self::assertSame($options['connect_timeout'], $stored['connect_timeout']);
    }

    /**
     * Code-shape pin (the S440 guard family idiom): the production client
     * must be built with the seam's options. An edit that bypasses
     * asyncClientOptions() with an inline literal — reintroducing the
     * vendor defaults — reddens here even if every other test stays green.
     */
    public function testTheAsyncClientIsConstructedThroughTheStrictOptionsSeam(): void
    {
        $method = new \ReflectionMethod(LastfmPlugin::class, 'createAsyncHttpRunner');
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertMatchesRegularExpression(
            '/new\s+\\\\Workerman\\\\Http\\\\Client\(\s*self::asyncClientOptions\(\)\s*\)/',
            $body,
            'createAsyncHttpRunner() must construct the Client from asyncClientOptions()',
        );
    }
}

/**
 * Simple PSR-11 test container for LastfmPluginTest.
 */
final class SimpleTestContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string, mixed> $services */
    public function __construct(
        private array $services,
        private array $hasIds = [],
    ) {
    }

    public function get(string $id): mixed
    {
        $this->requested[] = $id;
        if (!array_key_exists($id, $this->services)) {
            throw new class ('not found') extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        $this->requested[] = $id;
        if (!empty($this->hasIds)) {
            return in_array($id, $this->hasIds, true);
        }
        return array_key_exists($id, $this->services);
    }
}

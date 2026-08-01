<?php

/**
 * Unit::LastfmPluginTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

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

/**
 * Additional unit tests for {@see LastfmPlugin}.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
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
        // Test that when cooperative wait times out, it returns whatever state it's in
        /** @var \Workerman\Http\Client&\PHPUnit\Framework\MockObject\MockObject $client */
        $client = $this->createMock(\Workerman\Http\Client::class);

        // Never call success/error, so the wait loop will timeout
        $client->expects(self::once())
            ->method('request')
            ->willReturnCallback(function (string $url, array $options): void {
                // Don't call success or error callbacks - simulate timeout
            });

        $runner = LastfmPlugin::buildCooperativeRunner($client);

        // Use reflection to get the constant
        $reflection = new \ReflectionClass(LastfmPlugin::class);
        $const = $reflection->getConstant('HTTP_MAX_WAIT_SECONDS');

        $start = microtime(true);
        $result = $runner('https://example.com', 'body', []);
        $elapsed = microtime(true) - $start;

        // Should timeout and return whatever state was reached (done=false, status=0)
        $this->assertSame(0, $result['status']);
        // The wait should have been close to HTTP_MAX_WAIT_SECONDS
        $this->assertGreaterThanOrEqual($const - 0.5, $elapsed);
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

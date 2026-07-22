<?php

/**
 * Unit::LastfmBootSafetyTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\Database\LastfmMigrationRunner;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionObject;

/**
 * Boot-safety consequence tests (the item-5c3 landmine).
 *
 * These assert the CONSEQUENCES of the onEnable/deferred-schema split:
 *  - onEnable() runs NO migrations and issues ZERO DB queries at boot.
 *  - the schema-ensure runs lazily on FIRST event and is idempotent
 *    (fires at most once across many events).
 *  - a scrobble drives the async workerman/http-client via the canonical
 *    cooperative-wait runner (not a blocking runner).
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler
 */
final class LastfmBootSafetyTest extends TestCase
{
    /**
     * onEnable() must NOT touch the migration runner and must NOT query the
     * database. If a future edit moves migrations back into onEnable, the
     * container will be asked for LastfmMigrationRunner and/or the session
     * connection will be queried — both are asserted here.
     */
    public function testOnEnableRunsNoMigrationsAndZeroDbIo(): void
    {
        $conn = $this->createMock(\Workerman\MySQL\Connection::class);
        // Boot must issue ZERO DB queries.
        $conn->expects(self::never())->method('query');

        $sessions = new LastfmSessionRepository($conn);
        $container = new RecordingContainer([
            LastfmSessionRepository::class => $sessions,
        ]);

        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);
        $plugin->onEnable($container);

        // The migration runner must never be requested from the container
        // during boot — migrations are now deferred to first event.
        self::assertNotContains(
            LastfmMigrationRunner::class,
            $container->requested,
            'onEnable() must not resolve the migration runner (no boot migrations)',
        );

        // Sanity: onEnable DID wire the scrobbler (so subscribedEvents works).
        self::assertNotEmpty($plugin->subscribedEvents());
    }

    /**
     * The deferred schema-ensure hook must fire lazily on the FIRST event,
     * and be idempotent: exactly one invocation across many events.
     */
    public function testSchemaEnsureRunsOnceAcrossManyEvents(): void
    {
        $calls = 0;
        $ensure = static function () use (&$calls): void {
            $calls++;
        };

        /** @var LastfmApi&\PHPUnit\Framework\MockObject\MockObject $api */
        $api = $this->createMock(LastfmApi::class);
        /** @var LastfmSessionRepository&\PHPUnit\Framework\MockObject\MockObject $sessions */
        $sessions = $this->createMock(LastfmSessionRepository::class);
        $sessions->method('findByUserId')->willReturn(null);

        $scrobbler = new LastfmScrobbler(
            $api,
            $sessions,
            static fn (string $_id): ?array => null,
            null,
            $ensure,
        );

        // Before any event the hook has NOT run (deferred, not at construct).
        self::assertSame(0, $calls, 'schema-ensure must not run at construct/boot');

        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u1', 'm', 'd', 0));
        $scrobbler->onPlaybackStopped(new PlaybackStopped('s', 'u1', 'm', 'd', 1_000_000_000, false));
        $scrobbler->onPlaybackStarted(new PlaybackStarted('s', 'u2', 'm2', 'd', 0));

        self::assertSame(1, $calls, 'schema-ensure must run exactly once (idempotent guard)');
    }

    /**
     * A scrobble drives the async workerman/http-client through the
     * cooperative-wait runner: the request fires with success/error
     * callbacks and the runner returns the response the client delivered.
     */
    public function testCooperativeRunnerDrivesAsyncClient(): void
    {
        $captured = ['method' => null, 'url' => null, 'data' => null];

        /** @var \Workerman\Http\Client&\PHPUnit\Framework\MockObject\MockObject $client */
        $client = $this->createMock(\Workerman\Http\Client::class);
        $client->expects(self::once())
            ->method('request')
            ->willReturnCallback(function (string $url, array $options) use (&$captured) {
                $captured['method'] = $options['method'] ?? null;
                $captured['url']    = $url;
                $captured['data']   = $options['data'] ?? null;
                // Simulate the event loop delivering a successful response.
                ($options['success'])(new \Workerman\Http\Response(200, [], '{"status":"ok"}'));
                return null;
            });

        $runner = LastfmPlugin::buildCooperativeRunner($client);
        $result = $runner(
            LastfmApi::API_ROOT,
            'method=track.scrobble&artist=A&track=T',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        );

        self::assertSame('POST', $captured['method'], 'runner must POST via the async client');
        self::assertSame(LastfmApi::API_ROOT, $captured['url']);
        self::assertSame('method=track.scrobble&artist=A&track=T', $captured['data']);
        self::assertSame(200, $result['status']);
        self::assertSame('{"status":"ok"}', $result['body']);
    }

    /**
     * The cooperative runner surfaces transport errors as a zero-status
     * result (never throws), so LastfmApi treats them like a network fail.
     */
    public function testCooperativeRunnerSurfacesErrorAsZeroStatus(): void
    {
        /** @var \Workerman\Http\Client&\PHPUnit\Framework\MockObject\MockObject $client */
        $client = $this->createMock(\Workerman\Http\Client::class);
        $client->method('request')->willReturnCallback(function (string $url, array $options) {
            ($options['error'])(new \RuntimeException('dns fail'));
            return null;
        });

        $runner = LastfmPlugin::buildCooperativeRunner($client);
        $result = $runner(LastfmApi::API_ROOT, 'body', []);

        self::assertSame(0, $result['status']);
        self::assertSame('', $result['body']);
    }
}

/**
 * Minimal PSR-11 container that records every id it is asked for, so tests
 * can assert which services onEnable resolves (and, crucially, which it
 * does NOT).
 */
final class RecordingContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string, mixed> $services */
    public function __construct(private array $services)
    {
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
        return array_key_exists($id, $this->services);
    }
}

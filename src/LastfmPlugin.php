<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

use Phlix\Media\Library\ItemRepository;
use Phlix\Plugins\Scrobbler\Lastfm\Database\LastfmMigrationRunner;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Plugin entry class for the Last.fm scrobble integration.
 *
 * Implements the standard Phlix plugin {@see LifecycleInterface}: on
 * `enable` it resolves dependencies from the container, builds a
 * {@see LastfmScrobbler}, and exposes it via
 * {@see self::subscribedEvents()} for the PSR-14 dispatcher to wire up.
 *
 * On `disable` the scrobbler is released so the next enable rebuilds it.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmPlugin implements LifecycleInterface
{
    public const PLUGIN_TYPE = 'scrobbler';
    public const PLUGIN_NAME = 'lastfm';

    private ?LastfmScrobbler $scrobbler = null;
    private LoggerInterface $logger;

    /**
     * @param LastfmConfig         $config Wraps `config/lastfm.php`.
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     */
    public function __construct(
        private readonly LastfmConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Resolves dependencies and registers the scrobbler with the
     * dispatcher.
     *
     * Resolution graph:
     *   - {@see LastfmApi} (constructed inline from config + container logger)
     *   - {@see LastfmSessionRepository} (from container — DB-backed)
     *   - track-resolver callable (closure over `ItemRepository`)
     *
     * Throws nothing — when config is unusable the plugin simply records
     * a debug log line and bails. The loader treats that as a no-op
     * enable and skips listener registration.
     */
    public function onEnable(ContainerInterface $container): void
    {
        // 1. Run pending migrations so the lastfm_sessions table exists before
        //    we try to resolve or use LastfmSessionRepository.
        $this->runMigrations($container);

        if (!$this->config->isUsable()) {
            $this->logger->debug('Last.fm plugin not enabled: config incomplete or disabled');
            return;
        }

        $sessions = $this->resolveSessions($container);
        if ($sessions === null) {
            $this->logger->warning('Last.fm plugin: LastfmSessionRepository unavailable');
            return;
        }
        $resolveTrack = $this->resolveTrackResolver($container);

        $api = new LastfmApi(
            $this->config->apiKey,
            $this->config->sharedSecret,
            $this->logger,
            $this->createAsyncHttpRunner()
        );
        $this->scrobbler = new LastfmScrobbler($api, $sessions, $resolveTrack, $this->logger);

        $this->logger->info('Last.fm plugin enabled');
    }

    /**
     * Release the in-memory scrobbler so the next enable rebuilds it.
     */
    public function onDisable(): void
    {
        $this->scrobbler = null;
    }

    /**
     * Subscriptions returned to the loader:
     *  - `PlaybackStarted::class` → `LastfmScrobbler::onPlaybackStarted`
     *  - `PlaybackStopped::class` → `LastfmScrobbler::onPlaybackStopped`
     *
     * Returns an empty array when the plugin failed to enable, ensuring
     * the loader subscribes nothing.
     *
     * @return array<class-string, callable>
     */
    public function subscribedEvents(): array
    {
        if ($this->scrobbler === null) {
            return [];
        }
        $scrobbler = $this->scrobbler;
        return [
            PlaybackStarted::class => static function (PlaybackStarted $event) use ($scrobbler): void {
                $scrobbler->onPlaybackStarted($event);
            },
            PlaybackStopped::class => static function (PlaybackStopped $event) use ($scrobbler): void {
                $scrobbler->onPlaybackStopped($event);
            },
        ];
    }

    /**
     * @return string The plugin type ('scrobbler').
     */
    public function getPluginType(): string
    {
        return self::PLUGIN_TYPE;
    }

    /**
     * @return string The plugin name ('lastfm').
     */
    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    /**
     * Resolve a {@see LastfmSessionRepository} from the container, or
     * null if the binding is missing.
     */
    private function resolveSessions(ContainerInterface $container): ?LastfmSessionRepository
    {
        try {
            $sessions = $container->get(LastfmSessionRepository::class);
        } catch (\Throwable $e) {
            $this->logger->debug('Last.fm: container lookup for sessions failed', ['error' => $e->getMessage()]);
            return null;
        }
        return $sessions instanceof LastfmSessionRepository ? $sessions : null;
    }

    /**
     * Run any pending Last.fm plugin database migrations.
     *
     * Attempts to resolve a {@see LastfmMigrationRunner} from the container
     * and calls {@see LastfmMigrationRunner::run()}. If the runner is not
     * registered in the container (e.g. in test environments), the plugin
     * logs a debug message and continues — the host is responsible for
     * ensuring the table exists before using the repository.
     */
    private function runMigrations(ContainerInterface $container): void
    {
        if (!$container->has(LastfmMigrationRunner::class)) {
            $this->logger->debug('Last.fm migration runner not registered in container, skipping');
            return;
        }
        try {
            /** @var LastfmMigrationRunner $runner */
            $runner = $container->get(LastfmMigrationRunner::class);
            $runner->run();
            $this->logger->debug('Last.fm migrations completed');
        } catch (\Throwable $e) {
            $this->logger->warning('Last.fm migration runner threw', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Build the track-metadata resolver. We deliberately do not require
     * a hard dependency on `ItemRepository` so plugin tests can stub it.
     *
     * @return callable(string): ?array{title: string, artist: string, album: ?string, duration_seconds: ?int}
     */
    private function resolveTrackResolver(ContainerInterface $container): callable
    {
        try {
            /** @var mixed $repoRaw */
            $repoRaw = $container->get(ItemRepository::class);
        } catch (\Throwable) {
            $repoRaw = null;
        }
        if (!$repoRaw instanceof ItemRepository) {
            return static fn (string $_id) => null;
        }
        $repo = $repoRaw;
        return static function (string $mediaItemId) use ($repo): ?array {
            $row = $repo->findById($mediaItemId);
            if ($row === null) {
                return null;
            }
            /** @var array<string, mixed> $meta */
            $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $title = is_string($row['name'] ?? null) ? $row['name'] : null;
            $artist = is_string($meta['artist'] ?? null) ? $meta['artist']
                : (is_string($row['artist'] ?? null) ? $row['artist'] : null);
            if ($title === null || $artist === null) {
                return null;
            }
            $durationTicksRaw = $row['duration_ticks'] ?? 0;
            $durationTicks = is_numeric($durationTicksRaw) ? (int) $durationTicksRaw : 0;
            $album = is_string($meta['album'] ?? null) ? $meta['album'] : null;
            return [
                'title'            => $title,
                'artist'           => $artist,
                'album'            => $album,
                'duration_seconds' => $durationTicks > 0 ? (int) ($durationTicks / 10_000_000) : null,
            ];
        };
    }

    /**
     * Build an async HTTP runner backed by workerman/http-client.
     *
     * This runner integrates with the Workerman event loop instead of
     * blocking the worker on each scrobble/now-playing HTTP POST.
     * TLS verification is kept ON (default).
     *
     * If workerman/http-client is not available, falls back to a sync
     * runner that wraps the stream-based default — callers will still
     * receive correct results but the worker will block on I/O.
     *
     * @return callable(string $url, string $body, array<string, string> $headers): array{status: int, body: string}
     */
    private function createAsyncHttpRunner(): callable
    {
        if (!class_exists(\Workerman\Http\Client::class)) {
            $this->logger->warning('Last.fm: workerman/http-client not available, using sync fallback');
            return \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi::defaultHttp();
        }

        /** @var \Workerman\Http\Client $client */
        $client = new \Workerman\Http\Client();
        // 3-second connect timeout, 10-second transfer timeout — keeps things snappy.
        // TLS verification is ON by default (do not disable peer/host verification).
        $client->timeout = 10;
        $client->connectTimeout = 3;

        return static function (string $url, string $body, array $headers) use ($client): array {
            try {
                /** @var \React\Promise\PromiseInterface $promise */
                $promise = $client->post($url, $body, $headers);
                /** @var \Workerman\Http\Response $response */
                $response = $promise->wait();
                return [
                    'status' => $response->getStatusCode(),
                    'body'   => $response->getBody()->getContents(),
                ];
            } catch (\Throwable) {
                // Transport or DNS error — return distinguishable zero-status
                // failure so caller treats it the same as a network-level error.
                return ['status' => 0, 'body' => ''];
            }
        };
    }
}

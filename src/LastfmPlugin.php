<?php

/**
 * LastfmPlugin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

use Phlix\Media\Library\ItemRepository;
use Phlix\Plugins\Scrobbler\Lastfm\Database\LastfmMigrationRunner;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Phlix\Shared\Plugin\ConfigurableInterface;
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
final class LastfmPlugin implements LifecycleInterface, ConfigurableInterface
{
    public const PLUGIN_TYPE = 'scrobbler';
    public const PLUGIN_NAME = 'lastfm';

    private ?LastfmScrobbler $scrobbler = null;
    private LoggerInterface $logger;
    private LastfmConfig $config;

    /**
     * The constructor MUST stay autowirable — the host loader instantiates the
     * entry class through its PSR-11 container, which cannot build a
     * {@see LastfmConfig} (its `apiKey`/`sharedSecret` are required, un-guessable
     * values). So the config defaults to an empty/disabled one here and the real
     * settings arrive via {@see self::configure()} before `onEnable()`.
     *
     * @param LastfmConfig|null    $config Optional pre-built config (tests inject one).
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     */
    public function __construct(
        ?LastfmConfig $config = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->config = $config ?? LastfmConfig::fromArray([]);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Receive the plugin's persisted settings from the host.
     *
     * Called once by the loader between construction and {@see self::onEnable()},
     * so `onEnable()` sees the configured `api_key`/`shared_secret`/etc.
     *
     * @param array<string, mixed> $settings Persisted settings (the manifest's
     *        `settings` key-set: `enabled`, `api_key`, `shared_secret`).
     */
    public function configure(array $settings): void
    {
        $this->config = LastfmConfig::fromArray($settings);
    }

    /**
     * WIRE step (boot-safe): construct + subscribe ONLY. NO migrations, NO
     * DB queries, NO network — this runs across every worker at boot and
     * MUST never block or throw (the item-5c3 landmine).
     *
     * Resolution graph (all lazy/construct-only):
     *   - {@see LastfmApi} (constructed inline from config + async runner)
     *   - {@see LastfmSessionRepository} (resolved from container — the
     *     container just *constructs* it; no query is issued here)
     *   - track-resolver callable (a closure over `ItemRepository`; the
     *     `findById` lookup is deferred until an event fires)
     *   - deferred schema-ensure hook (see {@see self::makeSchemaEnsurer()})
     *     — the DB migration runs lazily on the FIRST playback event, not
     *     here at boot.
     *
     * Throws nothing — when config is unusable the plugin simply records
     * a debug log line and bails. The loader treats that as a no-op
     * enable and skips listener registration.
     */
    public function onEnable(ContainerInterface $container): void
    {
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
        // Boot-safe split: hand the scrobbler a DEFERRED, idempotent
        // schema-ensure hook instead of running migrations here. The
        // migration fires lazily on the first playback event this worker
        // actually processes — never across ~14 workers at boot.
        $this->scrobbler = new LastfmScrobbler(
            $api,
            $sessions,
            $resolveTrack,
            $this->logger,
            $this->makeSchemaEnsurer($container),
        );

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
     * Build a DEFERRED, idempotent schema-ensure hook.
     *
     * The returned closure is handed to the {@see LastfmScrobbler}, which
     * invokes it (guarded, at most once) on the FIRST playback event it
     * processes — NOT at boot/enable. This is the boot-safety split for the
     * item-5c3 landmine: `onEnable()` must do zero I/O, so migrations can
     * no longer run there.
     *
     * When invoked, the closure resolves a {@see LastfmMigrationRunner}
     * from the container and calls {@see LastfmMigrationRunner::run()}. The
     * migration itself is `CREATE TABLE IF NOT EXISTS`, so it is safe to
     * call repeatedly (idempotent) and safe under the concurrent workers
     * that each ensure on their own first event. If the runner is not
     * registered (e.g. test environments) the hook is a silent no-op.
     *
     * @return callable(): void
     */
    private function makeSchemaEnsurer(ContainerInterface $container): callable
    {
        $logger = $this->logger;
        return static function () use ($container, $logger): void {
            if (!$container->has(LastfmMigrationRunner::class)) {
                $logger->debug('Last.fm migration runner not registered in container, skipping');
                return;
            }
            try {
                /** @var LastfmMigrationRunner $runner */
                $runner = $container->get(LastfmMigrationRunner::class);
                $runner->run();
                $logger->debug('Last.fm migrations completed (deferred, first-use)');
            } catch (\Throwable $e) {
                $logger->warning('Last.fm migration runner threw', ['error' => $e->getMessage()]);
            }
        };
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
        if ($repoRaw instanceof ItemRepository === false) {
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

    /** Cooperative-wait ceiling (seconds) — just above the 10s transfer timeout. */
    private const HTTP_MAX_WAIT_SECONDS = 12.0;

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
     * @return callable(string $url, string $body, array<string, string> $headers): array{status: int, body: string, headers: array<array-key, mixed>}
     */
    private function createAsyncHttpRunner(): callable
    {
        if (!class_exists(\Workerman\Http\Client::class)) {
            $this->logger->warning('Last.fm: workerman/http-client not available, using sync fallback');
            return \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi::defaultHttp();
        }

        // 3-second connect timeout, 10-second transfer timeout — keeps things snappy.
        // TLS verification is ON by default (do not disable peer/host verification).
        /** @var \Workerman\Http\Client $client */
        $client = new \Workerman\Http\Client([
            'timeout'         => 10,
            'connect_timeout' => 3,
        ]);

        // The cooperative rendezvous only parks safely inside a coroutine
        // (see SV-0.4 law on phlix-server src/Hub/HttpClient.php). Outside a
        // coroutine the blocking stream runner is the only honest choice —
        // the gate routes accordingly and warns once.
        return self::contextGatedRunner(
            self::buildCooperativeRunner($client),
            \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi::defaultHttp(),
            static fn (): bool => self::cooperativeContextAvailable(),
            $this->logger,
        );
    }

    /**
     * Whether a Swoole coroutine context is active for the CURRENT call.
     *
     * Resolved dynamically (no static Swoole references): this plugin must
     * load — and analyze — on deployments without the swoole extension.
     */
    public static function cooperativeContextAvailable(): bool
    {
        if (!class_exists('Swoole\\Coroutine')) {
            return false;
        }

        // Reflection form (not a literal static call): swoole is optional at
        // runtime and absent from static-analysis venues without its stubs.
        return (new \ReflectionMethod('Swoole\\Coroutine', 'inCoroutine'))->invoke(null) === true;
    }

    /**
     * Pick the async (in-coroutine) runner or the blocking stream fallback,
     * warning exactly once when dispatch ever lands outside a coroutine.
     *
     * Extracted as a public static seam so the gating and the one-shot
     * notice are unit-testable without any event loop at all.
     *
     * @param callable(string, string, array<string, string>): array{status: int, body: string, headers: array<array-key, mixed>} $async
     * @param callable(string, string, array<string, string>): array{status: int, body: string, headers: array<array-key, mixed>} $blocking
     * @param callable(): bool $contextProbe
     */
    public static function contextGatedRunner(
        callable $async,
        callable $blocking,
        callable $contextProbe,
        LoggerInterface $logger,
    ): callable {
        $notified = false;

        return static function (string $url, string $body, array $headers) use ($async, $blocking, $contextProbe, $logger, &$notified): array {
            if ($contextProbe()) {
                return $async($url, $body, $headers);
            }

            if (!$notified) {
                $notified = true;
                $logger->warning('Last.fm: async HTTP dispatched outside a coroutine context — using blocking stream fallback');
            }

            return $blocking($url, $body, $headers);
        };
    }

    /**
     * Wrap a {@see \Workerman\Http\Client} in the canonical Phlix
     * cooperative-wait runner (see phlix-server `CLAUDE.md` "Async
     * Patterns" and the SV-0.4 law on `src/Hub/HttpClient.php`).
     *
     * The request is fired with `success`/`error` callbacks (async, does
     * NOT block the event loop), then the caller parks on a one-slot
     * rendezvous — under Swoole hooks the pop yields to the scheduler so
     * other connections keep progressing while this scrobble's response
     * arrives. This replaces the previous 1 ms `usleep` polling wall: an
     * unhooked spin (or a Channel touch outside a coroutine) starves the
     * ENTIRE worker, so production dispatch reaches this runner only
     * through {@see self::contextGatedRunner()}.
     *
     * The rendezvous is injectable so the mechanics are unit-testable
     * without the swoole extension (absent from CI). The default factory
     * builds a `Swoole\Coroutine\Channel(1)` when a coroutine context is
     * live and, outside one, a non-spinning synchronous holder that fails
     * fast: an unsettled await never fabricates success — it yields the
     * same zero-status transport shape a wait timeout does.
     *
     * Extracted (and non-private) so it can be unit-tested against a
     * client double without an event loop or a live network.
     *
     * @param callable(float): array{0: callable(): void, 1: callable(): bool}|null $rendezvousFactory
     *
     * @return callable(string $url, string $body, array<string, string> $headers): array{status: int, body: string, headers: array<array-key, mixed>}
     */
    public static function buildCooperativeRunner(
        \Workerman\Http\Client $client,
        float $maxWaitSeconds = self::HTTP_MAX_WAIT_SECONDS,
        ?callable $rendezvousFactory = null,
    ): callable {
        $rendezvousFactory ??= static fn (float $timeout): array => self::defaultRendezvous($timeout);

        return static function (string $url, string $body, array $headers) use ($client, $maxWaitSeconds, $rendezvousFactory): array {
            /** @var array{status: int, body: string, headers: array<array-key, mixed>} $captured */
            $captured = ['status' => 0, 'body' => '', 'headers' => []];
            [$signal, $await] = $rendezvousFactory($maxWaitSeconds);

            // success and error must never both feed a capacity-1 rendezvous.
            $signaled = false;
            $notify = static function () use (&$signaled, $signal): void {
                if ($signaled) {
                    return;
                }
                $signaled = true;
                $signal();
            };

            try {
                $client->request($url, [
                    'method'  => 'POST',
                    'data'    => $body,
                    'headers' => $headers,
                    'success' => static function ($response) use (&$captured, $notify): void {
                        if ($response instanceof \Workerman\Http\Response) {
                            $captured['status']  = $response->getStatusCode();
                            $captured['body']    = (string) $response->getBody();
                            $captured['headers'] = $response->getHeaders();
                        }
                        $notify();
                    },
                    'error'   => static function () use ($notify): void {
                        // Transport/DNS error — leave status 0 so the caller
                        // treats it the same as a network-level failure.
                        $notify();
                    },
                ]);
            } catch (\Throwable) {
                // Synchronous throw (bad address, etc.) — zero-status failure.
                return ['status' => 0, 'body' => '', 'headers' => []];
            }

            if ($await() !== true) {
                // Waited out the ceiling with no response — indistinguishable
                // from a transport failure (C6 law: zero status = transient).
                return ['status' => 0, 'body' => '', 'headers' => []];
            }

            return $captured;
        };
    }

    /**
     * Default rendezvous pair: Channel(1) inside a live coroutine, a
     * fail-fast synchronous holder outside one. Swoole classes resolve
     * dynamically so this file analyzes without swoole stubs.
     *
     * @return array{0: callable(): void, 1: callable(): bool}
     */
    private static function defaultRendezvous(float $timeout): array
    {
        if (self::cooperativeContextAvailable()) {
            $channelClass = 'Swoole\\Coroutine\\Channel';
            $channel = new $channelClass(1);

            // Reflection closures (phpstan models getClosure() on a bound
            // instance as non-null): the Channel class is invisible to static
            // analysis here (swoole optional), and its methods are guaranteed
            // present whenever the coroutine context probe passed above.
            $pusher = (new \ReflectionMethod($channel, 'push'))->getClosure($channel);
            $popper = (new \ReflectionMethod($channel, 'pop'))->getClosure($channel);

            return [
                static function () use ($pusher): void {
                    $pusher(true);
                },
                static function () use ($popper, $timeout): bool {
                    return $popper($timeout) === true;
                },
            ];
        }

        $settled = false;

        return [
            static function () use (&$settled): void {
                $settled = true;
            },
            static function () use (&$settled): bool {
                return $settled;
            },
        ];
    }
}

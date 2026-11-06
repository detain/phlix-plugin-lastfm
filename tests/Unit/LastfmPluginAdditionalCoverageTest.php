<?php

/**
 * Unit::LastfmPluginAdditionalCoverageTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmOutcome;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmScrobbler;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Additional coverage tests for {@see LastfmPlugin} to cover uncovered branches.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmPluginAdditionalCoverageTest extends TestCase
{
    /**
     * Test that subscribedEvents closures invoke scrobbler methods correctly.
     * This tests the actual invocation path, not just that closures are returned.
     * Exercises the PlaybackStarted and PlaybackStopped closures
     * returned by LastfmPlugin::subscribedEvents().
     */
    public function testSubscribedEventsClosuresInvokeScrobblerUpdateNowPlaying(): void
    {
        /** @var LastfmApi&\PHPUnit\Framework\MockObject\MockObject $api */
        $api = $this->createMock(LastfmApi::class);
        /** @var LastfmSessionRepository&\PHPUnit\Framework\MockObject\MockObject $sessions */
        $sessions = $this->createMock(LastfmSessionRepository::class);
        $sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);

        // Track resolver returns valid track
        $resolveTrack = static fn (string $_id): array => [
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
            'duration_seconds' => 200,
        ];

        $scrobbler = new LastfmScrobbler($api, $sessions, $resolveTrack);

        // Expect updateNowPlaying to be called once
        $api->expects(self::once())->method('updateNowPlaying')
            ->with('SK', 'Test Song', 'Test Artist', 'Test Album')
            ->willReturn(LastfmOutcome::success());

        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);

        // Manually inject our test scrobbler via reflection
        $reflection = new \ReflectionClass($plugin);
        $scrobblerProp = $reflection->getProperty('scrobbler');
        $scrobblerProp->setAccessible(true);
        $scrobblerProp->setValue($plugin, $scrobbler);

        $events = $plugin->subscribedEvents();

        // Invoke the PlaybackStarted closure
        $playbackStartedClosure = $events[PlaybackStarted::class];
        $playbackStartedClosure(new PlaybackStarted('sess', 'u1', 'media-1', 'device', 0));
    }

    /**
     * Test that subscribedEvents closures invoke scrobbler scrobble method when rules are met.
     * Exercises the PlaybackStopped (scrobble) closure returned
     * by LastfmPlugin::subscribedEvents().
     */
    public function testSubscribedEventsClosuresInvokeScrobblerScrobble(): void
    {
        /** @var LastfmApi&\PHPUnit\Framework\MockObject\MockObject $api */
        $api = $this->createMock(LastfmApi::class);
        /** @var LastfmSessionRepository&\PHPUnit\Framework\MockObject\MockObject $sessions */
        $sessions = $this->createMock(LastfmSessionRepository::class);
        $sessions->method('findByUserId')->willReturn([
            'user_id' => 'u1', 'session_key' => 'SK', 'connected_at' => '2024-01-01 00:00:00',
        ]);

        // Track resolver returns valid track with 200 seconds duration
        $resolveTrack = static fn (string $_id): array => [
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
            'duration_seconds' => 200,
        ];

        $scrobbler = new LastfmScrobbler($api, $sessions, $resolveTrack);

        // Track is 200 seconds, user plays for 120 seconds (>50% and >30s), so scrobble SHOULD be called
        $api->expects(self::once())->method('scrobble')
            ->with(
                'SK',
                'Test Song',
                'Test Artist',
                'Test Album',
                self::anything() // timestamp
            )
            ->willReturn(LastfmOutcome::success());

        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'API_KEY',
            'shared_secret' => 'SECRET',
        ]);

        // Manually inject our test scrobbler via reflection
        $reflection = new \ReflectionClass($plugin);
        $scrobblerProp = $reflection->getProperty('scrobbler');
        $scrobblerProp->setAccessible(true);
        $scrobblerProp->setValue($plugin, $scrobbler);

        $events = $plugin->subscribedEvents();

        // Invoke the PlaybackStopped closure with 120 seconds played (120 seconds = 1_200_000_000 ticks)
        $playbackStoppedClosure = $events[PlaybackStopped::class];
        $playbackStoppedClosure(new PlaybackStopped('s', 'u1', 'm', 'd', 1_200_000_000, true));
    }
}

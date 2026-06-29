<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmOAuthStateStore;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Plugins\Scrobbler\Lastfm\Server\Http\Controllers\LastfmController;
use Phlix\Plugins\Scrobbler\Lastfm\Support\Session;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see LastfmController}.
 *
 * Tests the OAuth flow: auth() redirects to Last.fm, callback() validates
 * state, exchanges code for session, and persists via repository.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\Server\Http\Controllers\LastfmController
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmControllerTest extends TestCase
{
    /** @var LastfmOAuthStateStore&\PHPUnit\Framework\MockObject\MockObject */
    private LastfmOAuthStateStore $stateStore;

    /** @var LastfmApi&\PHPUnit\Framework\MockObject\MockObject */
    private LastfmApi $api;

    /** @var LastfmSessionRepository&\PHPUnit\Framework\MockObject\MockObject */
    private LastfmSessionRepository $sessions;

    /** @var Session */
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        Session::resetAll();

        $this->stateStore = $this->createMock(LastfmOAuthStateStore::class);
        $this->api = $this->createMock(LastfmApi::class);
        $this->sessions = $this->createMock(LastfmSessionRepository::class);
        $this->session = new Session('test');
    }

    protected function tearDown(): void
    {
        Session::resetAll();
        parent::tearDown();
    }

    /**
     * Build a controller with the given config values.
     *
     * @param array<string, mixed> $configValues
     */
    private function makeController(array $configValues = []): LastfmController
    {
        $config = new LastfmConfig(
            apiKey: is_string($configValues['api_key'] ?? null) ? $configValues['api_key'] : '',
            sharedSecret: is_string($configValues['shared_secret'] ?? null) ? $configValues['shared_secret'] : '',
            enabled: ($configValues['enabled'] ?? false) === true,
            callbackUrl: is_string($configValues['callback_url'] ?? null) ? $configValues['callback_url'] : '/settings/integrations/lastfm',
            username: is_string($configValues['username'] ?? null) ? $configValues['username'] : '',
        );

        return new LastfmController(
            $config,
            $this->stateStore,
            $this->api,
            $this->sessions,
            $this->session,
        );
    }

    public function testRoutesReturnsCorrectDefinitions(): void
    {
        $routes = LastfmController::routes();

        self::assertCount(2, $routes);
        self::assertSame('GET', $routes[0]['method']);
        self::assertSame('/auth/lastfm', $routes[0]['path']);
        self::assertSame([LastfmController::class, 'auth'], $routes[0]['handler']);
        self::assertSame('GET', $routes[1]['method']);
        self::assertSame('/auth/lastfm/callback', $routes[1]['path']);
        self::assertSame([LastfmController::class, 'callback'], $routes[1]['handler']);
    }

    public function testAuthRedirectsToLastFmWhenUserAuthenticated(): void
    {
        $controller = $this->makeController([
            'enabled' => true,
            'api_key' => 'MY_API_KEY',
            'shared_secret' => 'MY_SECRET',
            'callback_url' => 'http://localhost/auth/lastfm/callback',
        ]);

        $this->stateStore->expects(self::once())
            ->method('put')
            ->with(self::isType('string'), 'user-123');

        $response = $controller->auth(['user_id' => 'user-123']);

        self::assertSame(302, $response['status']);
        self::assertArrayHasKey('Location', $response['headers']);
        self::assertStringStartsWith('https://www.last.fm/api/auth/?', $response['headers']['Location']);
        self::assertStringContainsString('api_key=MY_API_KEY', $response['headers']['Location']);
    }

    public function testAuthRedirectsToErrorWhenNoUser(): void
    {
        $controller = $this->makeController(['enabled' => true]);

        $response = $controller->auth([]);

        self::assertSame(302, $response['status']);
        self::assertArrayHasKey('Location', $response['headers']);
        self::assertStringContainsString('error=unauthenticated', $response['headers']['Location']);
    }

    public function testAuthRedirectsToErrorWhenPluginNotConfigured(): void
    {
        $controller = $this->makeController(['enabled' => false]);

        $response = $controller->auth(['user_id' => 'user-123']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('error=not_configured', $response['headers']['Location']);
    }

    public function testCallbackRedirectsToErrorWhenCsrfFails(): void
    {
        $this->stateStore->method('consume')->with('bad-state')->willReturn(null);

        $controller = $this->makeController();
        $response = $controller->callback(['state' => 'bad-state', 'code' => 'auth-code']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('error=csrf_failed', $response['headers']['Location']);
    }

    public function testCallbackRedirectsToErrorWhenCodeMissing(): void
    {
        $this->stateStore->method('consume')->with('valid-state')->willReturn('user-123');

        $controller = $this->makeController();
        $response = $controller->callback(['state' => 'valid-state']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('error=denied', $response['headers']['Location']);
    }

    public function testCallbackRedirectsToErrorWhenGetSessionFails(): void
    {
        $this->stateStore->method('consume')->with('valid-state')->willReturn('user-123');
        $this->api->method('getSession')->with('auth-code')->willReturn(null);

        $controller = $this->makeController();
        $response = $controller->callback(['state' => 'valid-state', 'code' => 'auth-code']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('error=token_exchange_failed', $response['headers']['Location']);
    }

    public function testCallbackSavesSessionAndRedirectsToSuccess(): void
    {
        $this->stateStore->method('consume')->with('valid-state')->willReturn('user-123');
        $this->api->method('getSession')->with('auth-code')->willReturn([
            'session_key' => 'sk-abc123',
            'username'    => 'rj',
        ]);

        $this->sessions->expects(self::once())
            ->method('save')
            ->with('user-123', 'sk-abc123', 'rj');

        $controller = $this->makeController([
            'callback_url' => '/settings/integrations/lastfm',
        ]);
        $response = $controller->callback(['state' => 'valid-state', 'code' => 'auth-code']);

        self::assertSame(302, $response['status']);
        self::assertArrayHasKey('Location', $response['headers']);
        self::assertStringContainsString('connected=1', $response['headers']['Location']);
    }
}

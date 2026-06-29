<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm\Server\Http\Controllers;

use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmOAuthStateStore;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Plugins\Scrobbler\Lastfm\Support\Session;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP controller for the Last.fm OAuth connect flow.
 *
 * Exposes two routes:
 *  - `GET /auth/lastfm`           — redirect to Last.fm authorization.
 *  - `GET /auth/lastfm/callback`  — exchange code for session key and persist.
 *
 * ## Route registration
 *
 * The host registers these routes by calling {@see self::routes()} and
 * mounting each returned route via its own router.  The controller
 * instance should be resolved from the PSR-11 container so that all
 * constructor dependencies are satisfied.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm\Server\Http\Controllers
 * @since 0.15.0
 */
final class LastfmController
{
    private LoggerInterface $logger;

    /**
     * @param LastfmConfig        $config   Last.fm plugin config.
     * @param LastfmOAuthStateStore $stateStore CSRF state store.
     * @param LastfmApi           $api      Last.fm API client.
     * @param LastfmSessionRepository $sessions  Session repository.
     * @param Session             $session  Request-scoped session.
     * @param LoggerInterface|null $logger  Optional PSR-3 logger.
     */
    public function __construct(
        private readonly LastfmConfig $config,
        private readonly LastfmOAuthStateStore $stateStore,
        private readonly LastfmApi $api,
        private readonly LastfmSessionRepository $sessions,
        private readonly Session $session,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Route definitions for the Last.fm OAuth connect flow.
     *
     * Returns a list of route descriptors. Each descriptor is an array with:
     *  - method:  string (GET)
     *  - path:    string (e.g. /auth/lastfm)
     *  - handler: array{LastfmController::class, string} [self, methodName]
     *
     * The host should mount these on its router during plugin enable.
     *
     * @return array<array{method: string, path: string, handler: array{class-string, string}}>
     */
    public static function routes(): array
    {
        return [
            [
                'method'  => 'GET',
                'path'    => '/auth/lastfm',
                'handler' => [self::class, 'auth'],
            ],
            [
                'method'  => 'GET',
                'path'    => '/auth/lastfm/callback',
                'handler' => [self::class, 'callback'],
            ],
        ];
    }

    /**
     * Initiate the Last.fm OAuth flow.
     *
     * Generates a cryptographically random `state` CSRF token, stores it
     * in the state store (bound to the current user), and redirects the
     * browser to Last.fm's authorization URL.
     *
     * Query params expected:
     *  - user_id: string — the Phlix user UUID initiating the connect.
     *
     * Redirects to Last.fm authorize URL. On error, redirects to the
     * frontend error page with `?error=...`.
     *
     * @param array<string, mixed> $request  Request parameters (query + path vars).
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     *         HTTP redirect response (status 302).
     */
    public function auth(array $request = []): array
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->redirectError('unauthenticated', 'No user session');
        }

        if (!$this->config->isUsable()) {
            $this->logger->warning('Last.fm auth attempted but plugin not configured');
            return $this->redirectError('not_configured', 'Last.fm plugin is not configured');
        }

        // Generate a cryptographically random state token for CSRF protection.
        $state = bin2hex(random_bytes(16));

        // Store the state bound to this user — consume() will return userId on match.
        $this->stateStore->put($state, $userId);

        $params = [
            'api_key' => $this->config->apiKey,
            'cb'      => $this->buildCallbackUrl(),
            'token'   => $state,
        ];

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $authorizeUrl = 'https://www.last.fm/api/auth/?' . $query;

        $this->logger->info('Last.fm OAuth: redirecting to authorize', [
            'user_id' => $userId,
        ]);

        return [
            'status'  => 302,
            'headers' => ['Location' => $authorizeUrl],
            'body'    => '',
        ];
    }

    /**
     * Handle the OAuth callback from Last.fm.
     *
     * Last.fm redirects here after the user approves (or denies) access.
     * The `state` parameter is validated against our stored state (CSRF
     * check).  On success the `code` is exchanged for a session key via
     * `auth.getSession`, the session is persisted, and the user is
     * redirected to the frontend.
     *
     * Query params expected:
     *  - state:   string — the CSRF state token from the auth step.
     *  - code:    string|null — authorization code from Last.fm (absent on denial).
     *
     * Redirects:
     *  - Success: `/settings/integrations/lastfm?connected=1`
     *  - Error:   `/settings/integrations/lastfm?error={reason}`
     *
     * @param array<string, mixed> $request Request parameters (query + path vars).
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function callback(array $request = []): array
    {
        // Step 1: CSRF validation — recover userId from the stored state.
        $state = is_string($request['state'] ?? null) ? $request['state'] : '';
        $userId = $this->stateStore->consume($state);
        if ($userId === null) {
            $this->logger->warning('Last.fm callback: CSRF mismatch or expired state');
            return $this->redirectError('csrf_failed', 'Invalid or expired state');
        }

        // Step 2: Exchange the code for a session key.
        $code = is_string($request['code'] ?? null) ? $request['code'] : '';
        if ($code === '') {
            // User denied access or code missing — treat as user-initiated cancel.
            $this->logger->info('Last.fm callback: user denied or code missing', ['user_id' => $userId]);
            return $this->redirectError('denied', 'Authorization was denied');
        }

        $sessionData = $this->api->getSession($code);
        if ($sessionData === null) {
            $this->logger->warning('Last.fm callback: getSession returned null', [
                'user_id' => $userId,
            ]);
            return $this->redirectError('token_exchange_failed', 'Failed to obtain session from Last.fm');
        }

        // Step 3: Persist the session in the database.
        $this->sessions->save(
            $userId,
            $sessionData['session_key'],
            $sessionData['username'],
        );

        $this->logger->info('Last.fm OAuth complete: session saved', [
            'user_id'  => $userId,
            'username' => $sessionData['username'],
        ]);

        return [
            'status'  => 302,
            'headers' => ['Location' => $this->config->callbackUrl . '?connected=1'],
            'body'    => '',
        ];
    }

    /**
     * Extract the user ID from the current request/session context.
     *
     * In the Phlix host, the authenticated user ID is typically stored in
     * the request-scoped session under `auth.user_id`.  This method
     * abstracts that lookup so the controller remains framework-agnostic.
     *
     * @param array<string, mixed> $request Current request parameters.
     *
     * @return string|null User ID or null if unauthenticated.
     */
    private function resolveUserId(array $request): ?string
    {
        // First check if user_id was explicitly passed (e.g. via query param
        // in integration tests or direct API calls).
        if (is_string($request['user_id'] ?? null) && $request['user_id'] !== '') {
            return $request['user_id'];
        }
        // Otherwise fall back to the request-scoped session store.
        $userId = $this->session->get('auth.user_id');
        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    /**
     * Build the full callback URL for the Last.fm authorization redirect.
     */
    private function buildCallbackUrl(): string
    {
        $cb = $this->config->callbackUrl;
        if ($cb === '') {
            $cb = '/auth/lastfm/callback';
        }
        // If the callback URL is relative, prepend the scheme+host.
        if (!str_starts_with($cb, 'http://') && !str_starts_with($cb, 'https://')) {
            $scheme = is_string($_SERVER['REQUEST_SCHEME'] ?? null) && $_SERVER['REQUEST_SCHEME'] !== ''
                ? $_SERVER['REQUEST_SCHEME']
                : 'https';
            $host = is_string($_SERVER['HTTP_HOST'] ?? null) && $_SERVER['HTTP_HOST'] !== ''
                ? $_SERVER['HTTP_HOST']
                : 'localhost';
            $cb = $scheme . '://' . $host . $cb;
        }
        return $cb;
    }

    /**
     * Build a redirect response to the frontend error page.
     *
     * @param string $errorCode  Machine-readable error code.
     * @param string $errorHuman Human-readable message (not exposed to client).
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function redirectError(string $errorCode, string $errorHuman): array
    {
        $this->logger->debug('Last.fm OAuth error redirect', [
            'error_code' => $errorCode,
            'reason'     => $errorHuman,
        ]);
        $base = $this->config->callbackUrl !== ''
            ? dirname($this->config->callbackUrl)
            : '/settings/integrations/lastfm';
        $location = $base . '?error=' . urlencode($errorCode);
        return [
            'status'  => 302,
            'headers' => ['Location' => $location],
            'body'    => '',
        ];
    }
}

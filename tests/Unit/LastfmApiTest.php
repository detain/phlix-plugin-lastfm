<?php

/**
 * Unit::LastfmApiTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LogSafety;

/**
 * Unit tests for {@see LastfmApi}.
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
#[CoversClass(\Phlix\Plugins\Scrobbler\Lastfm\LastfmApi::class)]
final class LastfmApiTest extends TestCase
{
    /**
     * Worked example from the Last.fm Web Service authentication docs:
     * the api_sig is the MD5 of the alphabetically-sorted concatenation
     * of (key . value) for every parameter except `format`/`callback`,
     * followed by the shared secret.
     */
    public function testBuildApiSigMatchesLastfmWorkedExample(): void
    {
        $api = new LastfmApi('xxxxxxxxxx', 'mysecret');

        // Parameters from the Last.fm docs sample (sorted: api_key, method, token).
        $params = [
            'method'  => 'auth.getSession',
            'api_key' => 'xxxxxxxxxx',
            'token'   => 'xxxxxxxxxxxxxxx',
        ];

        $expected = md5('api_keyxxxxxxxxxxmethodauth.getSessiontokenxxxxxxxxxxxxxxxmysecret');
        $this->assertSame($expected, $api->buildApiSig($params));
    }

    public function testBuildApiSigSkipsFormatAndCallback(): void
    {
        $api = new LastfmApi('k', 's');
        $params = ['method' => 'm', 'api_key' => 'k', 'format' => 'json', 'callback' => 'cb', 'extra' => 'e'];
        $expected = md5('api_keykextraemethodms');
        $this->assertSame($expected, $api->buildApiSig($params));
    }

    public function testGetSessionReturnsSessionKeyAndUsernameOnSuccess(): void
    {
        $api = $this->apiWith(200, json_encode([
            'session' => ['key' => 'SESSION_KEY', 'name' => 'rj'],
        ]));
        $session = $api->getSession('TOKEN');
        $this->assertNotNull($session);
        $this->assertSame('SESSION_KEY', $session['session_key']);
        $this->assertSame('rj', $session['username']);
    }

    public function testGetSessionReturnsNullOnHttpError(): void
    {
        $api = $this->apiWith(403, json_encode(['error' => 14, 'message' => 'Unauthorized Token']));
        $this->assertNull($api->getSession('BAD_TOKEN'));
    }

    public function testGetSessionReturnsNullOnUnparseableBody(): void
    {
        $api = $this->apiWith(200, 'not-json');
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testGetSessionReturnsNullWhenSessionNotArray(): void
    {
        // session is present but not an array (e.g., a string)
        $api = $this->apiWith(200, json_encode(['session' => 'not-an-array']));
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testGetSessionReturnsNullWhenKeyIsEmpty(): void
    {
        // session key is an empty string
        $api = $this->apiWith(200, json_encode(['session' => ['key' => '', 'name' => 'username']]));
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testGetSessionReturnsNullWhenNameIsEmpty(): void
    {
        // session name is an empty string
        $api = $this->apiWith(200, json_encode(['session' => ['key' => 'sessionkey', 'name' => '']]));
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testGetSessionReturnsNullWhenKeyIsNotString(): void
    {
        // session key is a number instead of string
        $api = $this->apiWith(200, json_encode(['session' => ['key' => 12345, 'name' => 'username']]));
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testGetSessionReturnsNullWhenNameIsNotString(): void
    {
        // session name is a number instead of string
        $api = $this->apiWith(200, json_encode(['session' => ['key' => 'sessionkey', 'name' => 12345]]));
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testGetSessionRejectsOversizedSessionKey(): void
    {
        // Columns are VARCHAR(64) (CreateLastfmSessionsTable): a hostile or
        // compromised response carrying a 65-byte key must be rejected at the
        // API boundary, not left for sql_mode to truncate. The cap is
        // byte-based, deliberately stricter than the column's char count.
        $api = $this->apiWith(200, json_encode([
            'session' => ['key' => str_repeat('K', 65), 'name' => 'rj'],
        ]));
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testGetSessionAcceptsExactlySizedSixtyFourByteKey(): void
    {
        $api = $this->apiWith(200, json_encode([
            'session' => ['key' => str_repeat('K', 64), 'name' => 'rj'],
        ]));
        $session = $api->getSession('TOKEN');
        $this->assertNotNull($session);
        $this->assertSame(64, strlen($session['session_key']));
    }

    public function testGetSessionRejectsControlCharactersInUsername(): void
    {
        // Raw control chars in `username` must not persist (secaudit m-4).
        $api = $this->apiWith(200, json_encode([
            'session' => ['key' => 'SESSION_KEY', 'name' => "rj\ra"],
        ]));
        $this->assertNull($api->getSession('TOKEN'));

        $api = $this->apiWith(200, json_encode([
            'session' => ['key' => 'SESSION' . "\x00" . '_KEY', 'name' => 'rj'],
        ]));
        $this->assertNull($api->getSession('TOKEN'));
    }

    public function testGetSessionTrimsSurroundingWhitespace(): void
    {
        $api = $this->apiWith(200, json_encode([
            'session' => ['key' => "  SESSION_KEY\t", 'name' => " rj\n"],
        ]));
        $session = $api->getSession('TOKEN');
        $this->assertNotNull($session);
        $this->assertSame('SESSION_KEY', $session['session_key']);
        $this->assertSame('rj', $session['username']);
    }

    public function testUpdateNowPlayingReturnsTrueOn200(): void
    {
        $api = $this->apiWith(200, json_encode([
            'nowplaying' => ['track' => ['#text' => 'Song'], 'artist' => ['#text' => 'Artist']],
        ]));
        $this->assertTrue($api->updateNowPlaying('SK', 'Song', 'Artist')->success);
    }

    public function testUpdateNowPlayingReturnsFalseOnApiError(): void
    {
        $api = $this->apiWith(200, json_encode(['error' => 9, 'message' => 'Invalid session key']));
        $this->assertFalse($api->updateNowPlaying('SK', 'Song', 'Artist')->success);
    }

    public function testScrobbleReturnsTrueOn200(): void
    {
        $api = $this->apiWith(200, json_encode([
            'scrobbles' => ['scrobble' => ['track' => ['#text' => 'Song']]],
        ]));
        $this->assertTrue($api->scrobble('SK', 'Song', 'Artist', 'Album', 1700000000)->success);
    }

    public function testScrobbleReturnsFalseOn500(): void
    {
        $api = $this->apiWith(500, '');
        $this->assertFalse($api->scrobble('SK', 'Song', 'Artist')->success);
    }

    /**
     * M-03 [review lastfm__srphp M03] — recorded-shape refusal: Last.fm
     * answers a refused submission with HTTP 200, no top-level `error`, and
     * `scrobbles.@attr` carrying accepted=0/ignored=1 plus the per-scrobble
     * ignore reason. That is a rejection, not a success.
     */
    public function testScrobble200WithIgnoredAttrIsRejectedAndCarriesReason(): void
    {
        $recorded = json_encode([
            'scrobbles' => [
                '@attr' => ['accepted' => '0', 'ignored' => '1', 'total' => '1'],
                'scrobble' => [
                    'artist' => ['#text' => 'Artist'],
                    'track'  => ['#text' => 'Song'],
                    'ignore' => ['#text' => 'true', '@attr' => ['reason' => 'banned']],
                ],
            ],
        ]);
        $api = $this->apiWith(200, $recorded);

        $outcome = $api->scrobble('SK', 'Song', 'Artist', null, 1700000000);

        $this->assertFalse($outcome->success, '200-but-ignored must not be reported as success');
        $this->assertSame(0, $outcome->accepted);
        $this->assertSame(1, $outcome->ignored);
        $this->assertSame('banned', $outcome->ignoreReason);
        // The reason must reach the log line the scrobbler emits (M-04 wiring):
        $this->assertArrayHasKey('ignore_reason', $outcome->logContext());
        $this->assertSame('banned', $outcome->logContext()['ignore_reason']);
    }

    /**
     * LFM-S4: an oversize, control-character-laden envelope message and
     * ignore reason arriving through the API seam reach the outcome and its
     * log context scrubbed and bounded.
     */
    public function testOversizeControlCharEnvelopeMessageIsScrubbedAtTheApiSeam(): void
    {
        $hostile = "bad\r\nINJECTED line\x00\x07" . str_repeat('x', 1000) . 'TAIL-MUST-NOT-SURVIVE';
        $api = $this->apiWith(200, (string) json_encode(['error' => 6, 'message' => $hostile]));

        $outcome = $api->scrobble('SK', 'Song', 'Artist', null, 1700000000);

        $this->assertFalse($outcome->success);
        $message = (string) $outcome->errorMessage;
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $message);
        $this->assertStringStartsWith('bad  INJECTED line', $message);
        $this->assertStringNotContainsString('TAIL-MUST-NOT-SURVIVE', $message);
        $this->assertStringContainsString('truncated', $message);
        $this->assertLessThanOrEqual(
            LogSafety::MAX_RESPONSE_STRING_BYTES + LogSafety::MAX_MARKER_BYTES,
            strlen($message),
        );
        $this->assertSame($message, $outcome->logContext()['error_message']);
    }

    public function testOversizeControlCharIgnoreReasonIsScrubbedAtTheApiSeam(): void
    {
        $hostile = "dup\nlicate\x1B[31m" . str_repeat('y', 1000) . 'TAIL-MUST-NOT-SURVIVE';
        $api = $this->apiWith(200, (string) json_encode([
            'scrobbles' => [
                '@attr' => ['accepted' => '0', 'ignored' => '1'],
                'scrobble' => ['ignore' => ['@attr' => ['reason' => $hostile]]],
            ],
        ]));

        $outcome = $api->scrobble('SK', 'Song', 'Artist', null, 1700000000);

        $reason = (string) $outcome->ignoreReason;
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $reason);
        $this->assertStringStartsWith('dup licate', $reason);
        $this->assertStringNotContainsString('TAIL-MUST-NOT-SURVIVE', $reason);
        $this->assertStringContainsString('truncated', $reason);
        $this->assertSame($reason, $outcome->logContext()['ignore_reason']);
    }

    /** The @attr-accepted twin: accepted=1/ignored=0 keeps the success verdict. */
    public function testScrobble200WithAcceptedAttrSucceeds(): void
    {
        $recorded = json_encode([
            'scrobbles' => [
                '@attr' => ['accepted' => '1', 'ignored' => '0', 'total' => '1'],
                'scrobble' => ['artist' => ['#text' => 'Artist'], 'track' => ['#text' => 'Song']],
            ],
        ]);
        $api = $this->apiWith(200, $recorded);

        $outcome = $api->scrobble('SK', 'Song', 'Artist', null, 1700000000);

        $this->assertTrue($outcome->success);
        $this->assertSame(1, $outcome->accepted);
        $this->assertSame(0, $outcome->ignored);
        $this->assertNull($outcome->ignoreReason);
    }

    /**
     * Shape-variance law (per the review's fix-essence): an absent or
     * non-numeric `@attr` keeps the permissive verdict — the parser must
     * never turn a Last.fm format change into crashed scrobbling. The
     * fixture in testScrobbleReturnsTrueOn200 pins the absent half.
     */
    public function testScrobbleGarbageAttrKeepsPermissiveSuccess(): void
    {
        $recorded = json_encode([
            'scrobbles' => ['@attr' => ['accepted' => 'yes', 'ignored' => ['weird']], 'scrobble' => []],
        ]);
        $api = $this->apiWith(200, $recorded);

        $outcome = $api->scrobble('SK', 'Song', 'Artist', null, 1700000000);

        $this->assertTrue($outcome->success);
        $this->assertNull($outcome->accepted);
        $this->assertNull($outcome->ignored);
    }

    /** Some recorded deliveries carry the reason as a direct `reason` key. */
    public function testScrobbleIgnoreReasonFromDirectKeyShape(): void
    {
        $recorded = json_encode([
            'scrobbles' => [
                '@attr' => ['accepted' => '0', 'ignored' => '1'],
                'scrobble' => ['ignore' => ['#text' => 'true', 'reason' => 'duplicate']],
            ],
        ]);
        $api = $this->apiWith(200, $recorded);

        $outcome = $api->scrobble('SK', 'Song', 'Artist', null, 1700000000);

        $this->assertFalse($outcome->success);
        $this->assertSame('duplicate', $outcome->ignoreReason);
    }

    /**
     * W-min52 M-20 [review lastfm minors W-min52] — a RECORDED payload,
     * held verbatim as wire text (the Last.fm API documentation's sample
     * JSON response for track.scrobble), not a hand-crafted json_encode
     * array. Real deliveries carry the full entry shape — album/
     * albumArtist blocks, an empty ignoredMessage with a `_type` marker,
     * a url-bearing track element keyed `name` instead of `#text` — and
     * the @attr verdict parser must ride that shape unchanged.
     */
    public function testRecordedDocsPayloadWithFullScrobbleEntryParsesAttrVerdict(): void
    {
        $recorded = <<<'JSON'
{
   "scrobbles": {
      "scrobble": [
         {
            "artist": {
               "mbid": "",
               "#text": "Pigbag"
            },
            "track": {
               "url": "https://www.last.fm/music/Pigbag/_/Papa+Was+a+Rollin+Stone",
               "name": "Papa Was a Rollin Stone"
            },
            "album": {
               "mbid": "",
               "#text": "Papa Was a Rollin'"
            },
            "albumArtist": {
               "mbid": "",
               "#text": "Pigbag"
            },
            "ignoredMessage": {
               "code": "0",
               "#text": "",
               "_type": "int"
            }
         }
      ],
      "@attr": {
         "accepted": "1",
         "ignored": "0"
      }
   }
}
JSON;
        $api = $this->apiWith(200, $recorded);

        $outcome = $api->scrobble('SK', 'Papa Was a Rollin Stone', 'Pigbag', null, 1700000000);

        $this->assertTrue($outcome->success, 'the documented accepted verdict must survive the recorded shape');
        $this->assertSame(1, $outcome->accepted);
        $this->assertSame(0, $outcome->ignored);
        $this->assertNull($outcome->ignoreReason);
    }

    public function testScrobbleIncludesSortedSignatureAndTimestamp(): void
    {
        $captured = ['url' => '', 'body' => ''];
        $http = function (string $url, string $body) use (&$captured) {
            $captured['url'] = $url;
            $captured['body'] = $body;
            return ['status' => 200, 'body' => '{"scrobbles":{}}'];
        };
        $api = new LastfmApi('K', 'S', null, $http);
        $api->scrobble('SK', 'Song', 'Artist', 'Album', 1700000000);

        parse_str($captured['body'], $parsed);
        $this->assertSame('track.scrobble', $parsed['method'] ?? null);
        $this->assertSame('Song', $parsed['track'] ?? null);
        $this->assertSame('Artist', $parsed['artist'] ?? null);
        $this->assertSame('Album', $parsed['album'] ?? null);
        $this->assertSame('1700000000', $parsed['timestamp'] ?? null);
        $this->assertSame('json', $parsed['format'] ?? null);
        $expectedSig = md5(
            'albumAlbumapi_keyKartistArtistmethodtrack.scrobbleskSKtimestamp1700000000trackSongS'
        );
        $this->assertSame($expectedSig, $parsed['api_sig'] ?? null);
    }

    /**
     * Build a LastfmApi backed by a stubbed HTTP runner.
     */
    private function apiWith(int $status, string|false $body): LastfmApi
    {
        $http = static function () use ($status, $body): array {
            return ['status' => $status, 'body' => is_string($body) ? $body : ''];
        };
        return new LastfmApi('API_KEY', 'SHARED_SECRET', null, $http);
    }
}

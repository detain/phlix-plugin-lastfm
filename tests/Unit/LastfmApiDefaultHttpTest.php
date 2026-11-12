<?php

/**
 * Unit::LastfmApiDefaultHttpTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin;

/**
 * Tests for the default HTTP runner in {@see LastfmApi}.
 *
 * The runner is EXECUTED here against a scripted loopback socket server —
 * this callable is the production transport whenever workerman/http-client
 * is absent, and reflection-only coverage of a blocking stream client proves
 * nothing about its wire behavior. The fixture never leaves 127.0.0.1: no
 * test in this suite reaches Last.fm (or the internet at large).
 *
 * Because the runner blocks its caller by contract, the dialing side runs in
 * a one-shot child process while the test process serves the socket: the two
 * halves of a synchronous HTTP exchange cannot share one thread.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmApiDefaultHttpTest extends TestCase
{
    /**
     * Test that defaultHttp() returns a callable with the correct signature.
     * We verify this via reflection without making actual network calls.
     */
    public function testDefaultHttpReturnsCallableWithCorrectSignature(): void
    {
        $callable = LastfmApi::defaultHttp();

        $this->assertIsCallable($callable);

        $reflection = new \ReflectionFunction($callable);
        $params = $reflection->getParameters();

        $this->assertSame('url', $params[0]->getName());
        $this->assertSame('body', $params[1]->getName());
        $this->assertSame('headers', $params[2]->getName());

        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    /**
     * Test that the callable returned by defaultHttp() handles headers correctly.
     * We verify the internal structure by checking that the closure has the
     * expected use variables and behavior patterns.
     */
    public function testDefaultHttpReturnedCallableIsStaticClosure(): void
    {
        $callable = LastfmApi::defaultHttp();

        $reflection = new \ReflectionFunction($callable);

        // The closure should be static (doesn't capture $this)
        $this->assertTrue($reflection->isStatic());
    }

    /**
     * Context-shape pin: the blocking fallback states its TLS policy
     * explicitly — verify_peer/verify_peer_name on, self-signed refused —
     * rather than inheriting whatever the stream defaults happen to be, and
     * the HTTP leg keeps the transport settings the runner depends on.
     */
    public function testDefaultHttpContextStatesStrictTlsFlags(): void
    {
        $options = LastfmApi::defaultHttpContextOptions("X-Test: 1\r\n", 'body=x');

        $this->assertSame(
            ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
            $options['ssl'],
        );
        $this->assertSame('POST', $options['http']['method']);
        $this->assertSame("X-Test: 1\r\n", $options['http']['header']);
        $this->assertSame('body=x', $options['http']['content']);
        $this->assertSame(10, $options['http']['timeout']);
        $this->assertTrue($options['http']['ignore_errors']);
    }

    /**
     * Parity pin: the blocking fallback and the async client carry the same
     * three TLS verification flags, so neither transport can drift weaker
     * than the other. (`peer_name` is async-only: the vendor pool loses its
     * auto-extracted host, while the stream wrapper derives it from the URL.)
     */
    public function testDefaultHttpTlsFlagsMatchAsyncStrictSubtree(): void
    {
        $asyncSsl = LastfmPlugin::asyncClientOptions()['context']['ssl'];
        unset($asyncSsl['peer_name']);

        $this->assertSame($asyncSsl, LastfmApi::defaultHttpContextOptions('', '')['ssl']);
    }

    /**
     * Executed happy path: a scripted 200 is returned with its status parsed
     * from the status line, its body intact, and its headers folded into the
     * associative shape the retry classifier reads.
     */
    public function testRunnerExecutesAgainstLocalhostAndReturnsScripted200(): void
    {
        $body = '{"status":"ok"}';
        $result = $this->exchangeWithScriptedResponse(
            "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame($body, $result['body']);
        $this->assertSame('application/json', $result['headers']['Content-Type']);
    }

    /**
     * The ignore_errors cure: Last.fm serves its JSON error envelope on 4xx
     * (a 403 carrying code 29 is the rate-limit/disabled anchor). Without
     * `ignore_errors` in the stream context, file_get_contents() reports the
     * >= 400 status as a failed read and returns false — the envelope body
     * collapses to the empty string and the classifier loses its verdict.
     * Pinned against a scripted 403-with-body: the status, the envelope, and
     * the Retry-After header all have to survive to the caller.
     */
    public function testRunnerReturnsTheErrorEnvelopeOn4xx(): void
    {
        $envelope = '{"error":29,"message":"Application request limit reached"}';
        $result = $this->exchangeWithScriptedResponse(
            "HTTP/1.1 403 Forbidden\r\n"
            . "Content-Type: application/json\r\n"
            . 'Retry-After: 7' . "\r\n"
            . 'Content-Length: ' . strlen($envelope) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $envelope
        );

        $this->assertSame(403, $result['status']);
        $this->assertSame($envelope, $result['body'], 'the 4xx envelope must reach the decoder (ignore_errors)');

        $decoded = json_decode($result['body'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(29, $decoded['error']);
        $this->assertSame('7', $result['headers']['Retry-After']);
    }

    /**
     * Transport failure shape: nothing listens on the dialed port, so the
     * runner reports the zero status the outcome classifier reads as a
     * transient transport drop (never a fabricated HTTP code, never a throw).
     */
    public function testRunnerReportsZeroStatusWhenConnectionIsRefused(): void
    {
        $url = $this->closedLoopbackUrl();
        $child = $this->startRunnerChild($url);
        $result = $this->collectRunnerChild($child);

        $this->assertSame(0, $result['status']);
        $this->assertSame('', $result['body']);
        $this->assertSame([], $result['headers']);
    }

    /**
     * Drive the production runner from a child process against a scripted
     * one-shot loopback server and return its normalized result shape.
     *
     * @param string $scriptedResponse Full raw HTTP response bytes to write.
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function exchangeWithScriptedResponse(string $scriptedResponse): array
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "loopback fixture could not bind: {$errstr}");

        $address = (string) stream_socket_get_name($server, false);
        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address);

        $child = $this->startRunnerChild('http://' . $address . '/');
        try {
            $client = @stream_socket_accept($server, 10.0, $peer);
            $this->assertIsResource(
                $client,
                'the runner never reached the loopback fixture (fixture bug, not a cure failure)',
            );

            // Drain the request head so the child is parked awaiting a response.
            stream_set_timeout($client, 5);
            while (false !== ($line = fgets($client)) && $line !== "\r\n") {
                // Headers are irrelevant to the assertion; consume until blank.
            }

            fwrite($client, $scriptedResponse);
            fclose($client);
        } finally {
            fclose($server);
        }

        return $this->collectRunnerChild($child);
    }

    /**
     * A loopback URL whose port is guaranteed unlistened (bound, then
     * released) — the refused-connection fixture.
     */
    private function closedLoopbackUrl(): string
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "loopback fixture could not bind: {$errstr}");
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return 'http://' . $address . '/';
    }

    /**
     * Launch the child process that executes the real runner. It starts
     * dialing immediately; the caller serves the listening socket.
     *
     * @return resource The stdout pipe of the child.
     */
    private function startRunnerChild(string $url)
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $script = 'require ' . $autoload . ';'
            . ' $result = (Phlix\Plugins\Scrobbler\Lastfm\LastfmApi::defaultHttp())(' . var_export($url, true) . ', "body=x", ['
            . "'Content-Type' => 'application/x-www-form-urlencoded']);"
            . ' echo json_encode($result);';

        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>/dev/null';
        $child = popen($command, 'r');
        $this->assertIsResource($child, 'the runner child process could not start');

        return $child;
    }

    /**
     * @param resource $child
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function collectRunnerChild($child): array
    {
        $stdout = '';
        stream_set_timeout($child, 15);
        while (!feof($child)) {
            $chunk = fread($child, 8192);
            if ($chunk === false) {
                break;
            }
            $stdout .= $chunk;
        }
        pclose($child);

        $this->assertNotSame('', $stdout, 'the runner child produced no result');
        /** @var array{status: int, body: string, headers: array<string, string>} $decoded */
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, 'the runner child result was not JSON: ' . $stdout);

        return $decoded;
    }
}

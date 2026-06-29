<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\Support\Session;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Session}.
 *
 * Verifies that the in-memory request-scoped store correctly isolates
 * data per namespace and that reset/flush work as expected.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\Support\Session
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Session::resetAll();
    }

    protected function tearDown(): void
    {
        Session::resetAll();
        parent::tearDown();
    }

    public function testPutAndGetReturnsStoredValue(): void
    {
        $session = new Session('test');
        $session->put('foo', 'bar');

        self::assertSame('bar', $session->get('foo'));
    }

    public function testGetReturnsDefaultWhenKeyAbsent(): void
    {
        $session = new Session('test');

        self::assertNull($session->get('absent'));
        self::assertSame('default', $session->get('absent', 'default'));
    }

    public function testForgetRemovesKey(): void
    {
        $session = new Session('test');
        $session->put('foo', 'bar');
        $session->forget('foo');

        self::assertNull($session->get('foo'));
    }

    public function testFlushClearsOnlyThisNamespace(): void
    {
        $sessionA = new Session('ns-a');
        $sessionB = new Session('ns-b');

        $sessionA->put('key', 'val-a');
        $sessionB->put('key', 'val-b');

        $sessionA->flush();

        self::assertNull($sessionA->get('key'));
        self::assertSame('val-b', $sessionB->get('key'));
    }

    public function testNamespacedKeysDoNotCollide(): void
    {
        $session1 = new Session('user-1');
        $session2 = new Session('user-2');

        $session1->put('oauth_state', 'state-for-user-1');
        $session2->put('oauth_state', 'state-for-user-2');

        self::assertSame('state-for-user-1', $session1->get('oauth_state'));
        self::assertSame('state-for-user-2', $session2->get('oauth_state'));
    }

    public function testResetAllClearsAllNamespaces(): void
    {
        $session1 = new Session('ns-1');
        $session2 = new Session('ns-2');

        $session1->put('key', 'val1');
        $session2->put('key', 'val2');

        Session::resetAll();

        self::assertNull($session1->get('key'));
        self::assertNull($session2->get('key'));
    }

    public function testOverwritingKeyReplacesValue(): void
    {
        $session = new Session('test');
        $session->put('key', 'original');
        $session->put('key', 'replacement');

        self::assertSame('replacement', $session->get('key'));
    }
}

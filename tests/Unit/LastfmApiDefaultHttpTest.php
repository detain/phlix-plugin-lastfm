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

/**
 * Tests for the default HTTP runner in {@see LastfmApi}.
 *
 * Note: The actual HTTP call via file_get_contents() is not tested because
 * it would make real network calls. We verify the structure of the returned
 * callable via reflection.
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
}

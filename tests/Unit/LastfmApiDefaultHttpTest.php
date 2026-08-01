<?php

/**
 * NOTE: This file was intentionally left empty.
 *
 * The tests in this file made real network calls which violates
 * the testing rule: "Deterministic tests only. No real network calls."
 *
 * The defaultHttp() method is a sync blocking HTTP runner that makes
 * real file_get_contents() calls. Testing its runtime behavior requires
 * actual network access, which is not allowed in unit tests.
 *
 * Coverage of defaultHttp() returning a callable is already tested
 * in LastfmApiHttpTest::testDefaultHttpRunnerIsUsedWhenNoOverrideProvided().
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\TestCase;

/**
 * This test class was removed because its tests made real network calls.
 *
 * The defaultHttp() runtime behavior is not tested here; coverage of
 * defaultHttp() returning a callable is in LastfmApiHttpTest.
 *
 * @removed This file intentionally left empty - real network tests not allowed.
 *
 * @coversDefaultClass \Phlix\Plugins\Scrobbler\Lastfm\LastfmApi
 */
final class LastfmApiDefaultHttpTest extends TestCase
{
    /**
     * @see LastfmApiHttpTest::testDefaultHttpRunnerIsUsedWhenNoOverrideProvided
     *
     * Note: defaultHttp() makes real network calls via file_get_contents().
     * Real network calls are prohibited in unit tests. Coverage of
     * defaultHttp() returning a callable is handled in LastfmApiHttpTest.
     */
    public function testDefaultHttpReturnsCallable(): void
    {
        // Placeholder: real network tests not allowed.
        // See LastfmApiHttpTest for coverage of the HTTP runner seam.
        $this->markTestSkipped('defaultHttp() makes real network calls — coverage in LastfmApiHttpTest');
    }
}

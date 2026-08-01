<?php

/**
 * Unit::LastfmConfigTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see LastfmConfig}.
 *
 * @covers \Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
final class LastfmConfigTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $config = new LastfmConfig('api-key', 'shared-secret', true);

        $this->assertSame('api-key', $config->apiKey);
        $this->assertSame('shared-secret', $config->sharedSecret);
        $this->assertTrue($config->enabled);
    }

    public function testConstructorDefaultsEnabledToFalse(): void
    {
        $config = new LastfmConfig('api-key', 'shared-secret');

        $this->assertFalse($config->enabled);
    }

    public function testFromArrayWithCompleteConfig(): void
    {
        $config = LastfmConfig::fromArray([
            'enabled'       => true,
            'api_key'       => 'my-api-key',
            'shared_secret' => 'my-secret',
        ]);

        $this->assertSame('my-api-key', $config->apiKey);
        $this->assertSame('my-secret', $config->sharedSecret);
        $this->assertTrue($config->enabled);
    }

    public function testFromArrayWithMissingKeysDefaultsToEmpty(): void
    {
        $config = LastfmConfig::fromArray([]);

        $this->assertSame('', $config->apiKey);
        $this->assertSame('', $config->sharedSecret);
        $this->assertFalse($config->enabled);
    }

    public function testFromArrayWithNonStringApiKeyDefaultsToEmpty(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 12345,
            'shared_secret' => 'secret',
            'enabled'       => true,
        ]);

        $this->assertSame('', $config->apiKey);
        $this->assertSame('secret', $config->sharedSecret);
        $this->assertTrue($config->enabled);
    }

    public function testFromArrayWithNonStringSharedSecretDefaultsToEmpty(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'api-key',
            'shared_secret' => ['array-not-string'],
            'enabled'       => true,
        ]);

        $this->assertSame('api-key', $config->apiKey);
        $this->assertSame('', $config->sharedSecret);
        $this->assertTrue($config->enabled);
    }

    public function testFromArrayWithTruthyEnabledString(): void
    {
        // When enabled is the string 'true', it should be treated as true
        $config = LastfmConfig::fromArray([
            'enabled'       => 'true',
            'api_key'       => 'api-key',
            'shared_secret' => 'secret',
        ]);

        $this->assertTrue($config->enabled);
    }

    public function testIsUsableReturnsTrueWhenFullyConfigured(): void
    {
        $config = new LastfmConfig('api-key', 'shared-secret', true);

        $this->assertTrue($config->isUsable());
    }

    public function testIsUsableReturnsFalseWhenDisabled(): void
    {
        $config = new LastfmConfig('api-key', 'shared-secret', false);

        $this->assertFalse($config->isUsable());
    }

    public function testIsUsableReturnsFalseWhenApiKeyEmpty(): void
    {
        $config = new LastfmConfig('', 'shared-secret', true);

        $this->assertFalse($config->isUsable());
    }

    public function testIsUsableReturnsFalseWhenSharedSecretEmpty(): void
    {
        $config = new LastfmConfig('api-key', '', true);

        $this->assertFalse($config->isUsable());
    }

    public function testIsUsableReturnsFalseWhenBothCredentialsEmpty(): void
    {
        $config = new LastfmConfig('', '', true);

        $this->assertFalse($config->isUsable());
    }

    public function testIsUsableReturnsFalseWhenOnlyEnabled(): void
    {
        $config = new LastfmConfig('', '', false);

        $this->assertFalse($config->isUsable());
    }
}

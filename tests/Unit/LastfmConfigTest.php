<?php

/**
 * Unit::LastfmConfigTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use PHPUnit\Framework\Attributes\CoversClass;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see LastfmConfig}.
 *
 * @package Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
#[CoversClass(\Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig::class)]
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

    public function testFromArrayWithFalsyStringEnabledIsDisabled(): void
    {
        // A persisted string 'false' must NOT enable the plugin. Loose
        // `== true` comparison treated every non-empty string as truthy
        // (M-LASTFM-09/17): strict filter_var parsing rejects it.
        $config = LastfmConfig::fromArray([
            'enabled'       => 'false',
            'api_key'       => 'api-key',
            'shared_secret' => 'secret',
        ]);

        $this->assertFalse($config->enabled);
    }

    public function testFromArrayWithNoAndOffEnabledStringsAreDisabled(): void
    {
        foreach (['no', 'off', '0', ''] as $falsy) {
            $config = LastfmConfig::fromArray([
                'enabled'       => $falsy,
                'api_key'       => 'api-key',
                'shared_secret' => 'secret',
            ]);

            $this->assertFalse($config->enabled, sprintf('string %s must not enable', var_export($falsy, true)));
        }
    }

    public function testFromArrayWithNonScalarEnabledIsDisabled(): void
    {
        // An array/Object passed as 'enabled' is invalid input, not truthy.
        $config = LastfmConfig::fromArray([
            'enabled'       => ['yes'],
            'api_key'       => 'api-key',
            'shared_secret' => 'secret',
        ]);

        $this->assertFalse($config->enabled);
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

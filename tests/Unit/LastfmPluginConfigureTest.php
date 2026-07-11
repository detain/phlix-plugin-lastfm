<?php

/**
 * Unit::LastfmPluginConfigureTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Lastfm;

use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmPlugin;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the autowirable-constructor + {@see ConfigurableInterface} contract:
 * the host builds the entry class with no args, then delivers settings via
 * configure() before onEnable().
 */
final class LastfmPluginConfigureTest extends TestCase
{
    public function test_implements_lifecycle_and_configurable(): void
    {
        $plugin = new LastfmPlugin();
        $this->assertInstanceOf(LifecycleInterface::class, $plugin);
        $this->assertInstanceOf(ConfigurableInterface::class, $plugin);
    }

    public function test_constructor_is_autowirable_with_no_arguments(): void
    {
        // The host container calls this with zero args; it must not throw.
        $plugin = new LastfmPlugin();
        $this->assertInstanceOf(LastfmPlugin::class, $plugin);

        // With no settings applied the wrapped config is empty/unusable.
        $this->assertFalse($this->config($plugin)->isUsable());
    }

    public function test_configure_builds_config_from_settings(): void
    {
        $plugin = new LastfmPlugin();
        $plugin->configure([
            'enabled'       => true,
            'api_key'       => 'abc123',
            'shared_secret' => 'sh-secret',
            'username'      => 'joe',
        ]);

        $config = $this->config($plugin);
        $this->assertSame('abc123', $config->apiKey);
        $this->assertSame('sh-secret', $config->sharedSecret);
        $this->assertSame('joe', $config->username);
        $this->assertTrue($config->isUsable());
    }

    /** Read the plugin's private LastfmConfig for assertions. */
    private function config(LastfmPlugin $plugin): LastfmConfig
    {
        $method = new ReflectionMethod($plugin, 'configure');
        $method->getName(); // touch reflection so the class is loaded
        $prop = (new \ReflectionObject($plugin))->getProperty('config');
        $prop->setAccessible(true);
        $value = $prop->getValue($plugin);
        $this->assertInstanceOf(LastfmConfig::class, $value);
        return $value;
    }
}

<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\ConfigMissing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The first direct coverage of Config::connect()/loadConfig(), everything else
 * reaching Config through loadForTesting(). Each case define()s bogus DB_*
 * constants, process-global and irreversible, so each runs in its own process.
 */
final class ConfigTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConnectThrowsConfigMissingWithNoFileAndNoConstants(): void {
        ConfigFile::usePath(sys_get_temp_dir() . '/eppitnic-configtest-nonexistent-' . bin2hex(random_bytes(4)) . '.php');

        $this->expectException(ConfigMissing::class);
        Config::connect();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConnectReachesTheRealConnectionAttemptOncePredefined(): void {
        ConfigFile::usePath(sys_get_temp_dir() . '/eppitnic-configtest-nonexistent-' . bin2hex(random_bytes(4)) . '.php');

        // a bogus driver name, not a missing constant: proves loadConfig() got
        // past its check and connect() really tried. Not an unreachable host,
        // whose PDO timeout runs to seconds for the same outcome
        define('DB_TYPE', 'eppitnic_test_bogus_driver');
        define('DB_HOST', 'localhost');
        define('DB_NAME', 'nonexistent');
        define('DB_CHARSET', 'utf8');
        define('DB_USER', 'nobody');
        define('DB_PASSWORD', '');

        try {
            Config::connect();
            $this->fail('expected a \RuntimeException');
        } catch (ConfigMissing $e) {
            $this->fail('constants were defined but loadConfig() still treated config as missing: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unable to connect', $e->getMessage());
        }
    }
}

<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigShowCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config show` -- a local, read-only settings dump, so this needs only
 * Config::loadForTesting(). Unlike ConfigEppServerCommandTest nothing here
 * calls Config::set(), so no database is required at all.
 */
final class ConfigShowCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        Config::loadForTesting(static::SETTINGS + [
            'jwt_psk' => 'super-secret-signing-key',
        ]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testListsEverySettingKey(): void {
        $command = new ConfigShowCommand([]);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        foreach (array_keys(static::SETTINGS) as $key) {
            $this->assertStringContainsString("{$key}:", $output);
        }
    }

    public function testNeverPrintsTheJwtPsk(): void {
        $command = new ConfigShowCommand([]);
        $output = $this->capture(fn() => $command->run());

        $this->assertStringNotContainsString('super-secret-signing-key', $output);
        $this->assertStringContainsString('jwt_psk: "[redacted]"', $output);
    }

    public function testEppNeverPrintsThePasswordButReportsItIsSet(): void {
        $command = new ConfigShowCommand([]);
        $output = $this->capture(fn() => $command->run());

        $this->assertStringNotContainsString(static::SETTINGS['epp']['password'], $output);
        $this->assertStringContainsString('"password_set":true', $output);
    }

    public function testOneKeyLimitsTheOutputToThatKey(): void {
        $command = new ConfigShowCommand(['region']);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('region:', $output);
        $this->assertStringNotContainsString('jwt_psk', $output);
        $this->assertStringNotContainsString('epp:', $output);
    }

    public function testUnknownKeyIsAUsageError(): void {
        $this->expectException(UsageError::class);
        (new ConfigShowCommand(['nonexistent']))->run();
    }
}

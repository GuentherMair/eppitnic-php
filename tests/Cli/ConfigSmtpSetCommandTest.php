<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigSmtpSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config smtp-set` -- the CLI shape around Notifier's own field rules
 * (EppSettingsTest-equivalent coverage for those rules is NotifierTest's
 * job; this covers unset-by-omission, comma-separated message_types,
 * confirm/dry-run, and history).
 */
final class ConfigSmtpSetCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        Config::loadForTesting(static::SETTINGS + [
            'smtp' => [
                'enabled' => false, 'host' => 'localhost', 'port' => null, 'sender' => '',
                'recipient_mode' => 'system', 'recipient' => '', 'username' => '', 'password' => '',
                'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
            ],
        ]);
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function runCommand(array $argv): string {
        $command = new ConfigSmtpSetCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testSetsTheHost(): void {
        $this->runCommand(['--yes', 'host', 'smtp.example.it']);
        $this->assertSame('smtp.example.it', Config::get('smtp')['host']);
    }

    public function testSetsEnabledTrue(): void {
        $this->runCommand(['--yes', 'enabled', 'true']);
        $this->assertTrue(Config::get('smtp')['enabled']);
    }

    public function testRejectsAnInvalidPort(): void {
        $this->expectException(UsageError::class);
        (new ConfigSmtpSetCommand(['--yes', 'port', '999999']))->run();
    }

    public function testRejectsAnUnknownField(): void {
        $this->expectException(UsageError::class);
        (new ConfigSmtpSetCommand(['--yes', 'bogus', 'x']))->run();
    }

    public function testMessageTypesTakesACommaSeparatedList(): void {
        $this->runCommand(['--yes', 'message_types', 'passwdReminder,scheduled_deletion']);
        $this->assertSame(['passwdReminder', 'scheduled_deletion'], Config::get('smtp')['message_types']);
    }

    public function testMessageTypesRejectsAnUnknownType(): void {
        $this->expectException(UsageError::class);
        (new ConfigSmtpSetCommand(['--yes', 'message_types', 'bogus']))->run();
    }

    public function testOmittingTheValueUnsetsAnOptionalField(): void {
        $this->runCommand(['--yes', 'port', '2525']);
        $this->runCommand(['--yes', 'port']);
        $this->assertNull(Config::get('smtp')['port']);
    }

    public function testOmittingTheValueUnsetsMessageTypesToEmpty(): void {
        $this->runCommand(['--yes', 'message_types', 'passwdReminder']);
        $this->runCommand(['--yes', 'message_types']);
        $this->assertSame([], Config::get('smtp')['message_types']);
    }

    public function testOmittingTheValueOnARequiredFieldIsRejected(): void {
        $this->expectException(UsageError::class);
        (new ConfigSmtpSetCommand(['--yes', 'host']))->run();
    }

    public function testDryRunDoesNotWrite(): void {
        $command = new ConfigSmtpSetCommand(['--dry-run', 'host', 'smtp.example.it']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set', $output);
        $this->assertSame('localhost', Config::get('smtp')['host']);
    }

    public function testAlreadySetIsANoOp(): void {
        $output = $this->runCommand(['--yes', 'host', 'localhost']);
        $this->assertStringContainsString('already', $output);
    }

    public function testASuccessfulChangeIsRecordedToHistory(): void {
        $this->runCommand(['--yes', 'host', 'smtp.example.it']);

        $row = R::getRow("SELECT * FROM history WHERE object = 'smtp'");
        $this->assertNotEmpty($row);
        $this->assertStringContainsString('smtp.example.it', $row['data']);
    }

    public function testPasswordIsRedactedInTheCliRecordToo(): void {
        $this->runCommand(['--yes', 'password', 'a-real-secret']);

        $row = R::getRow("SELECT * FROM history WHERE object = 'smtp'");
        $this->assertStringNotContainsString('a-real-secret', $row['data']);
    }
}

<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ConfigAllowedOriginsCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `config allowed-origins` -- list editing over AllowedOrigins, whose own
 * canonicalisation AllowedOriginsTest covers.
 */
final class ConfigAllowedOriginsCommandTest extends EppTestCase
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

        Config::loadForTesting(static::SETTINGS + ['allowed_origins' => ['https://epp.example.it']]);
    }

    private function runCommand(array $argv): string {
        $command = new ConfigAllowedOriginsCommand($argv);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        ob_start();
        $this->assertSame(0, $command->run());
        return (string) ob_get_clean();
    }

    /** @return string[] */
    private function stored(): array {
        return array_map('strval', (array) Config::get('allowed_origins'));
    }

    public function testShowsTheCurrentList(): void {
        $this->assertStringContainsString('https://epp.example.it', $this->runCommand([]));
    }

    public function testShowSaysSoWhenTheListIsEmpty(): void {
        Config::set('allowed_origins', []);

        $this->assertStringContainsString('browsers are refused', $this->runCommand([]));
    }

    public function testAddsAnOriginCanonicallyAndRecordsHistory(): void {
        $this->runCommand(['--yes', 'add', 'HTTP://127.0.0.1:8095/']);

        $this->assertSame(['https://epp.example.it', 'http://127.0.0.1:8095'], $this->stored());
        $row = R::getRow("SELECT * FROM history WHERE object = 'allowed_origins'");
        $this->assertSame(['https://epp.example.it', 'http://127.0.0.1:8095'], json_decode($row['data'], true)['allowed_origins']);
    }

    public function testRemovesAnOriginGivenInAnotherSpelling(): void {
        $this->runCommand(['--yes', 'remove', 'https://EPP.example.it:443/']);

        $this->assertSame([], $this->stored());
    }

    public function testRemovesAHandWrittenEntryByItsCanonicalForm(): void {
        Config::set('allowed_origins', ['https://epp.example.it/', 'https://other.example.it']);

        $this->runCommand(['--yes', 'remove', 'https://epp.example.it']);

        $this->assertSame(['https://other.example.it'], $this->stored());
    }

    public function testClearEmptiesTheList(): void {
        $this->runCommand(['--yes', 'clear']);

        $this->assertSame([], $this->stored());
    }

    public function testRefusesSomethingThatIsNoOrigin(): void {
        $this->expectException(UsageError::class);
        (new ConfigAllowedOriginsCommand(['--yes', 'add', 'https://epp.example.it/app']))->run();
    }

    public function testDryRunWritesNothing(): void {
        $this->runCommand(['--yes', '--dry-run', 'add', 'https://other.example.it']);

        $this->assertSame(['https://epp.example.it'], $this->stored());
        $this->assertEmpty(R::getAll("SELECT * FROM history WHERE object = 'allowed_origins'"));
    }
}

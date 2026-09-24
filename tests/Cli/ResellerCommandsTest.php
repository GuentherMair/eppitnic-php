<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ResellerCreateCommand;
use Eppitnic\Cli\Command\ResellerListCommand;
use Eppitnic\Cli\Command\ResellerSetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * `reseller list`, `reseller create` and `reseller set`: thin CLI shells
 * around ResellerService (ResellerServiceTest covers that layer's own
 * validation), so this is about the CLI shape -- --json/--jsonl records,
 * confirm/dry-run/--yes, and that a service refusal becomes a UsageError.
 */
final class ResellerCommandsTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'contacts', 'domains', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, reseller_id INTEGER)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, reseller_id INTEGER)');
        TestAccounts::ensureReseller(1);
        TestAccounts::ensure(1, 'admin', 1);

        Config::loadForTesting([]);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // reseller list
    // ---------------------------------------------------------------

    public function testListShowsTheSeededRegistrar(): void {
        $command = new ResellerListCommand([]);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('Registrar (self)', $output);
        $this->assertStringContainsString('unlimited', $output);
    }

    public function testListShowsAQuotaWhenSet(): void {
        R::exec('UPDATE resellers SET max_operations = 5 WHERE id = 1');

        $command = new ResellerListCommand([]);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('quota=5', $output);
        $this->assertStringNotContainsString('unlimited', $output);
    }

    public function testListAsJsonlEmitsOneRecordWithCounts(): void {
        TestAccounts::ensureReseller(2, 'Two');
        TestAccounts::ensure(2, 'user', 2);

        $command = new ResellerListCommand(['--jsonl']);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $lines = array_values(array_filter(explode("\n", trim($output))));
        $this->assertCount(2, $lines);
        $row = json_decode($lines[1], true);
        $this->assertSame(2, $row['id']);
        $this->assertSame(1, $row['users']);
    }

    // ---------------------------------------------------------------
    // reseller create
    // ---------------------------------------------------------------

    public function testCreateInsertsAReseller(): void {
        $command = new ResellerCreateCommand(['--yes', 'Acme']);
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame('Acme', R::getCell("SELECT name FROM resellers WHERE name = 'Acme'"));
    }

    public function testCreateAcceptsAMaxOperations(): void {
        $command = new ResellerCreateCommand(['--yes', '--max-operations=7', 'Acme']);
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame(7, (int) R::getCell("SELECT max_operations FROM resellers WHERE name = 'Acme'"));
    }

    public function testCreateWithoutAConfirmationDoesNothing(): void {
        $command = new ResellerCreateCommand(['Acme']);
        $command->useErrorStream(fopen('php://memory', 'w+'));
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertNull(R::getCell("SELECT name FROM resellers WHERE name = 'Acme'"));
    }

    public function testCreateDryRunDoesNotWrite(): void {
        $command = new ResellerCreateCommand(['--dry-run', 'Acme']);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would create', $output);
        $this->assertNull(R::getCell("SELECT name FROM resellers WHERE name = 'Acme'"));
    }

    public function testCreateRefusesADuplicateName(): void {
        $this->expectException(UsageError::class);
        (new ResellerCreateCommand(['--yes', 'Registrar (self)']))->run();
    }

    public function testCreateRequiresAName(): void {
        $this->expectException(UsageError::class);
        (new ResellerCreateCommand(['--yes']))->run();
    }

    public function testCreateRecordsHistory(): void {
        $command = new ResellerCreateCommand(['--yes', '--user=1', 'Acme']);
        $this->capture(fn() => $command->run());

        $row = R::getRow("SELECT * FROM history WHERE object = 'resellers' AND action = 'create'");
        $this->assertNotEmpty($row);
        $this->assertSame(1, (int) $row['user_id']);
    }

    // ---------------------------------------------------------------
    // reseller set
    // ---------------------------------------------------------------

    public function testSetChangesTheName(): void {
        $command = new ResellerSetCommand(['--yes', '1', 'name', 'Renamed']);
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame('Renamed', R::getCell('SELECT name FROM resellers WHERE id = 1'));
    }

    public function testSetChangesMaxOperations(): void {
        $command = new ResellerSetCommand(['--yes', '1', 'max_operations', '3']);
        $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertSame(3, (int) R::getCell('SELECT max_operations FROM resellers WHERE id = 1'));
    }

    public function testSetRefusesToDeactivateResellerOne(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('registrar itself');
        (new ResellerSetCommand(['--yes', '1', 'active', 'false']))->run();
    }

    public function testSetRefusesAnUnknownField(): void {
        $this->expectException(UsageError::class);
        (new ResellerSetCommand(['--yes', '1', 'bogus', 'x']))->run();
    }

    public function testSetRefusesAnUnknownReseller(): void {
        $this->expectException(UsageError::class);
        (new ResellerSetCommand(['--yes', '99', 'name', 'x']))->run();
    }

    public function testSetDryRunDoesNotWrite(): void {
        $command = new ResellerSetCommand(['--dry-run', '1', 'name', 'Renamed']);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('would set', $output);
        $this->assertSame('Registrar (self)', R::getCell('SELECT name FROM resellers WHERE id = 1'));
    }

    public function testSetAlreadyMatchingIsANoOp(): void {
        $command = new ResellerSetCommand(['--yes', '1', 'name', 'Registrar (self)']);
        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('already', $output);
    }

    public function testSetRecordsHistory(): void {
        $command = new ResellerSetCommand(['--yes', '--user=1', '1', 'name', 'Renamed']);
        $this->capture(fn() => $command->run());

        $this->assertSame(
            1,
            (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'resellers' AND object_id = 1 AND action = 'update'")
        );
    }
}

<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\ResellerService;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * ResellerService: listing with counts, creation, and the guard rails on
 * update (unique name, non-negative quota, reseller 1 never deactivated).
 */
final class ResellerServiceTest extends TestCase
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
    }

    // ---------------------------------------------------------------
    // list / get
    // ---------------------------------------------------------------

    public function testListIncludesTheSeededRegistrar(): void {
        $resellers = ResellerService::list();

        $this->assertCount(1, $resellers);
        $this->assertSame('Registrar (self)', $resellers[0]['name']);
        $this->assertSame(1, $resellers[0]['users'], 'the seeded admin');
        $this->assertSame(0, $resellers[0]['domains']);
        $this->assertSame(0, $resellers[0]['contacts']);
    }

    public function testGetCountsUsersDomainsAndContacts(): void {
        TestAccounts::ensureReseller(2, 'Two');
        TestAccounts::ensure(2, 'manager', 2);
        TestAccounts::ensure(3, 'user', 2);
        R::exec('INSERT INTO domains (reseller_id) VALUES (2)');
        R::exec('INSERT INTO contacts (reseller_id) VALUES (2), (2)');

        $reseller = ResellerService::get(2);

        $this->assertSame(2, $reseller['users']);
        $this->assertSame(1, $reseller['domains']);
        $this->assertSame(2, $reseller['contacts']);
    }

    public function testGetOfAnUnknownIdIsNull(): void {
        $this->assertNull(ResellerService::get(99));
    }

    // ---------------------------------------------------------------
    // create
    // ---------------------------------------------------------------

    public function testCreateInsertsAndRecordsHistory(): void {
        $reseller = ResellerService::create('Acme', 5, 1);

        $this->assertSame('Acme', $reseller['name']);
        $this->assertSame(5, $reseller['max_operations']);
        $this->assertSame(1, $reseller['active']);
        $this->assertSame(
            1,
            (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'resellers' AND object_id = ? AND action = 'create'", [$reseller['id']])
        );
    }

    public function testCreateTrimsTheName(): void {
        $reseller = ResellerService::create('  Acme  ', 0, 1);

        $this->assertSame('Acme', $reseller['name']);
    }

    public function testCreateRefusesABlankName(): void {
        $this->expectException(\InvalidArgumentException::class);
        ResellerService::create('   ', 0, 1);
    }

    public function testCreateRefusesANameOver64Characters(): void {
        $this->expectException(\InvalidArgumentException::class);
        ResellerService::create(str_repeat('x', 65), 0, 1);
    }

    public function testCreateRefusesADuplicateNameIgnoringCase(): void {
        ResellerService::create('Acme', 0, 1);

        $this->expectException(\InvalidArgumentException::class);
        ResellerService::create('ACME', 0, 1);
    }

    public function testCreateRefusesANegativeQuota(): void {
        $this->expectException(\InvalidArgumentException::class);
        ResellerService::create('Acme', -1, 1);
    }

    // ---------------------------------------------------------------
    // update
    // ---------------------------------------------------------------

    public function testUpdateChangesTheGivenFieldsAndLeavesTheRestAlone(): void {
        $reseller = ResellerService::create('Acme', 5, 1);

        $updated = ResellerService::update($reseller['id'], ['max_operations' => 10], 1);

        $this->assertSame('Acme', $updated['name']);
        $this->assertSame(10, $updated['max_operations']);
    }

    public function testUpdateRecordsHistory(): void {
        $reseller = ResellerService::create('Acme', 5, 1);

        ResellerService::update($reseller['id'], ['name' => 'Acme Corp'], 1);

        $this->assertSame(
            1,
            (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'resellers' AND object_id = ? AND action = 'update'", [$reseller['id']])
        );
    }

    public function testUpdateOfAnUnknownResellerIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        ResellerService::update(99, ['name' => 'x'], 1);
    }

    public function testUpdateRefusesAnUnknownField(): void {
        $reseller = ResellerService::create('Acme', 0, 1);

        $this->expectException(\InvalidArgumentException::class);
        ResellerService::update($reseller['id'], ['bogus' => 'x'], 1);
    }

    public function testUpdateRefusesADuplicateNameIgnoringCase(): void {
        ResellerService::create('Acme', 0, 1);
        $other = ResellerService::create('Other', 0, 1);

        $this->expectException(\InvalidArgumentException::class);
        ResellerService::update($other['id'], ['name' => 'acme'], 1);
    }

    public function testUpdateAllowsKeepingItsOwnName(): void {
        $reseller = ResellerService::create('Acme', 0, 1);

        $updated = ResellerService::update($reseller['id'], ['name' => 'Acme', 'max_operations' => 3], 1);

        $this->assertSame('Acme', $updated['name']);
        $this->assertSame(3, $updated['max_operations']);
    }

    #[DataProvider('truthyProvider')]
    public function testActiveAcceptsSeveralSpellings(mixed $value, bool $expectedActive): void {
        $reseller = ResellerService::create('Acme', 0, 1);

        $updated = ResellerService::update($reseller['id'], ['active' => $value], 1);

        $this->assertSame($expectedActive ? 1 : 0, $updated['active']);
    }

    public static function truthyProvider(): array {
        return [
            [true, true], [false, false],
            [1, true], [0, false],
            ['on', true], ['off', false],
            ['yes', true], ['no', false],
        ];
    }

    public function testActiveRejectsAnUnrecognizedValue(): void {
        $reseller = ResellerService::create('Acme', 0, 1);

        $this->expectException(\InvalidArgumentException::class);
        ResellerService::update($reseller['id'], ['active' => 'maybe'], 1);
    }

    public function testResellerOneCanNeverBeDeactivated(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reseller 1 (the registrar itself) cannot be deactivated');
        ResellerService::update(1, ['active' => false], 1);
    }

    public function testResellerOneCanStillBeUpdatedOtherwise(): void {
        $updated = ResellerService::update(1, ['max_operations' => 7], 1);

        $this->assertSame(7, $updated['max_operations']);
        $this->assertSame(1, $updated['active']);
    }
}

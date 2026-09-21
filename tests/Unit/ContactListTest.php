<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Contact;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * The contact list is a local read that carries each contact's status flags,
 * so a client can show whether one is linked without asking the registry once
 * per row.
 */
final class ContactListTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS contacts');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, org TEXT, name TEXT,
                 entitytype INTEGER, status TEXT, user_id INTEGER, active INTEGER DEFAULT 1)');
    }

    private function insert(string $handle, ?string $status, int $active = 1): void {
        R::exec('INSERT INTO contacts (handle, org, name, entitytype, status, user_id, active) VALUES (?, ?, ?, 2, ?, 1, ?)',
            [$handle, $handle, $handle, $status, $active]);
    }

    /**
     * @return array<string, string[]> handle => its status flags
     */
    private function statuses(bool $activeOnly = true): array {
        $rows = (new Contact($this->nic))->listContacts(1, true, $activeOnly);
        return array_column($rows, 'status', 'handle');
    }

    public function testCurrentCodeStoresItPlain(): void {
        $this->insert('A', serialize(['ok', 'linked']));

        $this->assertSame(['ok', 'linked'], $this->statuses()['A']);
    }

    /**
     * 6.x base64'd the column behind a marker, and one table holds both shapes.
     */
    public function testSixPointXWrappedItInAnEnvelope(): void {
        $this->insert('B', '__SERIALIZED:' . base64_encode(serialize(['ok', 'linked'])));

        $this->assertSame(['ok', 'linked'], $this->statuses()['B']);
    }

    public function testAnEmptyOrUnreadableValueIsNoFlagsRatherThanAnError(): void {
        $this->insert('C', null);
        $this->insert('D', '0');
        $this->insert('E', '__SERIALIZED:%%%not base64%%%');

        $this->assertSame(['C' => [], 'D' => [], 'E' => []], $this->statuses());
    }

    public function testAContactWithoutTheFlagCarriesOthers(): void {
        $this->insert('F', serialize(['ok']));

        $this->assertNotContains('linked', $this->statuses()['F']);
    }

    public function testTheInactiveStayOutOfTheDefaultList(): void {
        $this->insert('G', serialize(['ok']), 0);

        $this->assertArrayNotHasKey('G', $this->statuses());
        $this->assertArrayHasKey('G', $this->statuses(false));
    }
}

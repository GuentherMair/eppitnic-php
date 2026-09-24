<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * The domain list is a local read that carries each domain's status flags, so
 * a client can show them without asking the registry once per row.
 */
final class DomainListTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS domains');
        R::exec('DROP TABLE IF EXISTS transfers');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT,
                 status TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT, reseller_id INTEGER)');
    }

    private function insert(string $domain, ?string $status, int $active = 1): void {
        R::exec('INSERT INTO domains (domain, registrant, status, reseller_id, active) VALUES (?, ?, ?, 1, ?)',
            [$domain, 'REG1', $status, $active]);
    }

    /**
     * @return array<string, string[]> domain => its status flags
     */
    private function statuses(bool $activeOnly = true): array {
        $rows = (new Domain($this->nic))->listDomains(Scope::operator(1), null, $activeOnly);
        return array_column($rows, 'status', 'domain');
    }

    public function testCurrentCodeStoresItPlain(): void {
        $this->insert('a.it', serialize(['ok']));

        $this->assertSame(['ok'], $this->statuses()['a.it']);
    }

    /**
     * 6.x base64'd the column behind a marker, and one table holds both shapes.
     */
    public function testSixPointXWrappedItInAnEnvelope(): void {
        $this->insert('b.it', '__SERIALIZED:' . base64_encode(serialize(['ok', 'clientHold'])));

        $this->assertSame(['ok', 'clientHold'], $this->statuses()['b.it']);
    }

    public function testAnEmptyOrUnreadableValueIsNoFlagsRatherThanAnError(): void {
        $this->insert('c.it', null);
        $this->insert('d.it', '0');
        $this->insert('e.it', '__SERIALIZED:%%%not base64%%%');

        $this->assertSame(['c.it' => [], 'd.it' => [], 'e.it' => []], $this->statuses());
    }

    public function testTheInactiveStayOutOfTheDefaultList(): void {
        $this->insert('f.it', serialize(['ok']), 0);

        $this->assertArrayNotHasKey('f.it', $this->statuses());
        $this->assertArrayHasKey('f.it', $this->statuses(false));
    }

    /**
     * Not a domain EPP has confirmed exists locally yet, so it carries no
     * status of its own -- but the field is still there for a client that
     * expects every row to have one.
     */
    public function testAPendingTransferInHasNoStatusOfItsOwn(): void {
        R::exec('INSERT INTO transfers (domain, registrant, reseller_id) VALUES (?, ?, 1)', ['g.it', 'REG1']);

        $rows = (new Domain($this->nic))->listDomains(Scope::operator(1), null, true);
        $transferIn = current(array_filter($rows, fn($row) => $row['domain'] === 'g.it (transfer-in)'));

        $this->assertSame([], $transferIn['status']);
    }
}

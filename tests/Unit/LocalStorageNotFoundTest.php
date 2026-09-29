<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * A local write that matches no row -- missing, or another reseller's -- is a
 * failure with nothing in `history`, not a success recorded under object 0.
 */
final class LocalStorageNotFoundTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'contacts', 'history', 'settings', 'tasks'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 registrant TEXT, ns TEXT, admin TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER, email TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, object TEXT,
                 action TEXT, active INTEGER DEFAULT 1, exit_code INTEGER, exit_message TEXT)');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REG1', 2)");
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES ('mine.it', 2, 'REG1')");
    }

    private function history(): int {
        return (int) R::getCell('SELECT COUNT(*) FROM history');
    }

    public function testDeactivatingAMissingDomainFails(): void {
        $domain = new Domain($this->nic);

        $this->assertFalse($domain->deleteDomainDB('missing.it', Scope::operator(1)));
        $this->assertStringContainsString("'missing.it' not found", $domain->getError());
        $this->assertSame(0, $this->history());
    }

    public function testAnotherResellersDomainIsNotFoundEither(): void {
        $domain = new Domain($this->nic);

        $this->assertFalse($domain->deleteDomainDB('mine.it', new Scope(9, 3, 'user')));
        $this->assertSame(1, (int) R::getCell("SELECT active FROM domains WHERE domain = 'mine.it'"));
        $this->assertSame(0, $this->history());
    }

    public function testDeactivatingOwnDomainStillWorksAndRecordsItsId(): void {
        $this->assertTrue((new Domain($this->nic))->deleteDomainDB('mine.it', new Scope(9, 2, 'user')));

        $this->assertSame(0, (int) R::getCell("SELECT active FROM domains WHERE domain = 'mine.it'"));
$this->assertSame(1, (int) R::getCell("SELECT object_id FROM history"));
    }

    public function testAnUpdateOfAMissingContactFails(): void {
        $contact = new Contact($this->nic);
        $contact->set('handle', 'NOSUCH');
        $contact->set('email', 'a@example.it');

        $this->assertFalse($contact->updateDB('NOSUCH', Scope::operator(1), ['email']));
        $this->assertStringContainsString("'NOSUCH' not found", $contact->getError());
        $this->assertSame(0, $this->history());
    }

    public function testAContactStillARegistrantIsNotDeactivated(): void {
        $contact = new Contact($this->nic);

        $this->assertFalse($contact->deleteContactDB('REG1', Scope::operator(1)));
        $this->assertStringContainsString('still in use', $contact->getError());
        $this->assertSame(1, (int) R::getCell("SELECT active FROM contacts WHERE handle = 'REG1'"));
        $this->assertSame(0, $this->history());
    }
}

<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\PollProcessor;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * verifyTransfer() acts on our own pending transfer-ins only: another
 * registrar's request for one of our domains stays in the queue, and a
 * cancelled request is a record nothing reconciles.
 */
final class PollProcessorTransferTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['messages', 'transfers', 'contacts', 'domains', 'history', 'settings', 'tasks'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        // SQLite has no NOW()
        @R::getPDO()->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'), 0);

        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, type TEXT, domain TEXT, ac_id TEXT, archived_time TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT, registrant TEXT,
                 techc TEXT, dns TEXT, status TEXT NOT NULL DEFAULT 'pending')");
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 registrant TEXT, admin TEXT, tech TEXT, ns TEXT, authinfo TEXT, dnssec TEXT,
                 status TEXT, cr_date TEXT, ex_date TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, object TEXT,
                 action TEXT, active INTEGER DEFAULT 1, exit_code INTEGER, exit_message TEXT)');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REGI1234REGI5678', 2)");
    }

    private function message(int $id, string $type, string $domain, string $acId = 'OTHER-REG'): void {
        R::exec('INSERT INTO messages (id, type, domain, ac_id) VALUES (?, ?, ?, ?)', [$id, $type, $domain, $acId]);
    }

    private function transfer(string $domain, string $status = 'pending', string $dns = 'a:0:{}'): void {
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant, techc, dns, status)
                 VALUES (2, ?, 'REGI1234REGI5678', 'a:0:{}', ?, ?)", [$domain, $dns, $status]);
    }

    private function archived(int $id): bool {
        return R::getCell('SELECT archived_time FROM messages WHERE id = ?', [$id]) !== null;
    }

    private function verify(): array {
        return (new PollProcessor($this->nic))->verifyTransfer();
    }

    public function testATransferOutRequestStaysInTheQueue(): void {
        $this->message(1, 'pendingTransfer', 'ours.it');

        $this->verify();

        $this->assertFalse($this->archived(1));
    }

    public function testAPendingNoticeForOurOwnTransferInIsArchived(): void {
        $this->transfer('example-one.it');
        $this->message(1, 'pendingTransfer', 'example-one.it');
        $this->message(2, 'pendingTransfer', 'ours.it');
        $this->transport->queue(CommandCatalog::TRANSFER_QUERY_RESPONSE);

        $this->verify();

        $this->assertTrue($this->archived(1));
        $this->assertFalse($this->archived(2));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'), 'still pending at the registry');
    }

    /** @return array<string, array{0: string}> */
    public static function cancellations(): array {
        return [
            'by us'        => ['clientCancelledTransfer'],
            'by the registry' => ['serverCancelledTransfer'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cancellations')]
    public function testACancelledTransferRemovesItsNote(string $type): void {
        $this->transfer('example-one.it');
        $this->message(1, $type, 'example-one.it');

        $log = $this->verify();

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
        $this->assertStringContainsString('removing transfer note', implode("\n", $log));
        $this->assertSame([], $this->transport->requests);
    }

    public function testACancelledRowIsLeftAlone(): void {
        $this->transfer('example-one.it', 'cancelled');
        $this->message(1, 'pendingTransfer', 'example-one.it');

        $this->verify();

        $this->assertSame('cancelled', R::getCell('SELECT status FROM transfers'));
        $this->assertFalse($this->archived(1));
        $this->assertSame([], $this->transport->requests);
    }

    public function testACompletedTransferIsStoredAndItsNoteRemoved(): void {
        $this->transfer('example-one.it', 'pending', serialize([['name' => 'ns1.example-one.it', 'ip' => ['192.0.2.9']]]));
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->verify();

        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM domains WHERE domain = 'example-one.it'"));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.9</domain:hostAddr>', implode('', $this->transport->requests));
    }

    public function testATransferThatCannotBeStoredKeepsItsRow(): void {
        $this->transfer('example-one.it');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        R::exec("CREATE TRIGGER refuse_domains BEFORE INSERT ON domains BEGIN SELECT RAISE(ABORT, 'refused'); END");

        $log = $this->verify();

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertFalse($this->archived(1));
        $this->assertStringContainsString("couldn't store domain locally", implode("\n", $log));
    }
}

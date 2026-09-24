<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\DomainReapDeletionsCommand;
use Eppitnic\Config;
use Eppitnic\Service\Notifier;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeMailer;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `domain reap-deletions` end to end: it is what actually reaches the
 * registry for a deletion `DELETE /v1/domains/{name}?mode=expiry|date`
 * only ever queued (see src/Api/Routes/domain.php).
 */
final class DomainReapDeletionsTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['tasks', 'domains', 'settings', 'history', 'users'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER)');
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER, email TEXT, active INTEGER DEFAULT 1,
                 notify_enabled INTEGER DEFAULT 0, notify_message_types TEXT, notify_fulltext TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1, executed_time TEXT,
                 exit_code INTEGER, exit_message TEXT, created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');

        Config::loadForTesting(static::SETTINGS + [
            'domain_reap_deletions' => ['enabled' => true, 'frequency_minutes' => 15, 'last_run_at' => null],
            'smtp' => [
                'enabled' => false, 'host' => 'localhost', 'port' => null, 'sender' => '',
                'recipient_mode' => 'system', 'recipient' => '', 'username' => '', 'password' => '',
                'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
            ],
        ]);

        FakeMailer::reset();
        Notifier::useMailerFactory(fn() => new FakeMailer(true));
    }

    protected function tearDown(): void {
        Notifier::useMailerFactory(null);
        parent::tearDown();
    }

    private function addDomain(string $name, ?int $resellerId = 1): void {
        R::exec('INSERT INTO domains (domain, active, reseller_id) VALUES (?, 1, ?)', [$name, $resellerId]);
    }

    private function addDueTask(string $domain, string $date = 'today'): int {
        R::exec("INSERT INTO tasks (domain, date, notice, object, action, active) VALUES (?, ?, 'scheduled deletion', 'registry', 'delete', 1)",
            [$domain, date('Y-m-d', strtotime($date))]);
        return (int) R::getCell('SELECT id FROM tasks WHERE domain = ? ORDER BY id DESC LIMIT 1', [$domain]);
    }

    /**
     * A registry row that isn't explicitly a delete -- object='registry' but
     * no (or a different) action -- must never be picked up. The query
     * itself is the gate, not a PHP-level check.
     */
    private function addNonDeleteRegistryTask(string $domain, ?string $action, string $date = 'today'): int {
        R::exec("INSERT INTO tasks (domain, date, notice, object, action, active) VALUES (?, ?, 'not a deletion', 'registry', ?, 1)",
            [$domain, date('Y-m-d', strtotime($date)), $action]);
        return (int) R::getCell('SELECT id FROM tasks WHERE domain = ? ORDER BY id DESC LIMIT 1', [$domain]);
    }

    /**
     * @param string[] $responses what the registry answers, after login --
     *        one per domain the run is expected to reach
     */
    private function reap(array $args, array $responses): string {
        $command = new DomainReapDeletionsCommand($args);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        foreach ($responses as $response) {
            $transport->queue($response);
        }
        $transport->queue(CommandCatalog::OK_RESPONSE); // logout()
        $this->nic->setTransport($transport);
        $command->useClient($this->nic);

        ob_start();
        $this->exitCode = $command->run();
        $command->flush();
        return (string) ob_get_clean();
    }

    private int $exitCode = 0;

    private const REJECTED_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="2304"><msg lang="en">Object status prohibits operation</msg></result>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    public function testNothingDueIsNotAnError(): void {
        $output = $this->reap([], []);

        $this->assertSame(0, $this->exitCode);
        $this->assertStringContainsString('no deletions due', $output);
    }

    public function testADueDeletionIsCarriedOutAndTheTaskRetired(): void {
        $this->addDomain('example-one.it');
        $id = $this->addDueTask('example-one.it');

        $output = $this->reap([], [CommandCatalog::OK_RESPONSE]);

        $this->assertSame(0, $this->exitCode);
        $this->assertStringContainsString('example-one.it', $output);
        $this->assertSame(0, (int) R::getCell('SELECT active FROM domains WHERE domain = ?', ['example-one.it']), 'the domain was not deactivated locally');

        $row = R::getRow('SELECT active, exit_code, exit_message, executed_time FROM tasks WHERE id = ?', [$id]);
        $this->assertSame(0, (int) $row['active'], 'a successful deletion was not retired');
        $this->assertSame(0, (int) $row['exit_code']);
        $this->assertNotNull($row['executed_time']);
    }

    /**
     * A registry refusal is not terminal -- it stays active so the next run
     * retries it, but the failure is recorded for an admin to see.
     */
    public function testARegistryRefusalLeavesTheTaskForTheNextRun(): void {
        $this->addDomain('example-one.it');
        $id = $this->addDueTask('example-one.it');

        $this->reap([], [self::REJECTED_RESPONSE]);

        $this->assertSame(DOMAIN_DELETE_FAILED, $this->exitCode);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM domains WHERE domain = ?', ['example-one.it']), 'a rejected delete must not deactivate the domain locally');

        $row = R::getRow('SELECT active, exit_code, exit_message FROM tasks WHERE id = ?', [$id]);
        $this->assertSame(1, (int) $row['active'], 'a failed deletion was retired');
        $this->assertSame(1, (int) $row['exit_code']);
        $this->assertStringContainsString('prohibits operation', $row['exit_message']);
    }

    public function testARowDatedInTheFutureIsNotPickedUp(): void {
        $this->addDomain('example-one.it');
        $this->addDueTask('example-one.it', '+1 day');

        $output = $this->reap([], []);

        $this->assertStringContainsString('no deletions due', $output);
    }

    /**
     * object='registry' alone is not enough -- only action='delete' makes a
     * row this command's to act on. A row with no action at all, or some
     * other action, must be left completely untouched: not deleted, not
     * even read into the batch.
     */
    public function testARegistryRowThatIsNotADeleteIsIgnored(): void {
        $this->addDomain('example-one.it');
        $noAction = $this->addNonDeleteRegistryTask('example-one.it', null);
        $this->addDomain('example-two.it');
        $wrongAction = $this->addNonDeleteRegistryTask('example-two.it', 'create');

        $output = $this->reap([], []);

        $this->assertStringContainsString('no deletions due', $output);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM tasks WHERE id = ?', [$noAction]));
        $this->assertSame(1, (int) R::getCell('SELECT active FROM tasks WHERE id = ?', [$wrongAction]));
    }

    /**
     * A dry run must not delete anything locally or touch the task queue --
     * it only shows what would happen.
     */
    public function testDryRunTouchesNeitherTheDomainNorTheQueue(): void {
        $this->addDomain('example-one.it');
        $id = $this->addDueTask('example-one.it');

        // --dry-run never reaches the transport at all (Command::withSession()
        // substitutes its own DryRun client), so nothing needs to be queued
        $output = $this->reap(['--dry-run'], []);

        $this->assertStringContainsString('<domain:delete', $output);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM domains WHERE domain = ?', ['example-one.it']));

        $row = R::getRow('SELECT active, executed_time FROM tasks WHERE id = ?', [$id]);
        $this->assertSame(1, (int) $row['active']);
        $this->assertNull($row['executed_time']);
    }

    /**
     * More than one due deletion is carried out in the same session -- one
     * login for the whole batch, not one per domain.
     */
    public function testSeveralDueDeletionsShareOneSession(): void {
        $this->addDomain('example-one.it');
        $this->addDomain('example-two.it');
        $this->addDueTask('example-one.it');
        $this->addDueTask('example-two.it');

        $this->reap([], [CommandCatalog::OK_RESPONSE, CommandCatalog::OK_RESPONSE]);

        $this->assertSame(0, $this->exitCode);
        $this->assertSame(0, (int) R::getCell('SELECT active FROM domains WHERE domain = ?', ['example-one.it']));
        $this->assertSame(0, (int) R::getCell('SELECT active FROM domains WHERE domain = ?', ['example-two.it']));
    }

    private function enableSmtp(array $overrides = []): void {
        Config::loadForTesting(static::SETTINGS + [
            'domain_reap_deletions' => ['enabled' => true, 'frequency_minutes' => 15, 'last_run_at' => null],
            'smtp' => array_merge([
                'enabled' => true, 'host' => 'localhost', 'port' => null, 'sender' => 'eppitnic@example.it',
                'recipient_mode' => 'system', 'recipient' => 'admin@example.it', 'username' => '', 'password' => '',
                'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
            ], $overrides),
        ]);
    }

    public function testASuccessfulRunNotifiesTheSystemRecipient(): void {
        $this->enableSmtp();
        $this->addDomain('example-one.it');
        $this->addDueTask('example-one.it');

        $this->reap([], [CommandCatalog::OK_RESPONSE]);

        $this->assertCount(1, FakeMailer::$sent);
        $this->assertSame('admin@example.it', FakeMailer::$sent[0]['to']);
        $this->assertStringContainsString('example-one.it: deleted', FakeMailer::$sent[0]['body']);
    }

    public function testAFailedDeletionIsAlsoInTheSummary(): void {
        $this->enableSmtp();
        $this->addDomain('example-one.it');
        $this->addDueTask('example-one.it');

        $this->reap([], [self::REJECTED_RESPONSE]);

        $this->assertCount(1, FakeMailer::$sent);
        $this->assertStringContainsString('example-one.it: FAILED', FakeMailer::$sent[0]['body']);
    }

    public function testMultipleDeletionsAreSummarizedInOneEmail(): void {
        $this->enableSmtp();
        $this->addDomain('example-one.it');
        $this->addDomain('example-two.it');
        $this->addDueTask('example-one.it');
        $this->addDueTask('example-two.it');

        $this->reap([], [CommandCatalog::OK_RESPONSE, self::REJECTED_RESPONSE]);

        $this->assertCount(1, FakeMailer::$sent, 'one summary email, not one per domain');
        $this->assertStringContainsString('example-one.it: deleted', FakeMailer::$sent[0]['body']);
        $this->assertStringContainsString('example-two.it: FAILED', FakeMailer::$sent[0]['body']);
    }

    public function testEachOwningResellerGetsOnlyItsOwnDomains(): void {
        R::exec("INSERT INTO users (id, reseller_id, email, notify_enabled) VALUES
                 (5, 2, 'alice@example.it', 1), (6, 3, 'bob@example.it', 1), (7, 3, 'muted@example.it', 0)");
        $this->enableSmtp(['recipient_mode' => 'both']);
        $this->addDomain('example-one.it', 2);
        $this->addDomain('example-two.it', 3);
        $this->addDueTask('example-one.it');
        $this->addDueTask('example-two.it');

        $this->reap([], [CommandCatalog::OK_RESPONSE, CommandCatalog::OK_RESPONSE]);

        $this->assertCount(3, FakeMailer::$sent, 'one system summary plus one per recipient of each owning reseller');
        $byRecipient = array_column(FakeMailer::$sent, 'body', 'to');
        $this->assertStringContainsString('example-one.it', $byRecipient['alice@example.it']);
        $this->assertStringNotContainsString('example-two.it', $byRecipient['alice@example.it']);
        $this->assertStringContainsString('example-two.it', $byRecipient['bob@example.it']);
    }

    public function testDryRunNeverNotifies(): void {
        $this->enableSmtp();
        $this->addDomain('example-one.it');
        $this->addDueTask('example-one.it');

        $this->reap(['--dry-run'], []);

        $this->assertSame([], FakeMailer::$sent);
    }

    public function testDisabledSmtpNeverNotifies(): void {
        $this->addDomain('example-one.it'); // smtp stays disabled -- setUp()'s own default
        $this->addDueTask('example-one.it');

        $this->reap([], [CommandCatalog::OK_RESPONSE]);

        $this->assertSame([], FakeMailer::$sent);
    }
}

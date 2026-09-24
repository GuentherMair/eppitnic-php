<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\PollProcessCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Service\Notifier;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeMailer;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `poll process`'s own `poll_process.enabled` guard -- the actual
 * drain/reconcile/rotate behaviour when enabled is exercised through
 * PollProcessor/RegistryPasswordChange's own tests, not duplicated here.
 * The notify-on-drain glue (watermark a message id, notify only what is
 * newer) is covered below against an empty queue -- Notifier's own
 * filtering/routing is NotifierTest's job, not re-tested here.
 */
final class PollProcessCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['settings', 'messages'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, cl_trid TEXT, sv_trid TEXT, type TEXT,
                 domain TEXT, ac_id TEXT, re_id TEXT, data TEXT, archived_time TEXT, archived_user_id INTEGER,
                 created_time TEXT DEFAULT CURRENT_TIMESTAMP)');

        Config::loadForTesting(static::SETTINGS + [
            'poll_process' => ['enabled' => false, 'frequency_minutes' => 5, 'last_run_at' => null],
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

    private function capture(callable $fn): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    /** An empty registry queue: hello, login, one poll req with no msgQ, logout. */
    private function runAgainstAnEmptyQueue(array $args = []): string {
        $command = new PollProcessCommand($args);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE); // login
        $transport->queue(CommandCatalog::OK_RESPONSE); // poll req -- no msgQ, count() reads as 0
        $transport->queue(CommandCatalog::OK_RESPONSE); // logout
        $this->nic->setTransport($transport);
        $command->useClient($this->nic);

        return $this->capture(fn() => $this->assertSame(0, $command->run()));
    }

    public function testDisabledIsANoOpAndNeverTouchesTheRegistry(): void {
        $command = new PollProcessCommand([]);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('poll process is off', $output);
        $this->assertStringContainsString('config poll-process-set enabled true', $output);
    }

    /**
     * The enabled check comes before the --dry-run rejection: a disabled job
     * reports itself off rather than throwing over a flag that would not
     * have mattered anyway.
     */
    public function testDisabledShortCircuitsBeforeTheDryRunRejection(): void {
        $command = new PollProcessCommand(['--dry-run']);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $output = $this->capture(fn() => $this->assertSame(0, $command->run()));

        $this->assertStringContainsString('poll process is off', $output);
    }

    public function testEnabledReachesThePastDryRunRejection(): void {
        Config::loadForTesting(static::SETTINGS + [
            'poll_process' => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
        ]);

        $this->expectException(UsageError::class);
        (new PollProcessCommand(['--dry-run']))->run();
    }

    public function testAnEmptyQueueNotifiesNothing(): void {
        Config::loadForTesting(static::SETTINGS + [
            'poll_process' => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
            'smtp' => [
                'enabled' => true, 'host' => 'localhost', 'port' => null, 'sender' => 'eppitnic@example.it',
                'recipient_mode' => 'system', 'recipient' => 'admin@example.it', 'username' => '', 'password' => '',
                'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
            ],
        ]);

        $this->runAgainstAnEmptyQueue();

        $this->assertSame([], FakeMailer::$sent);
    }

    /**
     * The watermark (max messages.id before this run) is the boundary --
     * anything already there when the run starts must never be re-notified,
     * regardless of how the id compares to whatever the registry drains.
     */
    public function testAPreExistingMessageIsNeverReNotified(): void {
        R::exec("INSERT INTO messages (type, domain, data) VALUES ('passwdReminder', NULL, 'an old reminder')");

        Config::loadForTesting(static::SETTINGS + [
            'poll_process' => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
            'smtp' => [
                'enabled' => true, 'host' => 'localhost', 'port' => null, 'sender' => 'eppitnic@example.it',
                'recipient_mode' => 'system', 'recipient' => 'admin@example.it', 'username' => '', 'password' => '',
                'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
            ],
        ]);

        $this->runAgainstAnEmptyQueue();

        $this->assertSame([], FakeMailer::$sent, 'a message from before this run must not be re-notified');
    }
}

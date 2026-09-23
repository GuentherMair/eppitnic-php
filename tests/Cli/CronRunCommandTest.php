<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\CronRunCommand;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * `cron run`'s due/not-due decisions and its `last_run_at` bookkeeping. Every
 * scenario here keeps `tasks`/`domains` empty and `keepalive` off, so a due
 * job's own command returns before ever opening a registry session --
 * `--dry-run` is used instead wherever a job would otherwise be due and
 * unconditionally reach the network (poll_process/keepalive have no local
 * table to stay empty against).
 */
final class CronRunCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['settings', 'domains', 'tasks', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, user_id INTEGER,
                 active INTEGER DEFAULT 1, ex_date TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1, executed_time TEXT,
                 exit_code INTEGER, exit_message TEXT, created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
    }

    private const DEFAULTS = [
        'pdns'                  => ['enabled' => false, 'path' => null, 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
        'domain_sync'           => ['enabled' => false, 'batch_size' => 25, 'cursor_id' => 0, 'frequency_minutes' => 5, 'last_run_at' => null],
        'domain_reap_deletions' => ['enabled' => true, 'frequency_minutes' => 15, 'last_run_at' => null],
        'poll_process'          => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
    ];

    /** @param array<string, array<string, mixed>> $overrides job => fields to override */
    private function configure(array $overrides = []): void {
        $jobs = self::DEFAULTS;
        foreach ($overrides as $job => $fields) {
            $jobs[$job] = $fields + $jobs[$job];
        }
        Config::loadForTesting($jobs + static::SETTINGS);
    }

    private static function minutesAgo(int $minutes): string {
        return date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
    }

    private function capture(array $args = []): array {
        $command = new CronRunCommand($args);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $code = $command->run();
        $output = (string) ob_get_clean();

        return [$code, $output];
    }

    public function testDryRunReportsDueJobsWithoutRunningOrWritingLastRunAt(): void {
        $this->configure([
            'pdns'                  => ['enabled' => true, 'last_run_at' => null],
            'domain_sync'           => ['enabled' => false],
            'domain_reap_deletions' => ['enabled' => true, 'last_run_at' => null],
            'poll_process'          => ['last_run_at' => null],
        ]);

        [$code, $output] = $this->capture(['--dry-run']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('would run: pdns, domain_reap_deletions, poll_process, keepalive', $output);
        $this->assertStringNotContainsString('domain_sync', $output);

        $this->assertNull(Config::get('pdns')['last_run_at']);
        $this->assertNull(Config::get('domain_reap_deletions')['last_run_at']);
        $this->assertNull(Config::get('poll_process')['last_run_at']);
    }

    public function testDisabledAndNotYetDueJobsAreSkipped(): void {
        $this->configure([
            'pdns'                  => ['enabled' => false],
            'domain_sync'           => ['enabled' => false],
            'domain_reap_deletions' => ['enabled' => false],
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
        ]);

        [$code, $output] = $this->capture(['--dry-run']);

        $this->assertSame(0, $code);
        // keepalive has no due-ness of its own -- always listed, even here
        $this->assertStringContainsString('would run: keepalive', $output);
        foreach (['pdns', 'domain_sync', 'domain_reap_deletions', 'poll_process'] as $job) {
            $this->assertStringNotContainsString($job, $output);
        }
    }

    public function testElapsedFrequencyDeterminesDueness(): void {
        $this->configure([
            'pdns'        => ['enabled' => true, 'last_run_at' => self::minutesAgo(20), 'frequency_minutes' => 15],
            'domain_sync' => ['enabled' => true, 'last_run_at' => self::minutesAgo(2), 'frequency_minutes' => 5],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringContainsString('pdns', $output);
        $this->assertStringNotContainsString('domain_sync', $output);
    }

    public function testNullLastRunAtIsImmediatelyDue(): void {
        $this->configure([
            'domain_reap_deletions' => ['enabled' => true, 'last_run_at' => null],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringContainsString('domain_reap_deletions', $output);
    }

    public function testPollProcessDueOnElapsedTimeWhenEnabled(): void {
        $this->configure([
            'poll_process' => ['enabled' => true, 'last_run_at' => self::minutesAgo(10), 'frequency_minutes' => 5],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringContainsString('poll_process', $output);
    }

    /**
     * Disabling it is a real foot-gun (the registry password stops
     * auto-rotating) -- see PollProcessCommand -- but `cron run` respects it
     * exactly like every other job's `enabled`, in both --dry-run and a real
     * run (which never opens a registry session to check, since the
     * disabled command returns before that point).
     */
    public function testPollProcessDisabledIsSkippedEvenWhenDue(): void {
        $this->configure([
            'poll_process' => ['enabled' => false, 'last_run_at' => null],
        ]);

        [, $dryRunOutput] = $this->capture(['--dry-run']);
        $this->assertStringNotContainsString('poll_process', $dryRunOutput);

        [$code, $output] = $this->capture();
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('poll_process', $output);
        $this->assertNull(Config::get('poll_process')['last_run_at'], 'a disabled job must not be run');
    }

    public function testARealRunUpdatesLastRunAtOnlyForJobsItRan(): void {
        $this->configure([
            'pdns'                  => ['enabled' => true, 'last_run_at' => null],
            'domain_sync'           => ['enabled' => false],
            'domain_reap_deletions' => ['enabled' => true, 'last_run_at' => null],
            // kept not-due, so PollProcessCommand (which always opens a
            // session) is never actually invoked in this test
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
        ]);

        [$code, $output] = $this->capture();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ran: pdns, domain_reap_deletions', $output);

        $this->assertNotNull(Config::get('pdns')['last_run_at']);
        $this->assertNotNull(Config::get('domain_reap_deletions')['last_run_at']);
        $this->assertNull(Config::get('domain_sync')['last_run_at'], 'a disabled job must not be run');
        $this->assertSame(
            self::minutesAgo(1),
            Config::get('poll_process')['last_run_at'],
            'a not-yet-due job must not be run'
        );
    }

    public function testNothingDueReportsSo(): void {
        $this->configure([
            'domain_reap_deletions' => ['enabled' => false],
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
        ]);

        [$code, $output] = $this->capture();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing due', $output);
    }
}

<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\CronjobSettings;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * The single place every `config *-set` CLI command and
 * `PATCH /v1/cronjobs/{job}` share for validating, persisting and auditing
 * a scheduled job's settings.
 */
final class CronjobSettingsTest extends EppTestCase
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

        Config::loadForTesting(static::SETTINGS + [
            'pdns' => ['enabled' => false, 'path' => null, 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
            'domain_sync' => ['enabled' => false, 'batch_size' => 25, 'cursor_id' => 7, 'frequency_minutes' => 5, 'last_run_at' => null],
            'domain_reap_deletions' => ['enabled' => true, 'frequency_minutes' => 15, 'last_run_at' => null],
            'poll_process' => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
        ]);
    }

    public function testJobsListsEveryRegisteredJob(): void {
        $this->assertSame(
            ['pdns', 'domain_sync', 'domain_reap_deletions', 'poll_process', 'keepalive'],
            CronjobSettings::jobs()
        );
    }

    public function testFieldsListsAJobsOwnFieldsOnly(): void {
        $this->assertSame(['enabled', 'frequency_minutes'], CronjobSettings::fields('poll_process'));
    }

    public function testGetReturnsTheStoredSettings(): void {
        $this->assertSame(3600, CronjobSettings::get('pdns')['ttl']);
    }

    public function testGetWrapsKeepaliveAsAUniformShape(): void {
        $this->assertSame(['enabled' => false], CronjobSettings::get('keepalive'));
    }

    public function testGetRejectsAnUnknownJob(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::get('bogus');
    }

    public function testSetCoercesAStringBoolAndInt(): void {
        $result = CronjobSettings::set('pdns', ['enabled' => 'true', 'ttl' => '7200'], 1);
        $this->assertTrue($result['enabled']);
        $this->assertSame(7200, $result['ttl']);
        $this->assertTrue(Config::get('pdns')['enabled']);
        $this->assertSame(7200, Config::get('pdns')['ttl']);
    }

    public function testSetPreservesFieldsNotBeingChanged(): void {
        CronjobSettings::set('domain_sync', ['batch_size' => 50], 1);
        $stored = Config::get('domain_sync');
        $this->assertSame(50, $stored['batch_size']);
        $this->assertSame(7, $stored['cursor_id'], 'job state was clobbered by an unrelated field change');
    }

    public function testSetWithNullUnsetsAField(): void {
        CronjobSettings::set('pdns', ['path' => '/usr/bin/pdnsutil'], 1, true);
        CronjobSettings::set('pdns', ['path' => null], 1);
        $this->assertNull(Config::get('pdns')['path']);
    }

    public function testSetRejectsAnUnknownField(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('pdns', ['bogus' => 1], 1);
    }

    public function testSetRejectsAnUnknownJob(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('bogus', ['enabled' => true], 1);
    }

    public function testPollProcessEnabledCanBeToggled(): void {
        $result = CronjobSettings::set('poll_process', ['enabled' => false], 1);
        $this->assertFalse($result['enabled']);
        $this->assertFalse(Config::get('poll_process')['enabled']);
    }

    public function testSetRejectsANonExecutablePathWithoutForce(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('pdns', ['path' => '/nonexistent/pdnsutil'], 1);
    }

    public function testForceAcceptsANonExecutablePath(): void {
        $result = CronjobSettings::set('pdns', ['path' => '/nonexistent/pdnsutil'], 1, true);
        $this->assertSame('/nonexistent/pdnsutil', $result['path']);
    }

    public function testSetRejectsAFrequencyOutOfRange(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('domain_sync', ['frequency_minutes' => 1441], 1);
    }

    public function testPreviewDoesNotWrite(): void {
        [, $preview] = CronjobSettings::preview('pdns', ['ttl' => '7200']);
        $this->assertSame(7200, $preview['ttl']);
        $this->assertSame(3600, Config::get('pdns')['ttl'], 'preview() wrote a change');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testSetRecordsOneHistoryRowPerChange(): void {
        CronjobSettings::set('pdns', ['ttl' => '7200'], 42);

        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertNotEmpty($row);
        $this->assertSame('42', (string) $row['user_id']);
        $this->assertSame('update', $row['action']);
        $this->assertStringContainsString('pdns', $row['data']);
        $this->assertStringContainsString('7200', $row['data']);
    }

    public function testKeepaliveSetGoesThroughTheSameHistoryTrail(): void {
        CronjobSettings::set('keepalive', ['enabled' => true], 1);
        $this->assertTrue(Config::get('keepalive'));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testMarkRunWritesLastRunAtOnly(): void {
        CronjobSettings::markRun('domain_reap_deletions');

        $stored = Config::get('domain_reap_deletions');
        $this->assertNotNull($stored['last_run_at']);
        $this->assertTrue($stored['enabled'], 'markRun() touched an unrelated field');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'), 'bookkeeping must not be audited');
    }

    public function testMarkRunOnKeepaliveIsANoOp(): void {
        CronjobSettings::markRun('keepalive');
        $this->assertFalse(Config::get('keepalive'), 'keepalive has no last_run_at of its own');
    }
}

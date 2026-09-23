<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\EppSettings;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * The 7 plain `epp.*` fields -- the single place `config epp-set`/`config
 * epp-server` and `PATCH /v1/session/epp` share for validating, persisting
 * and auditing a change.
 */
final class EppSettingsTest extends EppTestCase
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

        Config::loadForTesting(static::SETTINGS);
    }

    public function testFieldsListsAllSevenInOrder(): void {
        $this->assertSame(
            ['server', 'server_deleted', 'port', 'interface', 'username', 'lang', 'cl_trid_prefix'],
            EppSettings::fields()
        );
    }

    public function testGetReturnsTheStoredValues(): void {
        $this->assertSame('https://epp.pubtest.nic.it', EppSettings::get()['server']);
        $this->assertNull(EppSettings::get()['port']);
    }

    public function testGetNeverIncludesThePassword(): void {
        $this->assertArrayNotHasKey('password', EppSettings::get());
    }

    public function testSetValidatesThroughTheSharedEppFieldRules(): void {
        $this->expectException(\InvalidArgumentException::class);
        EppSettings::set(['username' => 'TOO-SHORT'], 1);
    }

    public function testSetRejectsAnUnknownField(): void {
        $this->expectException(\InvalidArgumentException::class);
        EppSettings::set(['password' => 'whatever'], 1);
    }

    public function testSetPreservesFieldsNotBeingChanged(): void {
        EppSettings::set(['lang' => 'it'], 1);
        $this->assertSame('TEST-REG', Config::get('epp')['username'], 'an unrelated field was clobbered');
        $this->assertSame('test-password', Config::get('epp')['password'], 'password must survive a plain-field change');
    }

    public function testSetCoercesPortToAnInteger(): void {
        $result = EppSettings::set(['port' => '8443'], 1);
        $this->assertSame(8443, $result['port']);
        $this->assertSame(8443, Config::get('epp')['port']);
    }

    public function testSetRejectsAPortOutOfRange(): void {
        $this->expectException(\InvalidArgumentException::class);
        EppSettings::set(['port' => '70000'], 1);
    }

    public function testSetRejectsAnHttpServer(): void {
        $this->expectException(\InvalidArgumentException::class);
        EppSettings::set(['server' => 'http://epp.nic.it'], 1);
    }

    public function testUnsettingAnOptionalFieldWorks(): void {
        EppSettings::set(['port' => '8443'], 1);
        EppSettings::set(['port' => null], 1);
        $this->assertNull(Config::get('epp')['port']);
    }

    public function testUnsettingARequiredFieldIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        EppSettings::set(['server' => null], 1);
    }

    public function testUnsettingUsernameIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        EppSettings::set(['username' => ''], 1);
    }

    public function testSetRecordsOneHistoryRowPerCall(): void {
        EppSettings::set(['lang' => 'it', 'cl_trid_prefix' => 'ACME'], 42);

        $row = R::getRow("SELECT * FROM history WHERE object = 'epp'");
        $this->assertNotEmpty($row);
        $this->assertSame('42', (string) $row['user_id']);
        $this->assertSame('update', $row['action']);
        $this->assertStringContainsString('lang', $row['data']);
        $this->assertStringContainsString('cl_trid_prefix', $row['data']);
    }

    public function testPreviewDoesNotWrite(): void {
        [, $preview] = EppSettings::preview(['lang' => 'it']);
        $this->assertSame('it', $preview['lang']);
        $this->assertSame('en', Config::get('epp')['lang'], 'preview() wrote a change');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }
}

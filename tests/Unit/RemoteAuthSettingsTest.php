<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\RemoteAuthSettings;
use Eppitnic\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RedBeanPHP\R;

/**
 * The `remote_auth` setting -- the single place `config remote-auth-set`
 * validates, persists and audits a change (see ConfigRemoteAuthSetCommandTest
 * for that CLI shape, and AuthRemoteTest for how Api\Auth consumes it).
 */
final class RemoteAuthSettingsTest extends EppTestCase
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
            'remote_auth' => ['enabled' => false, 'header' => null],
        ]);
    }

    public function testFieldsListsBothFieldsInOrder(): void {
        $this->assertSame(['enabled', 'header'], RemoteAuthSettings::fields());
    }

    public function testGetReturnsTheStoredValues(): void {
        $this->assertFalse(RemoteAuthSettings::get()['enabled']);
        $this->assertNull(RemoteAuthSettings::get()['header']);
    }

    public function testSetRejectsAnUnknownField(): void {
        $this->expectException(\InvalidArgumentException::class);
        RemoteAuthSettings::set(['bogus' => 1], 1);
    }

    public function testEnabledAcceptsTruthyStrings(): void {
        $result = RemoteAuthSettings::set(['enabled' => 'yes'], 1);
        $this->assertTrue($result['enabled']);
    }

    public function testEnabledRejectsAGarbageValue(): void {
        $this->expectException(\InvalidArgumentException::class);
        RemoteAuthSettings::set(['enabled' => 'maybe'], 1);
    }

    public function testEnabledCannotBeUnset(): void {
        $this->expectException(\InvalidArgumentException::class);
        RemoteAuthSettings::set(['enabled' => null], 1);
    }

    public function testHeaderCanBeSet(): void {
        $result = RemoteAuthSettings::set(['header' => 'X-Remote-User'], 1);
        $this->assertSame('X-Remote-User', $result['header']);
    }

    public function testHeaderRejectsAnInvalidName(): void {
        $this->expectException(\InvalidArgumentException::class);
        RemoteAuthSettings::set(['header' => 'Not A Header!'], 1);
    }

    #[DataProvider('forbiddenHeaders')]
    public function testHeaderRejectsAForbiddenName(string $header): void {
        $this->expectException(\InvalidArgumentException::class);
        RemoteAuthSettings::set(['header' => $header], 1);
    }

    /** @return array<int, array{0: string}> */
    public static function forbiddenHeaders(): array {
        return [['Authorization'], ['authorization'], ['Cookie'], ['Proxy-Authorization']];
    }

    public function testHeaderCanBeUnsetBackToServerMode(): void {
        RemoteAuthSettings::set(['header' => 'X-Remote-User'], 1);
        RemoteAuthSettings::set(['header' => null], 1);
        $this->assertNull(Config::get('remote_auth')['header']);
    }

    public function testSetRecordsAHistoryRow(): void {
        RemoteAuthSettings::set(['enabled' => true], 42);

        $row = R::getRow("SELECT * FROM history WHERE object = 'remote_auth'");
        $this->assertNotEmpty($row);
        $this->assertSame('42', (string) $row['user_id']);
        $this->assertSame('update', $row['action']);
        $this->assertStringContainsString('enabled', $row['data']);
    }

    public function testPreviewDoesNotWrite(): void {
        [, $preview] = RemoteAuthSettings::preview(['enabled' => true]);
        $this->assertTrue($preview['enabled']);
        $this->assertFalse(Config::get('remote_auth')['enabled'], 'preview() wrote a change');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }
}

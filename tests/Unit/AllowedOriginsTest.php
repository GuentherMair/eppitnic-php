<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\AllowedOrigins;
use Eppitnic\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RedBeanPHP\R;

final class AllowedOriginsTest extends EppTestCase
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

        Config::loadForTesting(static::SETTINGS + ['allowed_origins' => ['https://epp.example.it']]);
    }

    /** @return array<string, array{0: string, 1: string}> as typed => as a browser sends it */
    public static function canonicalForms(): array {
        return [
            'already canonical'      => ['https://epp.example.it', 'https://epp.example.it'],
            'trailing slash'         => ['https://epp.example.it/', 'https://epp.example.it'],
            'upper case'             => ['HTTPS://EPP.Example.IT', 'https://epp.example.it'],
            'default https port'     => ['https://epp.example.it:443', 'https://epp.example.it'],
            'default http port'      => ['http://127.0.0.1:80', 'http://127.0.0.1'],
            'other port kept'        => ['http://127.0.0.1:8095', 'http://127.0.0.1:8095'],
            'IPv6 host'              => ['http://[::1]:5173', 'http://[::1]:5173'],
            'surrounding whitespace' => [' https://epp.example.it ', 'https://epp.example.it'],
        ];
    }

    #[DataProvider('canonicalForms')]
    public function testCanonicalMatchesTheBrowsersOriginHeader(string $typed, string $expected): void {
        $this->assertSame($expected, AllowedOrigins::canonical($typed));
    }

    /** @return array<string, array{0: string}> */
    public static function notOrigins(): array {
        return [
            'bare host'        => ['epp.example.it'],
            'wildcard'         => ['*'],
            'null origin'      => ['null'],
            'with a path'      => ['https://epp.example.it/app'],
            'with a query'     => ['https://epp.example.it/?x=1'],
            'with a fragment'  => ['https://epp.example.it/#top'],
            'with credentials' => ['https://user:pw@epp.example.it'],
            'other scheme'     => ['ftp://epp.example.it'],
        ];
    }

    #[DataProvider('notOrigins')]
    public function testCanonicalRefusesWhatIsNoOrigin(string $value): void {
        $this->assertNull(AllowedOrigins::canonical($value));
    }

    public function testNormalizeCanonicalizesAndDeduplicates(): void {
        $this->assertSame(
            ['https://epp.example.it', 'http://127.0.0.1:8095'],
            AllowedOrigins::normalize(['https://epp.example.it/', 'http://127.0.0.1:8095', 'HTTPS://epp.example.it:443'])
        );
    }

    public function testNormalizeRejectsANonString(): void {
        $this->expectException(\InvalidArgumentException::class);
        AllowedOrigins::normalize([['https://epp.example.it']]);
    }

    public function testSetReplacesTheListAndRecordsHistory(): void {
        $stored = AllowedOrigins::set(['https://other.example.it/'], 3);

        $this->assertSame(['https://other.example.it'], $stored);
        $this->assertSame(['https://other.example.it'], AllowedOrigins::get());

        $row = R::getRow("SELECT * FROM history WHERE object = 'allowed_origins'");
        $this->assertSame('3', (string) $row['user_id']);
        $this->assertSame(['https://other.example.it'], json_decode($row['data'], true)['allowed_origins']);
    }

    public function testSetAnEmptyListClearsIt(): void {
        $this->assertSame([], AllowedOrigins::set([], 3));
        $this->assertSame([], AllowedOrigins::get());
    }

    public function testAFailedSetWritesNothing(): void {
        try {
            AllowedOrigins::set(['*'], 3);
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(['https://epp.example.it'], AllowedOrigins::get());
        $this->assertEmpty(R::getAll("SELECT * FROM history WHERE object = 'allowed_origins'"));
    }
}

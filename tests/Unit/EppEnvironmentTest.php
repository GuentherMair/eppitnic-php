<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\EppSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which registry epp.server points at -- what the frontend switches its
 * colours by.
 */
final class EppEnvironmentTest extends TestCase
{
    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function servers(): array {
        return [
            'production'            => ['https://epp.nic.it', 'production'],
            'public test registry'  => ['https://epp.pubtest.nic.it', 'test'],
            'trailing slash, upper' => ['https://EPP.PUBTEST.NIC.IT/', 'test'],
            'anything else'         => ['https://epp.example.it', 'custom'],
            'not set'               => [null, 'custom'],
        ];
    }

    #[DataProvider('servers')]
    public function testTellsTheRegistriesApart(?string $server, string $expected): void {
        Config::loadForTesting($server === null ? [] : ['epp' => ['server' => $server]]);

        $this->assertSame($expected, EppSettings::environment());
    }
}

<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Euro amounts formatted per region.lc_monetary, through ICU rather than the
 * C library's locale data (which musl, in the Docker image, doesn't have).
 */
final class MoneyTest extends TestCase
{
    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: float, 2: string}> */
    public static function amounts(): array {
        return [
            'Italian, with a charset'  => ['it_IT.UTF-8', 2473.04, '2.473,04 €'],
            'German, with a modifier'  => ['de_DE@euro', 2473.04, '2.473,04 €'],
            'American grouping'        => ['en_US', 2473.04, '2,473.04 €'],
            'two decimals, always'     => ['it_IT', 1000.0, '1.000,00 €'],
            'rounded to the cent'      => ['it_IT', 0.125, '0,13 €'],
            'negative'                 => ['it_IT', -12.5, '-12,50 €'],
            'C locale: no grouping'    => ['C', 2473.04, '2473.04 €'],
        ];
    }

    #[DataProvider('amounts')]
    public function testFormatsInTheConfiguredLocale(string $locale, float $amount, string $expected): void {
        Config::loadForTesting(['region' => ['timezone' => 'Europe/Rome', 'lc_monetary' => $locale]]);

        $this->assertSame($expected, Money::euro($amount));
    }
}

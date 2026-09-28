<?php

namespace Eppitnic\Support;

use Eppitnic\Config;

/**
 * Amounts as people read them: digits grouped and separated the way the
 * `region.lc_monetary` locale does, always in euros -- the registry bills
 * nothing else. ICU (ext-intl) does the grouping; musl has no locale data.
 *
 * @category    Net
 * @package     Eppitnic\Support\Money
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Money
{
    /** e.g. 2473.04 -> "2.473,04 €" under it_IT */
    public static function euro(float $amount): string {
        $formatter = new \NumberFormatter(self::locale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
        // half-up, as number_format() and people round; ICU defaults to half-even
        $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP);

        $digits = $formatter->format($amount);
        return ($digits === false ? number_format($amount, 2, '.', '') : $digits) . ' €';
    }

    /** "it_IT.UTF-8" or "it_IT@euro" -> "it_IT", the form ICU names locales by */
    private static function locale(): string {
        $locale = (string) (Config::all()['region']['lc_monetary'] ?? '');
        $locale = (string) preg_replace('/[.@].*$/', '', $locale);
        return in_array($locale, ['', 'C', 'POSIX'], true) ? 'en_US_POSIX' : $locale;
    }
}

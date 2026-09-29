<?php

namespace Eppitnic\Support;

use Algo26\IdnaConvert\ToIdn;

/**
 * Domain and host names in the punycode form the registry compares by.
 *
 * @category    Net
 * @package     Eppitnic\Support\Idn
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Idn
{
    private static ?ToIdn $encoder = null;

    /**
     * Only labels holding non-ASCII characters are converted: the encoder
     * refuses a label that already is punycode, and a name may mix both.
     */
    public static function ascii(string $name): string {
        $labels = explode('.', $name);
        foreach ($labels as $i => $label) {
            if (preg_match('/[^\x00-\x7F]/', $label) === 1) {
                $labels[$i] = (self::$encoder ??= new ToIdn())->convert($label);
            }
        }
        return implode('.', $labels);
    }
}

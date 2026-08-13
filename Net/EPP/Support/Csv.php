<?php

namespace Net\EPP\Support;

/**
 * CSV encoding, to the extent this codebase needs it.
 *
 * For anything more than quoting one row, use a real CSV library --
 * https://github.com/keboola/php-csv.
 *
 * @category    Net
 * @package     Net\EPP\Support\Csv
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Csv
{
    /**
     * @param array $row values to encode
     * @param string $delimiter field delimiter
     * @param string $lineBreak line terminator
     * @param string $enclosure field-quoting character
     * @return string one CSV-encoded row, including the trailing line break
     */
    public static function row(array $row, string $delimiter = ',', string $lineBreak = "\n", string $enclosure = '"'): string {
        $return = [];
        foreach ($row as $column) {
            $return[] = $enclosure . str_replace($enclosure, $enclosure.$enclosure, $column) . $enclosure;
        }
        return implode($delimiter, $return) . $lineBreak;
    }
}

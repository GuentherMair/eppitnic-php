<?php

// for a proper CSV handling class see https://github.com/keboola/php-csv

class CSV {
  // BOM as a string for comparison.
  public const bom = "\xef\xbb\xbf";

  public static function jumpBOM(&$fp) {
    // Progress file pointer and get first 3 characters to compare to the BOM string.
    if (fgets($fp, 4) !== self::bom) {
      // BOM not found - rewind pointer to start of file.
      rewind($fp);
    }
  }
  public static function rowToCSV(array $row, $delimiter = ',', $lineBreak = "\n", $enclosure = '"') {
      $return = [];
      foreach ($row as $column)
          $return[] = $enclosure . str_replace($enclosure, $enclosure.$enclosure, $column) . $enclosure;
      return implode($delimiter, $return) . $lineBreak;
  }
}

<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';

// verify command line arguments
if ( $argc < 2 ) {
  echo "SYNTAX: " . $argv[0] . " DNS-NAME\n";
  echo "\n";
  exit(1);
}

// sample extension of the storage driver
class MyStorageWrapper extends Net_EPP_IT_StorageDB
{
  function __construct($cfg) {
    parent::__construct($cfg);
  }

  public function searchDNS($dns) {
    $elements = parent::doRetrieve('tbl_domains', 'active', 1);
    $result = array();
    foreach ($elements as $element)
      if ( in_array($dns, array_keys($element['ns'])) )
        $result[] = $element['domain'];
    return $result;
  }
}

// initialize objects
$nic = new Net_EPP_IT_Client();
$db = new MyStorageWrapper($nic->EPPCfg->adodb);

// now make use of our new method
echo "Performing DB operations:\n";
print_r($db->searchDNS($argv[1]));


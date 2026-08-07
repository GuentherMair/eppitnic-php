<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("u:o:");
$userid = isset($options['u']) ? (int)$options['u'] : 1;

// export goes straight to a local DB report - no EPP session is required
$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$domain = new Net_EPP_IT_Domain($nic, $db);

// get domain info from DB
$csv = $domain->export($userid);

if (isset($options['o'])) {
  if (file_put_contents($options['o'], $csv) === FALSE) {
    echo "[FAILURE] unable to write to '{$options['o']}'\n";
    exit(1);
  }
  echo "[SUCCESS] exported domains for user ID {$userid} to '{$options['o']}'\n";
} else {
  echo $csv;
}

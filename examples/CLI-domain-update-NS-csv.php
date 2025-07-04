<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

if ( $argc < 2 ) {
  echo "SYNTAX: " . $argv[0] . " CSV-FILE\n";
  exit(1);
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
  echo "CSV-FILE ".$arg[1]." not readable\n";
  exit(2);
}

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
      $name = $data[0];
      $ns_add = split(":", $data[1]);
      $ns_remove = split(":", $data[2]);

      // recreate domain object
      $domain = new Net_EPP_IT_Domain($nic, $db);
      $domain->debug = LOG_DEBUG;

      // load domain object
      $domain->fetch($name);

      // add NS records
      foreach ($ns_add as $single_ns) {
        if ( ! empty($single_ns)) {
          echo "Adding NS: ".$single_ns."\n";
          $domain->addNS($single_ns);
        }
      }

      // remove NS records
      foreach ($ns_remove as $single_ns) {
        if ( ! empty($single_ns)) {
          echo "Removing NS: ".$single_ns."\n";
          $domain->remNS($single_ns);
        }
      }

      // update domain
      if ($domain->update())
        echo "Domain '".$name."' is now up to date.\n";
      else
        echo "Update to domain '".$name."' FAILED (".$domain->getError().")!\n";
    }

    // logout
    if ($session->logout())
      echo "Logout OK.\n";
    else
      echo "Logout FAILED (".$session->getError().").\n";
  }
}

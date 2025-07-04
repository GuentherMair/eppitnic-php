<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

/*
 * dump domain data
 */
function dump_domain($domain) {
  echo " - Registrant: " . $domain->get('registrant') . "\n";
  echo " - Admin-C: " . $domain->get('admin') . "\n";
  $tech = $domain->get('tech');
  if ( ! is_array($tech)) {
    echo " - Tech-C: " . $tech . "\n";
  } else foreach ($tech as $single_tech) {
    echo " - Tech-C: " . $single_tech . "\n";
  }
  $state = $domain->get('status');
  foreach ($state as $s)
    echo " - state '" . $s . "'\n";
  $ns = $domain->get('ns');
  foreach ($ns as $name)
    echo " - NS: " . $name['name'] . "\n";
}

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  echo "Greeting OK.\n";

  // perform login
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    // lookup domain
    $name = "testABC12345.it";
    $domain->set('domain', $name);

    dump_domain($domain);

    if ($domain->restore($name))
      echo "Restore domain '{$name}' succeeded.\n";
    else
      echo "Restore domain '{$name}' FAILED (".$domain->getError().")!\n";

    dump_domain($domain);

    // logout
    if ($session->logout()) {
      echo "Logout OK.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}

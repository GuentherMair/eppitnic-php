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

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ($session->login() === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

// lookup domain
$name = "testABC123456.it";
if ($domain->loadDB($name)) {
  echo "Domain {$name} found in DB. Processing update...\n";
  $domain->set('authinfo', $domain->authinfo());
  $domain->addDNSSEC(12345, 3, 1, '49FD46E6C4B45C55D4AC');
  $domain->addDNSSEC(9876, 8, 3, '59FD46E6C4B45C55D4AC');
  if ($domain->updateDB($name)) {
    echo "Database updated successfully.\n";
  } else {
    echo "Failed to to update database!!\n";
  }
} else {
  echo "Domain {$name} not found in DB. Fetching...\n";
  if ($domain->fetch($name)) {
    echo "Domain {$name} found.\n";
    $domain->addDNSSEC(12345, 3, 1, '49FD46E6C4B45C55D4AC');
    $domain->addDNSSEC(9876, 8, 3, '59FD46E6C4B45C55D4AC');
    if ($domain->storeDB($name)) {
      echo "Domain stored in DB. Run again to handle an update!\n";
    } else {
      echo "Failed to store Domain in DB!!\n";
    }
  } else {
    echo "Domain {$name} not found! Aborting...\n";
  }
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


<?php

use Net\EPP\Client;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = true;
$domain = new Domain($nic);
$domain->debug = true;

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
    if ($domain->storeDB()) {
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


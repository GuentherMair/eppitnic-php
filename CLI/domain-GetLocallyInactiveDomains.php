<?php

use Net\EPP\Client;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

use RedBeanPHP\R;

$nic = new Client();
$session = new Session($nic);
$domain = new Domain($nic);

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

try {
  // list in-active domains
  $rows = R::getAll("SELECT domain FROM domains WHERE active = 0");
  foreach ($rows as $row) {
    if ($domain->check($row['domain']) !== TRUE) {
      if ($domain->fetch($row['domain'])) {
        echo "Domain '{$row['domain']}' still exists and should be removed.\n";
        $state = $domain->get('status');
        foreach ($state as $s)
          echo " - state '{$s}'\n";
      }
    }
  }
} catch (\RedBeanPHP\RedException\SQL $e) {
  echo "[FAILURE] A database error occured: " . $e->getMessage() . "\n";
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

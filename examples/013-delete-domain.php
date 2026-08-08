<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic);
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
switch ($domain->check($name)) {
  case TRUE:
    echo "Domain '{$name}' is still available, sorry!\n";
    break;
  case FALSE:
    echo "Domain '{$name}' not available, trying to delete...";
    if ($domain->delete($name)) {
      echo " OK.\n";
    } else {
      echo " FAILED (".$domain->getError().").\n";
    }
    break;
  default:
    echo "Error checking '{$name}'.\n";
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


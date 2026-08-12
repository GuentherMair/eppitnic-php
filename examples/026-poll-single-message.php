<?php

use Net\EPP\Client;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = true;

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

// poll message queue
switch ($session->pollMessageCount()) {
  case 0:
    echo "There are no messages in the polling queue.\n";
    break;
  default:
    echo "There are ".$session->pollMessageCount()." messages in the polling queue.\n";
    if ($session->poll(TRUE, "req")) {
      echo "Successfully got and stored message n. " . $session->pollMessageCount() . "!\n";
      $session->poll(TRUE, "ack", $session->pollID());
    } else {
      echo "FAILED to get message n. " . $session->pollMessageCount() . ": ".$session->getError()."\n";
    }
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


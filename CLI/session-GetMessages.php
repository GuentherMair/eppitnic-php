<?php

use Net\EPP\Client;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);

// send "hello"
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

switch ($session->pollMessageCount()) {
  case 0:
    echo "There are no messages in the polling queue.\n";
    break;
  default:
    echo "There are ".$session->pollMessageCount()." messages in the polling queue, polling...\n";
    break;
}

while ($session->pollMessageCount() > 0) {
  // as for now - dump debug information
  $session->poll(TRUE, "req", $session->pollID());
  //print_r($session->result['body']);

  if ($session->poll(FALSE, "ack", $session->pollID())) {
    echo "[SUCCESS] Got message n. " . $session->pollMessageCount() . ":\n";
  } else {
    echo "[FAILURE] Unable to get message n. " . $session->pollMessageCount() . " (".$session->getError().").\n";
  }
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

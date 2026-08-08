<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
$session->debug = LOG_DEBUG;

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
    break;
}
while ($session->pollMessageCount() > 0) {
  if ($session->poll(TRUE, "req")) {
    echo "Successfully got and stored message n. " . $session->pollMessageCount() . "!\n";
    $session->poll(TRUE, "ack", $session->pollID());
  } else {
    echo "FAILED to get message n. " . $session->pollMessageCount() . ": ".$session->getError()."\n";
  }
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


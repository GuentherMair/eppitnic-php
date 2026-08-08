<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ( $session->login() === FALSE ) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

// poll message queue
switch ( $session->pollMessageCount() ) {
  case 0:
    echo "There are no messages in the polling queue.\n";
    break;
  default:
    echo "There are ".$session->pollMessageCount()." messages in the polling queue.\n";
    echo "Polling...\n";
    break;
}
while ( $session->pollMessageCount() > 0 ) {

  // as for now - dump debug information
  $session->poll(TRUE, "req", $session->pollID());
  print_r($session->result[body]);

  if ( $session->poll(FALSE, "ack", $session->pollID()) )
    echo "Successfully got message n. " . $session->pollMessageCount() . ":\n";
  else
    echo "FAILED to get message n. " . $session->pollMessageCount() . " (".$session->getError().").\n";
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";



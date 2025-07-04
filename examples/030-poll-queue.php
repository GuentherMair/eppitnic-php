<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';

$nic = new Net_EPP_IT_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  echo "Greeting OK.\n";

  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    // poll message queue
    switch ( $session->pollMessageCount() ) {
      case 0:
        echo "There are no messages in the polling queue.\n";
        break;
      default:
        echo "There are ".$session->pollMessageCount()." messages in the polling queue.\n";
        break;
    }
    while ( $session->pollMessageCount() > 0 ) {
      if ( $session->poll(TRUE, "req") ) {
        echo "Successfully got and stored message n. " . $session->pollMessageCount() . "!\n";
        $session->poll(TRUE, "ack", $session->pollID());
      } else {
        echo "FAILED to get message n. " . $session->pollMessageCount() . ": ".$session->getError()."\n";
      }
    }

    // logout
    if ( $session->logout() ) {
      echo "Logout OK.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}


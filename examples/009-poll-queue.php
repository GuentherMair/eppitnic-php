<?php

echo "This example is obsolete (thanks again Marco for the hint)!\n";
echo "Please use either of:\n";
echo "\n";
echo " 1) examples/029-poll-single-message.php\n";
echo " 2) examples/030-poll-queue.php\n";
echo "\n";
exit;

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';

$nic = new Net_EPP_Client();
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
        echo "Polling...\n";
        break;
    }
    while ( $session->pollMessageCount() > 0 ) {
      print_r($session->result[body]);
      switch ( $session->poll(TRUE, "ack", $session->pollID()) ) {
        case TRUE:
          echo "Successfully got message n. " . $session->pollMessageCount() . ":\n";
          break;
        case FALSE;
          echo "FAILED to get message n. " . $session->pollMessageCount() . ": ".$session->getError()."\n";
          break;
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


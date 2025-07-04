<?php

set_include_path('.:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';

$nic = new Net_EPP_IT_Client("config.xml");
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
    echo "Login FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  } else {
    echo "Login OK (code ".$session->svCode.", '".$session->svMsg."').\n";

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
      switch ( $session->poll(TRUE, "ack", $session->pollID()) ) {
        case TRUE:
          echo "Successfully got message n. " . $session->pollMessageCount() . ":\n";
          break;
        case FALSE;
          echo "FAILED to get message n. " . $session->pollMessageCount() . ":\n";
          echo "Result code ".$session->svCode.", '".$session->svMsg."'.\n";
          break;
      }
      print_r($session->result[body]);
    }

    // logout
    if ( $session->logout() ) {
      echo "Logout OK (code ".$session->svCode.", '".$session->svMsg."').\n";
    } else {
      echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}

?>

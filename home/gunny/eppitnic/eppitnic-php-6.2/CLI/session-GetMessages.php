<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
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

    // close session
    if ($session->logout()) {
      echo "Your remaining credit: {$session} EUR.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }
  }
}

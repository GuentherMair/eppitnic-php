<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$contact = new Net_EPP_IT_Contact($nic, $db);

if ($argc < 2) {
  echo "SYNTAX: {$argv[0]} CONTACT\n";
  exit(1);
}

$name = $argv[1];

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    switch ($contact->check($name)) {
      case TRUE:
        echo "Contact '{$name}' is available.\n";
        break;
      case FALSE:
        if ($contact->delete($name)) {
          echo "[SUCCESS] Contact '{$name}' removed.\n";
        } else {
          echo "[FAILURE] Delete contact '{$name}' failed (".$contact->getError().").\n";
        }
        break;
      default:
        echo "Error checking '{$name}' (".$contact->getError().").\n";
        break;
    }

    // close session
    if ($session->logout()) {
      echo "Your remaining credit: {$session} EUR.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }
  }
}

<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  echo "Greeting OK.\n";

  // perform login
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    // test check contact (single)
    echo "Starting single contact lookup...\n";
    $name = "GM00001";
    switch ($contact->check($name)) {
      case TRUE:
        echo "Contact '{$name}' is free.\n";
        break;
      case FALSE:
        echo "Contact '{$name}' already in use.\n";
        break;
      default:
        echo "Error: '{$name}' (".$contact->getError().").\n";
        break;
    }

    // test check contact (bulk)
    echo "Starting bulk contact lookup... (remember: there is a maximum of 5 contacts that can be checked)\n";
    $names = array("GM00001", "GM00002", "XY00001", "XY00002", "XY00003", "XY00004-notchecked", "XY00005-notchecked");
    $result = $contact->check($names);
    if (is_array($result)) {
      foreach ($result as $name => $value) {
        switch ($value) {
          case TRUE:
            echo "Contact '{$name}' is free.\n";
            break;
          case FALSE:
            echo "Contact '{$name}' already in use.\n";
            break;
        }
      }
    } else {
      switch ($result) {
        case TRUE:
          echo "Contact is free.\n";
          break;
        case FALSE:
          echo "Contact already in use.\n";
          break;
        default:
          echo "Error looking up contact (".$contact->getError().").\n";
          break;
      }
    }

    // logout
    if ($session->logout()) {
      echo "Logout OK.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}

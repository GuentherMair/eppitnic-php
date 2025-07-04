<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_IT_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;

if ( $argc < 2 ) {
  echo "SYNTAX: " . $argv[0] . " CONTACT\n";
  exit(1);
}

$name = $argv[1];

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

    // test check contact
    switch ( $contact->check($name) ) {
      case TRUE:
        echo "Contact '".$name."' is available.\n";
        break;
      case FALSE:
        echo "Contact '".$name."' exists:\n";
        if ( $contact->fetch($name) ) {
          $status = $contact->get('status');
          foreach ( $status as $state )
            echo " - status '" . $state . "'\n";
          echo " - name '" . $contact->get('name') . "'\n";
          echo " - street '" . $contact->get('street') . "'\n";
          echo " - city '" . $contact->get('city') . "'\n";
          echo " - consent for publishing '" . $contact->get('consentforpublishing') . "'\n";
        } else {
          echo "Fetch contact FAILED (".$contact->getError().").\n";
        }
        break;
      default:
        echo "Error: '".$name."' (".$contact->getError().").\n";
        break;
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


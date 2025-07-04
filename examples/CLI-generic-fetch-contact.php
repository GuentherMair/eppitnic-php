<?php

set_include_path('.:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_IT_Client("config.xml");
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
    echo "Login FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  } else {
    echo "Login OK (code ".$session->svCode.", '".$session->svMsg."').\n";

    // test check contact
    switch ( $contact->check($name) ) {
      case TRUE:
        echo "Contact '".$name."' is available.\n";
        echo "Result code ".$contact->svCode.", '".$contact->svMsg."'.\n";
        break;
      case FALSE:
        echo "Contact '".$name."' exists:\n";
        if ( $contact->fetch($name) ) {
          echo " - name '" . $contact->get('name') . "'\n";
          echo " - street '" . $contact->get('street') . "'\n";
          echo " - city '" . $contact->get('city') . "'\n";
          echo " - consent for publishing '" . $contact->get('consentforpublishing') . "'\n";
        } else {
          echo "Error: code ".$contact->svCode.", '".$contact->svMsg."'.\n";
        }
        break;
      default:
        echo "Error: '".$name."'.\n";
        break;
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

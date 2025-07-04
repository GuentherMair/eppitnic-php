<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;

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
    $name = "GM00002";
    switch ( $contact->check($name) ) {
      case TRUE:
        echo "Contact '".$name."' is available.\n";
        $contact->set('handle', $name);
        $contact->set('name', 'Guenther Mair');
        $contact->set('street', 'via Andriano 7/G');
        $contact->set('city', 'Bolzano');
        $contact->set('province', 'BZ');
        $contact->set('postalcode', '39010');
        $contact->set('countrycode', 'IT');
        $contact->set('voice', '+39.3486914569');
        $contact->set('email', 'guenther.mair@hoslo.ch');
        $contact->set('authinfo', 'ABC1234567');
        $contact->set('nationalitycode', 'IT');
        $contact->set('entitytype', '1');
        $contact->set('regcode', 'MRAGTH78P24F132L');
        if ( $contact->create() ) {
          echo "Create contact '".$contact->get('handle')."' created.\n";
        } else {
          echo "Create contact '".$contact->get('handle')."' failed (".$contact->getError().").\n";
        }
        break;
      case FALSE:
        echo "Contact '".$name."' already in use:\n";
        unset($contact);
        $contact = new Net_EPP_IT_Contact($nic, $db);
        $contact->debug = LOG_DEBUG;
        $contact->set('name', "XYZ");
        if ( $contact->fetch($name) ) {
          echo " - name '" . $contact->get('name') . "'\n";
          echo " - street '" . $contact->get('street') . "'\n";
          echo " - city '" . $contact->get('city') . "'\n";
        } else {
          echo "Error: '".$name."' (".$contact->getError().").\n";
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


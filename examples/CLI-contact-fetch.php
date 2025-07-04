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
          // get values
          $values = array();
          $values['name'] = $contact->get('name');
          $values['street'] = $contact->get('street');
          $values['city'] = $contact->get('city');
          $values['province'] = $contact->get('province');
          $values['postalcode'] = $contact->get('postalcode');
          $values['voice'] = $contact->get('voice');
          $values['fax'] = $contact->get('fax');
          $values['email'] = $contact->get('email');
          $values['authinfo'] = $contact->get('authinfo');
          $values['nationalitycode'] = $contact->get('nationalitycode');
          $values['entitytype'] = $contact->get('entitytype');
          $values['regcode'] = $contact->get('regcode');
          $values['consentforpublishing'] = $contact->get('consentforpublishing');

          // print states
          $status = $contact->get('status');
          echo " - states '".implode("' / '", $status)."'\n";

          // print remaining values
          foreach ( $values as $key => $value )
            if ( !empty($value) ) echo " - ".$key." '" . $value . "'\n";
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


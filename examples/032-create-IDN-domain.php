<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;


/*
 * the real things starts here
 */

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

    // some details
    $name = "test-domain-è.it";
    $handle = "IDN-TEST-0001";
    $dns1 = "dns1.inet-services.it";
    $dns2 = "dns2.inet-services.it";

    // create contact
    if ( $contact->check($handle) ) {
      echo "Creating contact '".$handle."'...\n";
      $contact->set('handle', $handle);
      $contact->set('name', 'Guenther Mair');
      $contact->set('org', 'Guenther Mair');
      $contact->set('street', 'via Andriano 7/G');
      $contact->set('city', 'Bolzano');
      $contact->set('province', 'BZ');
      $contact->set('postalcode', '39010');
      $contact->set('countrycode', 'IT');
      $contact->set('voice', '+39.3486914569');
      $contact->set('email', 'guenther.mair@hoslo.ch');
      $contact->set('authinfo', 'ABC1234567');
      $contact->set('nationalitycode', 'IT');
      $contact->set('entitytype', 2);
      $contact->set('regcode', '02509280216');
      if ( $contact->create() ) {
        echo "Create contact '".$contact->get('handle')."' created.\n";
        return TRUE;
      } else {
        echo "Create contact '".$contact->get('handle')."' FAILED (".$contact->getError().").\n";
        return FALSE;
      }
    }

    // create domain
    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' is available.\n";
        $domain->set('domain', $name);
        $domain->set('registrant', $handle);
        $domain->set('admin', $handle);
        $domain->set('tech', $handle);
        $domain->set('ns', $dns1);
        $domain->set('ns', $dns2);
        if ( $domain->create() ) {
          echo "Domain '".$name."' created.\n";
        } else {
          echo "Domain '".$name."' NOT created (".$domain->getError().").\n";
        }
        break;
      case FALSE:
        echo "Domain '".$name."' is NOT available.\n";
        break;
      default:
        echo "Error: '".$name."' (".$domain->getError().").\n";
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


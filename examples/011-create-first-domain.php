<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_IT_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

/*
 * we will require 3 contacts at least for this script!
 */
function check_or_create($handle, $registrant = FALSE) {
  global $nic, $db;

  $contact = new Net_EPP_IT_Contact($nic, $db);
  $contact->debug = LOG_DEBUG;

  if ( ! $contact->check($handle) ) {
    $contact->delete($handle);
  }
  echo "Creating contact '".$handle."'...\n";
  $contact->set('handle', $handle);
  $contact->set('name', 'Guenther Mair');
  $contact->set('street', 'via Andriano 7/G');
  $contact->set('city', 'Bolzano');
  $contact->set('province', 'BZ');
  $contact->set('postalcode', '39010');
  $contact->set('countrycode', 'IT');
  $contact->set('voice', '+39.3486914569');
  $contact->set('email', 'guenther.mair@hoslo.ch');
  $contact->set('authinfo', 'ABC1234567');
  if ( $registrant ) {
    $contact->set('nationalitycode', 'IT');
    $contact->set('entitytype', 2);
    $contact->set('regcode', '02509280216');
  } else {
    $contact->set('entitytype', 0);
  }
  if ( $contact->create() ) {
    echo "Create contact '".$contact->get('handle')."' created.\n";
    return TRUE;
  } else {
    echo "Create contact '".$contact->get('handle')."' FAILED (".$contact->getError().").\n";
    return FALSE;
  }
  return TRUE;
}


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
    $name = "test1234567890.it";
    $registrant = "GM0001";
    $admin = "GM0002";
    $tech = "GM0003";
    $dns1 = "dns1.inet-services.it";
    $dns2 = "dns2.inet-services.it";

    if ( ! check_or_create($registrant, TRUE) ) {
      echo "Unable to create '".$registrant."'!\n";
      $session->logout();
      exit;
    }
    if ( ! check_or_create($admin) ) {
      echo "Unable to create '".$admin."'!\n";
      $session->logout();
      exit;
    }
    if ( ! check_or_create($tech) ) {
      echo "Unable to create '".$tech."'!\n";
      $session->logout();
      exit;
    }

    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' is available.\n";
        $domain->set('domain', $name);
        $domain->set('registrant', $registrant);
        $domain->set('admin', $admin);
        $domain->set('tech', $tech);
        $domain->set('ns', $dns1);
        $domain->set('ns', $dns2);
        /*
        $domain->addNS('dns3.inet-services.it', array('::1', '172.16.1.1'));
        print_r( $domain->get('ns') );
        */
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


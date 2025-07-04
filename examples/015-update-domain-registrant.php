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
 * dump domain data
 */
function dump_domain($domain) {
  if ( $domain->fetch($name) ) {
    echo " - Registrant: " . $domain->get('registrant') . "\n";
    echo " - Admin-C: " . $domain->get('admin') . "\n";
    echo " - Tech-C: " . $domain->get('tech') . "\n";
    $state = $domain->get('status');
    foreach ( $state as $s )
      echo " - state '" . $s . "'\n";
    $ns = $domain->get('ns');
    foreach ($ns as $name) {
      echo " - NS: " . $name['name'] . "\n";
    }
  } else {
    echo "Fetch domain ".$name." FAILED (".$domain->getError().")!\n";
  }
}

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
    $name = "domain-update-test-0001.it";
    $registrant_new = "GM1000";

    check_or_create($registrant_new, TRUE);

    switch ( $domain->fetch($name) ) {
      case TRUE:
        echo "OLD STATUS:\n";
        dump_domain($domain);
        // update domain
        $domain->set('registrant', $registrant_new);
        $domain->set('authinfo', $domain->authinfo());
        if ( $domain->updateRegistrant() )
          echo "Domain '".$name."' is now up to date.\n";
        else
          echo "Update to domain '".$name."' FAILED (".$domain->getError().")!\n";
        echo "NEW STATUS:\n";
        dump_domain($domain);
        break;
      case FALSE:
        echo "Domain '".$name."' should already be available!\n";
        echo "Please run the update example '014' first!\n";
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


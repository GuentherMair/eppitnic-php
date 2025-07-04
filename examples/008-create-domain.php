<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
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

  if ($contact->check($handle) === TRUE)
    return TRUE;

  echo "Creating contact '{$handle}'...\n";
  $contact->set('handle', $handle);
  $contact->set('name', 'Guenther Mair');
  $contact->set('org', 'Guenther Mair');
  $contact->set('street', 'via 123/B');
  $contact->set('city', 'Bolzano');
  $contact->set('province', 'BZ');
  $contact->set('postalcode', '39100');
  $contact->set('countrycode', 'IT');
  $contact->set('voice', '+39.3480123456');
  $contact->set('email', 'info@inet-services.it');
  $contact->set('authinfo', 'ABC1234567');
  if ($registrant) {
    $contact->set('nationalitycode', 'IT');
    $contact->set('entitytype', 2);
    $contact->set('regcode', '02509280216');
  } else {
    $contact->set('entitytype', 0);
  }

  if ($contact->create()) {
    echo "Create contact '".$contact->get('handle')."' created.\n";
    return TRUE;
  } else {
    echo "Create contact '".$contact->get('handle')."' FAILED (".$contact->getError().").\n";
    return FALSE;
  }
}


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

    // some details
    $name = "testABC123456.it";
    $registrant = "GM0004";
    $admin = "GM0005";
    $tech = "GM0006";
    $dns = array(
      "dns1.inet-services.it",
      "dns2.inet-services.it",
      "dns3.inet-services.it",
    );

    if ( ! check_or_create($registrant, TRUE)) {
      echo "Unable to create '".$registrant."'!\n";
      $session->logout();
      exit;
    }
    if ( ! check_or_create($admin)) {
      echo "Unable to create '".$admin."'!\n";
      $session->logout();
      exit;
    }
    if ( ! check_or_create($tech)) {
      echo "Unable to create '".$tech."'!\n";
      $session->logout();
      exit;
    }

    switch ($domain->check($name)) {
      case TRUE:
        echo "Domain '{$name}' is available.\n";
        $domain->set('domain', $name);
        $domain->set('registrant', $registrant);
        $domain->set('admin', $admin);
        $domain->set('tech', $tech);
        foreach ($dns as $single_dns)
          $domain->set('ns', $single_dns);
        $domain->set('authinfo', substr(rand(), 0, 32));
        if ($domain->create()) {
          echo "Domain '{$name}' created.\n";
        } else {
          echo "Domain '{$name}' NOT created (".$domain->getError().").\n";
        }
        break;
      case FALSE:
        echo "Domain '{$name}' is NOT available.\n";
        break;
      default:
        echo "Error checking '{$name}' (".$domain->getError().").\n";
        break;
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

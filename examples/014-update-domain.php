<?php

set_include_path('.:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_IT_Client("config.xml");
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
    echo "Create contact '".$contact->get('handle')."' FAILED.\n";
    echo "Reason code ".$contact->svCode.", '".$contact->svMsg."'.\n";
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
    echo "Login FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  } else {
    echo "Login OK (code ".$session->svCode.", '".$session->svMsg."').\n";

    // some details
    $name = "domain-update-test-0001.it";
    $registrant = "GM0001";
    $admin = "GM0002";
    $tech = "GM0003";
    $tech_new = "GM0004";
    $dns1 = "dns1.inet-services.it";
    $dns2 = "dns2.inet-services.it";

    check_or_create($registrant, TRUE);
    check_or_create($admin);
    check_or_create($tech);
    check_or_create($tech_new);

    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' is available.\n";
        $domain->set('domain', $name);
        $domain->set('registrant', $registrant);
        $domain->set('admin', $admin);
        $domain->set('tech', $tech);
        $domain->set('ns', $dns1);
        $domain->set('ns', $dns2);
        if ( $domain->create() ) {
          echo "Domain '".$name."' created.\n";
          echo "Let us wait some time for the domain to become available.\n";
          echo "Sleeping 15 seconds";
          for ($i = 0; $i < 15; $i++) {
            echo ".";
            sleep(1);
          }
          echo "\n";
        } else {
          echo "Domain '".$name."' NOT created.\n";
        }
        echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";
        break;
      case FALSE:
        echo "Domain '".$name."' is NOT available.\n";
        echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";
        break;
      default:
        echo "Error: '".$name."'.\n";
        exit;
        break;
    }

    // destroy domain object
    unset($domain);

    // recreate domain object
    $domain = new Net_EPP_IT_Domain($nic, $db);
    $domain->debug = LOG_DEBUG;

    // load domain object
    $domain->fetch($name);

    // update domain
    $domain->set('tech', $tech_new);
    switch ( $domain->update() ) {
      case TRUE:
        echo "Domain '".$name."' is now up to date.\n";
        break;
      case FALSE:
        echo "Update to domain '".$name."' FAILED!.\n";
        echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";
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

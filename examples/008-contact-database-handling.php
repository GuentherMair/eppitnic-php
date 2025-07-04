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
    $name = "GM00004";
    switch ( $contact->check($name) ) {
      case TRUE:
        echo "Contact '".$name."' is available on EPP.\n";
        if ( $contact->loadDB($name) ) {
          echo "Contact '".$name."' found in DB.\n";
        } else {
          echo "Contact '".$name."' not found in DB. Creating...";
          $contact->set('handle', $name);
          $contact->set('name', 'Guenther Mair');
          $contact->set('street', 'via Andriano');
          $contact->set('street2', '7');
          $contact->set('street3', 'G');
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
          if ( $contact->storeDB() ) {
            echo " done.\n";
          } else {
            echo " FAILED!!\n";
          }
        }

        echo "Current contact data:\n";
        echo " - street '" . $contact->get('street') . "'\n";
        echo " - street '" . $contact->get('street2') . "'\n";
        echo " - street '" . $contact->get('street3') . "'\n";
        echo " - voice '" . $contact->get('voice') . "'\n";
        echo " - email '" . $contact->get('email') . "'\n";

        echo "Changing data (street, voice, email, consent for publishing)...";
        $contact->set('street', 'via 123');
        $contact->set('voice', '+39.0471000000');
        $contact->set('email', 'info@inet-services.it');
        $contact->set('consentforpublishing', TRUE);
        echo " done.\n";

        echo "Launching DB update...";
        if ( $contact->updateDB() ) {
          echo " done.\n";
        } else {
          echo " FAILED!!\n";
        }

        echo "Destroying object...";
        unset($contact);
        echo " done.\n";

        $contact = new Net_EPP_IT_Contact($nic, $db);
        $contact->debug = LOG_DEBUG;
        if ( $contact->loadDB($name) ) {
          echo "Contact '".$name."' found in DB.\n";
        } else {
          echo "Contact '".$name."' not found in DB. THIS SHOULD NOT HAPPEN.\n";
        }

        echo "Current contact data:\n";
        echo " - street '" . $contact->get('street') . "'\n";
        echo " - street '" . $contact->get('street2') . "'\n";
        echo " - street '" . $contact->get('street3') . "'\n";
        echo " - voice '" . $contact->get('voice') . "'\n";
        echo " - email '" . $contact->get('email') . "'\n";
        break;
      case FALSE:
        echo "Contact '".$name."' already in use.\n";
        $rs = $contact->delete($name);
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

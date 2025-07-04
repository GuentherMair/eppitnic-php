<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;

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

    // test check contact
    $name = "GM00041";
    switch ($contact->check($name)) {
      case TRUE:
        echo "Contact '{$name}' is available.\n";
        $contact->set('handle', $name);
        $contact->set('name', 'Guenther Mair');
        $contact->set('street', 'via 123/B');
        $contact->set('street2', '7');
        $contact->set('street3', 'G');
        $contact->set('city', 'Bolzano');
        $contact->set('province', 'BZ');
        $contact->set('postalcode', '39100');
        $contact->set('countrycode', 'IT');
        $contact->set('voice', '+39.3480123456');
        $contact->set('email', 'info@inet-services.it');
        $contact->set('authinfo', 'ABC1234567');
        $contact->set('nationalitycode', 'IT');
        $contact->set('entitytype', '1');
        $contact->set('regcode', 'MRAGTH78P24F132L');
        if ($contact->create() === FALSE) {
          echo "Create contact '".$contact->get('handle')."' failed (".$contact->getError().").\n";
        } else {
          echo "Contact '".$contact->get('handle')."' created.\n";

          echo "Destroying current object...";
          unset($contact);
          echo " done.\n";

          echo "Creating new object...";
          $contact = new Net_EPP_IT_Contact($nic, $db);
          $contact->debug = LOG_DEBUG;
          echo " done.\n";

          echo "Fetching object data from EPP server:\n";
          if ($contact->fetch($name)) {
            echo " - street '" . $contact->get('street') . "'\n";
            echo " - street '" . $contact->get('street2') . "'\n";
            echo " - street '" . $contact->get('street3') . "'\n";
            echo " - voice '" . $contact->get('voice') . "'\n";
            echo " - email '" . $contact->get('email') . "'\n";
            echo " - consentforpublishing '" . $contact->get('consentforpublishing') . "'\n";
            echo " - regcode '" . $contact->get('regcode') . "'\n";
            echo " - nationalitycode '" . $contact->get('nationalitycode') . "'\n";
          } else {
            echo "Error: unable to fetch contact from server (".$contact->getError().")!\n";
          }

          echo "Changing data (street, voice, email, consent for publishing)...";
          $contact->set('street', 'via 123');
          $contact->set('voice', '+39.0471000000');
          $contact->set('email', 'info@inet-services.it');
          $contact->set('consentforpublishing', TRUE);
          echo " done.\n";

          /*
          echo "Deny update to contact...";
          if ($contact->updateStatus('clientUpdateProhibited', 'add')) {
            echo " done.\n";
          } else {
            echo " Error: (".$contact->getError().")!\n";
          }

          echo "Deny delete to contact...";
          if ($contact->updateStatus('clientDeleteProhibited', 'add')) {
            echo " done.\n";
          } else {
            echo " Error: (".$contact->getError().")!\n";
          }
          */

          echo "Now updating data through EPP server...\n";
          if ($contact->update()) {
            echo "Destroying current object...";
            unset($contact);
            echo " done.\n";

            echo "Creating new object...";
            $contact = new Net_EPP_IT_Contact($nic, $db);
            $contact->debug = LOG_DEBUG;
            echo " done.\n";

            echo "Fetching updated object data from EPP server:\n";
            if ($contact->fetch($name)) {
              echo " - street '" . $contact->get('street') . "'\n";
              echo " - street '" . $contact->get('street2') . "'\n";
              echo " - street '" . $contact->get('street3') . "'\n";
              echo " - voice '" . $contact->get('voice') . "'\n";
              echo " - email '" . $contact->get('email') . "'\n";
              echo " - consentforpublishing '" . $contact->get('consentforpublishing') . "'\n";
              echo " - regcode '" . $contact->get('regcode') . "'\n";
              echo " - nationalitycode '" . $contact->get('nationalitycode') . "'\n";
            } else {
              echo "Error: unable to fetch contact from server (".$contact->getError().")!\n";
            }
          } else {
            echo "Error: unable to update contact (".$contact->getError().")!\n";
          }
        }
        break;
      case FALSE:
        echo "Contact '{$name}' already in use.\n";
        $rs = $contact->delete($name);
        break;
      default:
        echo "Error checking '{$name}' (".$contact->getError().").\n";
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

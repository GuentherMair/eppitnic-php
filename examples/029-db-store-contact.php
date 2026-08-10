<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = LOG_DEBUG;
$contact = new Contact($nic);
$contact->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ($session->login() === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

// test check contact
$name = "GM0007";
switch ($contact->check($name)) {
  case TRUE:
    echo "Contact '{$name}' is available on EPP.\n";
    if ($contact->loadDB($name)) {
      echo "Contact '{$name}' found in DB.\n";
    } else {
      echo "Contact '{$name}' not found in DB. Creating...";
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
      if ($contact->storeDB()) {
        echo " done.\n";
      } else {
        echo " FAILED (".$contact->getError().")!\n";
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
    if ($contact->updateDB()) {
      echo " done.\n";
    } else {
      echo " FAILED (".$contact->getError().")!\n";
    }

    echo "Destroying object...";
    unset($contact);
    echo " done.\n";

    $contact = new Contact($nic);
    $contact->debug = LOG_DEBUG;
    if ($contact->loadDB($name)) {
      echo "Contact '{$name}' found in DB.\n";
    } else {
      echo "Contact '{$name}' not found in DB. THIS SHOULD NOT HAPPEN.\n";
    }

    echo "Current contact data:\n";
    echo " - street '" . $contact->get('street') . "'\n";
    echo " - street '" . $contact->get('street2') . "'\n";
    echo " - street '" . $contact->get('street3') . "'\n";
    echo " - voice '" . $contact->get('voice') . "'\n";
    echo " - email '" . $contact->get('email') . "'\n";
    break;
  case FALSE:
    echo "Contact '{$name}' exists. Fetching it...";
    $contact->fetch($name);
    echo " now storing it...";
    if ($contact->storeDB()) {
      echo " done.\n";
    } else {
      echo " FAILED (".$contact->getError().")!\n";
    }

    echo "Current contact data:\n";
    echo " - street '" . $contact->get('street') . "'\n";
    echo " - street '" . $contact->get('street2') . "'\n";
    echo " - street '" . $contact->get('street3') . "'\n";
    echo " - voice '" . $contact->get('voice') . "'\n";
    echo " - email '" . $contact->get('email') . "'\n";
    break;
  default:
    echo "Error checking '{$name}' (".$contact->getError().").\n";
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


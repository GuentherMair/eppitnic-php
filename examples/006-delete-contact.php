<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = true;
$contact = new Contact($nic);
$contact->debug = true;

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
$name = "GM00001";
switch ($contact->check($name)) {
  case TRUE:
    echo "Contact '{$name}' is available.\n";
    $contact->set('handle', $name);
    $contact->set('name', 'Guenther Mair');
    $contact->set('street', 'via 123/B');
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

      echo "Now trying to delete...\n";
      if ($contact->delete($name) === FALSE) {
        echo "Delete contact '{$name}' failed (".$contact->getError().").\n";
      } else {
        echo "Contact '{$name}' removed.\n";
        if ($contact->check($name)) {
          echo "Contact '{$name}' is available again.\n";
        } else {
          echo "THIS SHOULD NOT HAPPEN... contact '{$name}' is not available!\n";
        }
      }
    }
    break;
  case FALSE:
    echo "Contact '{$name}' already in use.\n";
    if ($contact->delete($name) === FALSE) {
      echo "Delete contact '{$name}' failed (".$contact->getError().").\n";
    } else {
      echo "Contact '{$name}' removed.\n";
    }
    break;
  default:
    echo "Error checking '{$name}'.\n";
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


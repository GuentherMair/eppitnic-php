<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic);
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


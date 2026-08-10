<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = LOG_DEBUG;
$domain = new Domain($nic);
$domain->debug = LOG_DEBUG;

/*
 * we will require 3 contacts at least for this script!
 */
function check_or_create($handle, $registrant = FALSE) {
  global $nic;

  $contact = new Contact($nic);
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
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ($session->login() === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
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
  exit(CONTACT_CREATE_FAILED);
}
if ( ! check_or_create($admin)) {
  echo "Unable to create '".$admin."'!\n";
  $session->logout();
  exit(CONTACT_CREATE_FAILED);
}
if ( ! check_or_create($tech)) {
  echo "Unable to create '".$tech."'!\n";
  $session->logout();
  exit(CONTACT_CREATE_FAILED);
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
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


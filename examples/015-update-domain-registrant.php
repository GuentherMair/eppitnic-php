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
 * dump domain data
 */
function dump_domain($domain) {
  echo " - Registrant: " . $domain->get('registrant') . "\n";
  echo " - Admin-C: " . $domain->get('admin') . "\n";
  $tech = $domain->get('tech');
  if ( ! is_array($tech)) {
    echo " - Tech-C: " . $tech . "\n";
  } else foreach ($tech as $single_tech) {
    echo " - Tech-C: " . $single_tech . "\n";
  }
  $state = $domain->get('status');
  foreach ($state as $s)
    echo " - state '" . $s . "'\n";
  $ns = $domain->get('ns');
  foreach ($ns as $name)
    echo " - NS: " . $name['name'] . "\n";
}

/*
 * we will require 3 contacts at least for this script!
 */
function check_or_create($handle, $registrant = FALSE) {
  global $nic;

  $contact = new Contact($nic);
  $contact->debug = LOG_DEBUG;

  if ($contact->check($handle) === FALSE)
    return TRUE;

  echo "Creating contact '".$handle."'...\n";
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
  print_r( $session->result );
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
$name = "domain-update-test-01.it";
$registrant_new = "GM1000";

check_or_create($registrant_new, TRUE);

switch ($domain->fetch($name)) {
  case TRUE:
    echo "OLD STATUS:\n";
    dump_domain($domain);
    // update domain
    $domain->set('registrant', $registrant_new);
    $domain->set('authinfo', $domain->authinfo());
    if ($domain->updateRegistrant())
      echo "Domain '{$name}' is now up to date.\n";
    else
      echo "Update to domain '{$name}' FAILED (".$domain->getError().")!\n";
    echo "NEW STATUS:\n";
    dump_domain($domain);
    break;
  case FALSE:
    echo "Domain '{$name}' should already be available!\n";
    echo "Please run the update example '014' first!\n";
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


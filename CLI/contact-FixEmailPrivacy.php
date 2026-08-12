<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$domain = new Domain($nic);

$domain_list = array();
$contact_list = array();

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

$domain_list = $domain->listDomains();

foreach ($domain_list as $name => $values)
  $contact_list[$values['registrant']]['domains'][] = $values['domain'];

foreach ($contact_list as $name => $values) {
  $contact = new Contact($nic);
  //$contact->debug = true;
  if ($contact->fetch($name)) {
    $contact->set('consentforpublishing', FALSE);
    if (in_array($contact->get('email'), array('', 'n.a.'))) {
      $contact->set('email', "info@{$values['domains'][0]}");
      echo "{$name}: set contact to info@{$values['domains'][0]}\n";
    }
    if ($contact->update())
      $contact->storeDB();
  } else {
    echo "[FAILURE]: unable to fetch {$name}\n";
  }
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

// retrieve and test command line options
$options = getopt("d:f:r:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILENAME|-d DOMAIN[:DOMAIN:...]) -e REGISTRANT\n";
  echo "\n";
  echo "  -f FILE containing domain names\n";
  echo "  -d DOMAIN name(s) given as colon-separated list on command line\n";
  echo "\n";
  echo "  -e email address to use for updating all registrants\n";
  echo "\n";
  echo " If no parameter except '-d' or '-f' is given (they are mutualy exclusive!), this command will diplay information about the domain.\n";
  echo "\n";
  exit(SYNTAX_ERROR);
}

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

// verify domain names
$domain_names = array();
$tmp = isset($options['f']) ? explode("\n", trim(file_get_contents($options['f']))) : explode(":", $options['d']);
foreach ($tmp as $domain)
  if (substr($domain, -3) == '.it')
    $domain_names[] = $domain;
if (count($domain_names) < 1) {
  echo "No valid .IT domain given!\n";
  exit(INVALID_INPUT);
}

// set the registrant email
$registrant_email = $options['e'];
if (empty($registrant_email)) {
  echo "No registrant email given!\n";
  exit(INVALID_INPUT);
}

$nic = new Client();
$session = new Session($nic);

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

foreach ($domain_names as $domain_name) {
    $domain = new Domain($nic);
    if ($domain->fetch($domain_name)) {
        $contact = new Contact($nic);
        $contact->fetch($domain->get('registrant'));
        $contact->set('email', $registrant_email);
        if ($contact->update()) {
            echo "Destroying current object...";
            unset($contact);
            echo " done.\n";

            echo "Creating new object...";
            $contact = new Contact($nic);
            echo " done.\n";

            echo "Fetching updated object data from EPP server:\n";
            if ($contact->fetch($domain->get('registrant'))) {
              echo " - email '" . $contact->get('email') . "'\n";
            } else {
              echo "Error: unable to fetch contact from server (".$contact->getError().")!\n";
            }
          } else {
            echo "Error: unable to update contact (".$contact->getError().")!\n";
          }
    }
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

<?php

use Net\EPP\Client;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

// retrieve and test command line options
$options = getopt("d:f:c:");
if ( ! isset($options['c']) ||
     (( ! isset($options['d']) && ! isset($options['f'])) ||
      (isset($options['d']) && isset($options['f'])))) {
  echo "SYNTAX: {$argv[0]} (-f FILE|-d DOMAIN[:DOMAIN:...]) -c CONTACT[:CONTACT:CONTACT:CONTACT:CONTACT:CONTACT]\n";
  echo "\n";
  echo "  -f FILE containing domain names (one per line)\n";
  echo "  -d DOMAIN name(s), given as colon-separated list on command line\n";
  echo "\n";
  echo "  -c the target tech-c set: every domain ends up with exactly these\n";
  echo "     contacts (existing ones not listed here are removed, missing ones added)\n";
  echo "\n";
  exit(SYNTAX_ERROR);
}

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

// verify domain names
$domains = array();
$tmp = isset($options['f']) ? explode("\n", trim(file_get_contents($options['f']))) : explode(":", $options['d']);
foreach ($tmp as $domain)
  if (substr($domain, -3) == '.it')
    $domains[] = $domain;
if (count($domains) < 1) {
  echo "No valid .IT domain given!\n";
  exit(INVALID_INPUT);
}

// the target tech-c set
$contacts = array_slice(explode(":", $options['c']), 0, 6);

$nic = new Client();
$session = new Session($nic);
//$session->debug = true;

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

foreach ($domains as $name) {
  // recreate domain object
  $domain = new Domain($nic);
  //$domain->debug = true;

  // load domain object
  if ( ! $domain->fetch($name)) {
    echo "Fetch domain '{$name}' FAILED (".$domain->getError().")\n";
    continue;
  }

  // remove old technical contacts not present in the target set
  $contacts_old = (array) $domain->get('tech');
  foreach ($contacts_old as $single_contact)
    if ( ! in_array($single_contact, $contacts)) {
      echo "Removing TECH-C '{$single_contact}' from '{$name}'.\n";
      $domain->remTECH($single_contact);
    }

  // add new technical contacts missing from the current set
  foreach ($contacts as $single_contact)
    if ( ! empty($single_contact) && ! in_array($single_contact, $contacts_old)) {
      echo "Adding TECH-C '{$single_contact}' to '{$name}'.\n";
      $domain->addTECH($single_contact);
    }

  // update domain
  if ($domain->update())
    echo "Domain '{$name}' is now up to date.\n";
  else
    echo "Update to domain '{$name}' FAILED (".$domain->getError().")!\n";
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

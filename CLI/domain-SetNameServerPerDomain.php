<?php

use Net\EPP\Client;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

if ($argc < 2) {
  echo "SYNTAX: {$argv[0]} CSV-FILE\n";
  echo "\n";
  echo "  CSV-FILE rows: domain;ns_to_add[:ns:...];ns_to_remove[:ns:...]\n";
  echo "\n";
  exit(SYNTAX_ERROR);
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
  echo "[{$argv[1]}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

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

while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
  $name = $data[0];
  $ns_add = explode(":", $data[1] ?? '');
  $ns_remove = explode(":", $data[2] ?? '');

  // recreate domain object
  $domain = new Domain($nic);
  //$domain->debug = true;

  // load domain object
  if ( ! $domain->fetch($name)) {
    echo "Fetch domain '{$name}' FAILED (".$domain->getError().")\n";
    continue;
  }

  // add NS records
  foreach ($ns_add as $single_ns)
    if ( ! empty($single_ns)) {
      echo "Adding NS: '{$single_ns}' to '{$name}'.\n";
      $domain->addNS($single_ns);
    }

  // remove NS records
  foreach ($ns_remove as $single_ns)
    if ( ! empty($single_ns)) {
      echo "Removing NS: '{$single_ns}' from '{$name}'.\n";
      $domain->remNS($single_ns);
    }

  // update domain
  if ($domain->update())
    echo "Domain '{$name}' is now up to date.\n";
  else
    echo "Update to domain '{$name}' FAILED (".$domain->getError().")!\n";
}
fclose($handle);

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

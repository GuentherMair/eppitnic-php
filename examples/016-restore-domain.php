<?php

use Net\EPP\Client;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = true;
$domain = new Domain($nic);
$domain->debug = true;

/*
 * dump domain data
 */
function dump_domain($domain) {
  echo " - Registrant: " . $domain->get('registrant') . "\n";
  echo " - Admin-C: " . $domain->get('admin') . "\n";
  foreach ($domain->get('tech') as $single_tech) {
    echo " - Tech-C: " . $single_tech . "\n";
  }
  $state = $domain->get('status');
  foreach ($state as $s)
    echo " - state '" . $s . "'\n";
  $ns = $domain->get('ns');
  foreach ($ns as $name)
    echo " - NS: " . $name['name'] . "\n";
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

// lookup domain
$name = "testABC12345.it";
$domain->set('domain', $name);

dump_domain($domain);

if ($domain->restore($name))
  echo "Restore domain '{$name}' succeeded.\n";
else
  echo "Restore domain '{$name}' FAILED (".$domain->getError().")!\n";

dump_domain($domain);

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


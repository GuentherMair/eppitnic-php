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
$name = "transfer-domain-02.it";
$authinfo = "1378932989";

switch ($domain->check($name)) {
  case TRUE:
    echo "Domain '{$name}' is still available, sorry!\n";
    break;
  case FALSE:
    echo "Domain '{$name}' not available, fetching information...\n";
    if ($domain->fetch($name, $authinfo, 'all')) {
      echo " - Registrant: " . $domain->get('registrant') . "\n";
      echo " - Admin-C: " . $domain->get('admin') . "\n";
      foreach ($domain->get('tech') as $single_tech) {
        echo " - Tech-C: {$single_tech}\n";
      }
      $state = $domain->get('status');
      foreach ($state as $s)
        echo " - state '{$s}'\n";
      $ns = $domain->get('ns');
      foreach ($ns as $name)
        echo " - NS: {$name['name']}\n";
      $infcontacts = $domain->get('infcontacts');
      foreach ($infcontacts as $contact) {
        echo " - infContact : ";
        print_r($contact);
      }
    } else {
      echo "FAILED (".$domain->getError().")\n";
    }
    break;
  default:
    echo "Error: '{$name}'.\n";
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


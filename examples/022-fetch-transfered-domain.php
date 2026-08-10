<?php

use Net\EPP\Client;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = LOG_DEBUG;
$domain = new Domain($nic);
$domain->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ( $session->login() === FALSE ) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

// lookup domain
$name = "transfer-domain-0001.it";
switch ( $domain->check($name) ) {
  case TRUE:
    echo "Domain '".$name."' is still available, sorry!\n";
    break;
  case FALSE:
    echo "Domain '".$name."' not available, fetching information...\n";
    if ( $domain->fetch($name) ) {
      echo " - Registrant: " . $domain->get('registrant') . "\n";
      echo " - Admin-C: " . $domain->get('admin') . "\n";
      echo " - Tech-C: " . $domain->get('tech') . "\n";
      $state = $domain->get('status');
      foreach ( $state as $s )
        echo " - state '" . $s . "'\n";
      $ns = $domain->get('ns');
      foreach ($ns as $name) {
        echo " - NS: " . $name['name'] . "\n";
      }
    } else {
      echo "FAILED (".$domain->getError().").\n";
    }
    break;
  default:
    echo "Error: '".$name."'.\n";
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


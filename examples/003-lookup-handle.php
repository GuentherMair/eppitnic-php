<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = true;
$contact = new Contact($nic);
$contact->debug = true;

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
$name = "HELLO-WORLD";
switch ($contact->check($name)) {
  case TRUE:
    echo "Contact '{$name}' is available.\n";
    break;
  case FALSE:
    echo "Contact '{$name}' already in use.\n";
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


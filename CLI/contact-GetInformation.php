<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
$contact = new Net_EPP_IT_Contact($nic);

if ($argc < 2) {
  echo "SYNTAX: {$argv[0]} CONTACT\n";
  exit(SYNTAX_ERROR);
}

$name = $argv[1];

// send "hello"
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

switch ($contact->check($name)) {
  case TRUE:
    echo "Contact '{$name}' is available.\n";
    break;
  case FALSE:
    echo "Contact '{$name}' exists, \n";
    if ($contact->fetch($name)) {
      echo $contact;
    } else {
      echo "but fetch contact FAILED (".$contact->getError().").\n";
    }
    break;
  default:
    echo "Error checking '{$name}' (".$contact->getError().").\n";
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

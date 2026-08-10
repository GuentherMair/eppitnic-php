<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = LOG_DEBUG;
$contact = new Contact($nic);
$contact->debug = LOG_DEBUG;

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

// test check contact (single)
echo "Starting single contact lookup...\n";
$name = "GM00001";
switch ($contact->check($name)) {
  case TRUE:
    echo "Contact '{$name}' is free.\n";
    break;
  case FALSE:
    echo "Contact '{$name}' already in use.\n";
    break;
  default:
    echo "Error: '{$name}' (".$contact->getError().").\n";
    break;
}

// test check contact (bulk)
echo "Starting bulk contact lookup... (remember: there is a maximum of 5 contacts that can be checked)\n";
$names = array("GM00001", "GM00002", "XY00001", "XY00002", "XY00003", "XY00004-notchecked", "XY00005-notchecked");
$result = $contact->check($names);
if (is_array($result)) {
  foreach ($result as $name => $value) {
    switch ($value) {
      case TRUE:
        echo "Contact '{$name}' is free.\n";
        break;
      case FALSE:
        echo "Contact '{$name}' already in use.\n";
        break;
    }
  }
} else {
  switch ($result) {
    case TRUE:
      echo "Contact is free.\n";
      break;
    case FALSE:
      echo "Contact already in use.\n";
      break;
    default:
      echo "Error looking up contact (".$contact->getError().").\n";
      break;
  }
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


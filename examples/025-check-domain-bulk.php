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

// test check domain (single)
echo "Starting single domain lookup...\n";
$name = "some-weired-domain.it";
switch ($domain->check($name)) {
  case TRUE:
    echo "Domain '{$name}' is available.\n";
    break;
  case FALSE:
    echo "Domain '{$name}' is NOT available.\n";
    break;
  default:
    echo "Error: '{$name}' (".$domain->getError().").\n";
    break;
}

// test check domain (bulk)
echo "Starting bulk domain lookup... (remember: there is a maximum of 5 domain that can be checked)\n";
$names = array("test.it", "x.it", "registro.it", "some-other-domain.it", "still-works.it", "notchecked1.it", "notchecked2.it");
$result = $domain->check($names);
if (is_array($result)) {
  foreach ($result as $name => $values) {
    switch ($values['available']) {
      case TRUE:
        echo "Domain '{$name}' is free.\n";
        break;
      case FALSE:
        echo "Domain '{$name}' already in use ('{$values['reason']}').\n";
        break;
    }
  }
} else {
  switch ($result) {
    case TRUE:
      echo "Domain is free.\n";
      break;
    case FALSE:
      echo "Domain already in use.\n";
      break;
    default:
      echo "Error looking up domain (".$domain->getError().").\n";
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


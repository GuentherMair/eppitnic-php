<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Session;

$init = ($argc == 2) ? $argv[1] : "DUP";
require_once dirname(__FILE__).'/../vendor/autoload.php';

use RedBeanPHP\R;

$nic = new Client();
$session = new Session($nic);
$contact = new Contact($nic);

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

try {
  $rows = R::getAll("SELECT handle FROM contacts WHERE active = 1 AND handle LIKE :init", [":init" => "{$init}%"]);
  foreach ($rows as $row) {
    if ($contact->delete($row['handle'])) {
      echo "[SUCCESS] Contact '{$row['handle']}' removed.\n";

      // archive the handle locally
      R::exec("UPDATE contacts SET active = 0 WHERE active = 1 AND handle = :name", [":name" => $row['handle']]);
    } else {
      echo "[FAILURE] Contact '{$row['handle']}' NOT removed (".$contact->getError().").\n";
    }
  }
} catch (\RedBeanPHP\RedException\SQL $e) {
  echo "[FAILURE] A database error occured: " . $e->getMessage() . "\n";
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

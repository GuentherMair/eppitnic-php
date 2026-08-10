<?php

use Net\EPP\Client;
use Net\EPP\Config;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = LOG_DEBUG;
$contact = new Contact($nic);
$contact->debug = LOG_DEBUG;

$new_password = substr(md5(rand()), 0, 8);

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ($session->login($new_password) === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

// switch password inside the 'epp' setting
try {
  $epp = Config::get('epp');
  $epp['password'] = $new_password;
  Config::set('epp', $epp);
  echo "Overall password update on server side and in the 'epp' setting was successfull.\n";
} catch (\Throwable $e) {
  // maybe you prefer to send an email here or exit with a different exit code or ...
  echo "\n";
  echo "WARNING: password update on server side succeeded, but the 'epp' setting\n";
  echo "         could not be updated ({$e->getMessage()})! Set the password to: {$new_password}\n";
  echo "         or you will not be able to log in again!\n";
  echo "\n";
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

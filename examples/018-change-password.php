<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic);
$contact->debug = LOG_DEBUG;

$cfg = realpath(dirname(__FILE__).'/../config/config.json');

$new_password = substr(md5(rand()), 0, 8);

// check availability and writeability of configuration file
if ( ! is_writable($cfg)) {
  echo "Config file '".$cfg."' does not exist or is not writable.\n";
  exit(CONFIG_ERROR);
}

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

// switch password inside configuration file
$cfgData = json_decode(file_get_contents($cfg), true);
$cfgData['epp']['password'] = $new_password;
$result = file_put_contents($cfg, json_encode($cfgData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// make sure password switch did complete successfully
if ($result) {
  echo "Overall password update on server side and in '{$cfg}' was successfull.\n";
} else {
  // maybe you prefer to send an email here or exit with a different exit code or ...
  echo "\n";
  echo "WARNING: password update on server side succeeded, but '{$cfg}'\n";
  echo "         could not be updated! Set the password to: {$new_password}\n";
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

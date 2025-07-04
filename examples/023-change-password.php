<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;

$cfg = realpath(dirname(__FILE__).'/../config.xml');

$new_password = substr(md5(rand()), 0, 8);

// check availability and writeability of configuration file
if ( ! is_writable($cfg) ) {
  echo "Config file '".$cfg."' does not exist or is not writable.\n";
  exit;
}

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  echo "Greeting OK.\n";

  // perform login
  if ( $session->login($new_password) === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    // switch password inside configuration file
    $result = file_put_contents($cfg, str_replace("<password>".strtolower($nic->EPPCfg->password)."</password>", "<password>".$new_password."</password>", strtolower(file_get_contents($cfg))));

    // make sure password switch did complete successfully
    if ( $result ) {
      echo "Overall password update on server side and in '".$cfg."' was successfull.\n";
    } else {
      // maybe you prefer to send an email here or exit with a different exit code or ...
      echo "\n";
      echo "WARNING: password update on server side succeeded, but '".$cfg."'\n";
      echo "         could not be updated! Set the password to: ".$new_password."\n";
      echo "         or you will not be able to log in again!\n";
      echo "\n";
    }

    // logout
    if ( $session->logout() ) {
      echo "Logout OK.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}  


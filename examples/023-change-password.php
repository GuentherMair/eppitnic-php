<?php

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_IT_Client("config.xml");
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;

$new_password = substr(md5(rand()), 0, 8);

// check availability of config.xml
if ( ! is_writable("config.xml") ) {
  echo "Config file config.xml does not exist or is not writable.\n";
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
    echo "Login FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  } else {
    echo "Login OK (code ".$session->svCode.", '".$session->svMsg."').\n";

    // switch password inside configuration file
    $result = file_put_contents("config.xml", ereg_replace("<password>".$nic->EPPCfg->password."</password>", "<password>".$new_password."</password>", strtolower(file_get_contents("config.xml"))));

    // make sure password switch did complete successfully
    if ( $result ) {
      echo "Overall password update on server side and in config.xml was successfull.\n";
    } else {
      // maybe you prefer to send an email here or exit with a different exit code or ...
      echo "\n";
      echo "WARNING: password update on server side succeeded, but config.xml could not be\n";
      echo "         updated! Set the password to: ".$new_password." or you will not be able\n";
      echo "         to log in again!\n";
      echo "\n";
    }

    // logout
    if ( $session->logout() ) {
      echo "Logout OK (code ".$session->svCode.", '".$session->svMsg."').\n";
    } else {
      echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}  

?>

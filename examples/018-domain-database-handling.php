<?php

set_include_path('.:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_IT_Client("config.xml");
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  echo "Greeting OK.\n";

  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    // lookup domain
    $name = "test1234567890.it";
    if ( $domain->loadDB($name) ) {
      echo "Domain ".$name." found in DB. Processing update...\n";
      $domain->set('authinfo', $domain->authinfo());
      if ( $domain->updateDB($name) ) {
        echo "Database updated successfully.\n";
      } else {
        echo "Failed to to update database!!\n";
      }
    } else {
      echo "Domain ".$name." not found in DB. Fetching...\n";
      if ( $domain->fetch($name) ) {
        echo "Domain ".$name." found.\n";
        if ( $domain->storeDB($name) ) {
          echo "Domain stored in DB. Run again to handle an update!\n";
        } else {
          echo "Failed to store Domain in DB!!\n";
        }
      } else {
        echo "Domain ".$name." not found! Aborting...\n";
      }
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


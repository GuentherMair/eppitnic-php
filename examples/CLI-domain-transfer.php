<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

// retrieve and test command line options
$options = getopt("d:a:r:");
if ( ! isset($options['d']) ||
     ! isset($options['a']) ) {
  echo "SYNTAX: " . $argv[0] . " -d DOMAIN -a AUTHINFO\n";
  exit(1);
}

// set values
$name = $options['d'];
$authinfo = $options['a'];

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
    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' does not exist, sorry!\n";
        echo "Please make sure:\n";
        echo " - this domain exists\n";
        echo " - is owned by another registrar/mantainer\n";
        echo " - to change this file (".__FILE__."), changing the authinfo\n";
        break;
      case FALSE:
        $domain->transferStatus($name);
        echo "Transfer-Status: ".$domain->get('trStatus')."\n";
        if ( $domain->transfer($name, $authinfo) )
          echo "Transfer OK\n";
        else
          echo "Transfer FAILED (".$domain->getError().")!\n";
        $domain->transferStatus($name);
        echo "Transfer-Status: ".$domain->get('trStatus')."\n";
        break;
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


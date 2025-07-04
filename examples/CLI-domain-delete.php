#!/usr/local/php5/bin/php
<?php

if ( $argc < 2 ) {
  echo "SYNTAX: ".$argv[0]." FILE\n";
  exit(1);
}

$fh = fopen($argv[1], "r");
$data = array();
while ( ($row = fgets($fh, 4096)) !== FALSE ) {
  $data[] = rtrim($row);
}
fclose($fh);

print_r($data);

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
//$session->debug = LOG_DEBUG;

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

    foreach ($data as $name) {
      $domain = new Net_EPP_IT_Domain($nic, $db);
      $domain->debug = LOG_DEBUG;
      $domain->set("domain", $name);
    
      // lookup domain
      switch ( $domain->check($name) ) {
	case TRUE:
	  echo "Domain '".$name."' is still available, sorry!\n";
	  break;
	case FALSE:
	  if ($domain->delete($name)) {
            echo "[SUCCESS] Domain '".$name."' deleted.\n";
	  } else {
	    echo "[FAILURE] Domain '".$name."' not deleted (".$domain->getError().")\n";
	  }
	  break;
	default:
	  echo "Error: '".$name."'.\n";
	  break;
      }
    }

    // logout
    if ( $session->logout() )
      echo "Logout OK.\n";
    else
      echo "Logout FAILED (".$session->getError().").\n";
  }
}  


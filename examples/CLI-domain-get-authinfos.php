<?php

error_reporting(E_ERROR);
set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;


// retrieve and test command line options
$options = getopt("f:");
if ( !isset($options['f']) ) {
  echo "SYNTAX: " . $argv[0] . " -f FILENAME\n";
  exit(1);
}

if ( !is_readable($options['f']) ) {
  echo "[" . $options['f'] . "] is not a readable file.\n";
  exit(2);
}

$domains = split("\n", trim(file_get_contents($options['f'])));

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {

    // loop through all domain names
    foreach ( $domains as $name ) {
      // recreate domain object
      $domain = new Net_EPP_IT_Domain($nic, $db);
      $domain->debug = LOG_DEBUG;

      // load domain object
      $domain->fetch($name);

      echo '"' . $name . '","' . $domain->get('authinfo') . '"' . "\n";
    }

    // logout
    if ( ! $session->logout() )
      echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  }
}  

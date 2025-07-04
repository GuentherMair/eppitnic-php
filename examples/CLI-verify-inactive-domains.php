<?php

error_reporting(E_ERROR | E_WARNING | E_PARSE);
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

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  echo "Greeting OK.\n";

  $session->logout();

  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    // list in-active domains
    $sql = "SELECT domain FROM tbl_domains WHERE active = 0";
    $result = $db->dbConnect->Execute($sql);
    while ( !$result->EOF ) {
      $d = $result->Fields('domain');
      if ( $domain->check($d) !== TRUE ) {
        if ( $domain->fetch($d) ) {
          echo "Domain '".$d."' still exists and should be removed.\n";
          $state = $domain->get('status');
          foreach ( $state as $s )
            echo " - state '" . $s . "'\n";
        }
      }
      $result->MoveNext();
    }

    // logout
    if ( $session->logout() )
      echo "Logout OK.\n";
    else
      echo "Logout FAILED (".$session->getError().").\n";
  }
}


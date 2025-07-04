<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_IT_Client();
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
    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' is still available, sorry!\n";
        break;
      case FALSE:
        echo "Domain '".$name."' not available, fetching information...\n";
        if ( $domain->fetch($name) ) {
          echo " - Registrant: " . $domain->get('registrant') . "\n";
          echo " - Admin-C: " . $domain->get('admin') . "\n";
          $tech = $domain->get('tech');
          if ( ! is_array($tech) ) {
            echo " - Tech-C: " . $tech . "\n";
          } else foreach ($tech as $single_tech) {
            echo " - Tech-C: " . $single_tech . "\n";
          }
          $state = $domain->get('status');
          foreach ( $state as $s )
            echo " - state '" . $s . "'\n";
          $ns = $domain->get('ns');
          foreach ($ns as $name) {
            echo " - NS: " . $name['name'] . "\n";
          }
        } else {
          echo "FAILED (".$domain->getError().")\n";
        }
        break;
      default:
        echo "Error: '".$name."'.\n";
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


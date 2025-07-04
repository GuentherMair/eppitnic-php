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

if ( $argc < 2 ) {
  echo "SYNTAX: " . $argv[0] . " DOMAIN\n";
  exit(1);
}

$name = $argv[1];

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  echo "Greeting OK.\n";

  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  } else {
    echo "Login OK (code ".$session->svCode.", '".$session->svMsg."').\n";

    // lookup domain
    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' is still available, sorry!\n";
        break;
      case FALSE:
        echo "Domain '".$name."' not available, fetching information...\n";
        if ( $domain->fetch($name) ) {
          echo " - Registrant: " . $domain->get('registrant') . "\n";
          echo " - Admin-C: " . $domain->get('admin') . "\n";
          echo " - Tech-C: " . $domain->get('tech') . "\n";
          echo " - AuthInfo: " . $domain->get('authinfo') . "\n";
          echo " - Status: " . $domain->state() . "\n";
          $ns = $domain->get('ns');
          foreach ($ns as $name) {
            echo " - NS: " . $name['name'] . "\n";
          }
        } else {
          echo "FAILED\n";
        }
        echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";
        break;
      default:
        echo "Error: '".$name."'.\n";
        break;
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

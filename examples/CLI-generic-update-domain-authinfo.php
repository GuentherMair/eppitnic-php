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

if ( $argc < 3 ) {
  echo "SYNTAX: " . $argv[0] . " DOMAIN AUTHINFO\n";
  exit(1);
}

$name = $argv[1];
$authinfo = $argv[2];

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

    // some details
    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' is available.\n";
        echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";
        break;
      case FALSE:
        echo "Domain '".$name."' is NOT available.\n";
        echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";
        break;
      default:
        echo "Error: '".$name."'.\n";
        exit;
        break;
    }

    // destroy domain object
    unset($domain);

    // recreate domain object
    $domain = new Net_EPP_IT_Domain($nic, $db);
    $domain->debug = LOG_DEBUG;

    // load domain object
    $domain->fetch($name);

    // update domain
    $domain->set('authinfo', $authinfo);
    switch ( $domain->update() ) {
      case TRUE:
        echo "Domain '".$name."' is now up to date.\n";
        break;
      case FALSE:
        echo "Update to domain '".$name."' FAILED!.\n";
        echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";
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

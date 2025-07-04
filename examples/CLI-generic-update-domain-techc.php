<?php

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

if ( $argc < 4 ) {
  echo "SYNTAX: " . $argv[0] . " DOMAIN [add|remove] CONTACT[:CONTACT:CONTACT:CONTACT:CONTACT:CONTACT]\n";
  exit(1);
}

$name = $argv[1];
$op_type = strtolower($argv[2]);
$contact = $argv[3];

if ( $op_type != 'add' && $op_type != 'remove' ) {
  echo "Syntax error: operation type '".$op_type."' not supported, please use 'add' or 'remove' instead\n";
  exit(1);
}

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

    // recreate domain object
    $domain = new Net_EPP_IT_Domain($nic, $db);
    $domain->debug = LOG_DEBUG;

    // load domain object
    $domain->fetch($name);

    // split CONTACT information
    $contact_array = array_slice(split(":", $contact), 0, 6);

    // change technical contacts
    switch ($op_type) {
      case 'add':
        foreach ($contact_array as $single_contact) {
          echo "Adding NS: ".$single_contact."\n";
          $domain->addTECH($single_contact);
        }
        break;
      case 'remove':
        foreach ($contact_array as $single_contact) {
          echo "Removing NS: ".$single_contact."\n";
          $domain->remTECH($single_contact);
        }
        break;
    }

    // update domain
    switch ( $domain->update() ) {
      case TRUE:
        echo "Domain '".$name."' is now up to date.\n";
        break;
      case FALSE:
        echo "Update to domain '".$name."' FAILED!.\n";
        echo "Reason code ".$domain->svCode.", '".$domain->svMsg."', '".$domain->extValueReason."'.\n";
        echo "\n";
        echo "Query:\n";
        print_r($domain->xmlQuery);
        echo "\n";
        echo "Result:\n";
        print_r($domain->result);
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

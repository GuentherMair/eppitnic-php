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


// retrieve and test command line options
$options = getopt("d:a:r:");
if ( ! isset($options['d']) ||
     (! isset($options['a']) && ! isset($options['r'])) ) {
  echo "SYNTAX: " . $argv[0] . " -d DOMAIN -a CONTACT[:CONTACT:CONTACT:CONTACT:CONTACT:CONTACT] -r CONTACT[:CONTACT:CONTACT:CONTACT:CONTACT:CONTACT]\n";
  exit(1);
}


// set values
$name = $options['d'];
$contact_add = array_slice(split(":", $options['a']), 0, 6);
$contact_remove = array_slice(split(":", $options['r']), 0, 6);


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

    // add technical contact
    foreach ($contact_add as $single_contact)
      if ( ! empty($single_contact) ) {
        echo "Adding TECH-C: ".$single_contact."\n";
        $domain->addTECH($single_contact);
      }

    // remove technical contact
    foreach ($contact_remove as $single_contact)
      if ( ! empty($single_contact) ) {
        echo "Removing TECH-C: ".$single_contact."\n";
        $domain->remTECH($single_contact);
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

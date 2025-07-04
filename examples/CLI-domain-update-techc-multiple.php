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
$options = getopt("d:c:");
if ( !isset($options['d']) || !isset($options['c']) ) {
  echo "SYNTAX: " . $argv[0] . " -d DOMAIN[:DOMAIN:...] -c CONTACT[:CONTACT:CONTACT:CONTACT:CONTACT:CONTACT]\n";
  exit(1);
}


// set values
$names = split(":", $options['d']);
$contacts = array_slice(split(":", $options['c']), 0, 6);


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

    // loop through all domain names
    foreach ( $names as $name ) {
      // recreate domain object
      $domain = new Net_EPP_IT_Domain($nic, $db);
      $domain->debug = LOG_DEBUG;

      // load domain object
      $domain->fetch($name);

      // remove old technical contacts
      $contacts_old = $domain->get('tech');
      foreach ($contacts_old as $single_contact)
        if ( ! in_array($single_contact, $contacts) ) {
          echo "Removing TECH-C ".$single_contact." from ".$name.".\n";
          $domain->remTECH($single_contact);
        }

      // add new technical contacts
      foreach ($contacts as $single_contact)
        if ( ! empty($single_contact) && ! in_array($single_contact, $contacts_old) ) {
          echo "Adding TECH-C ".$single_contact." to ".$name.".\n";
          $domain->addTECH($single_contact);
        }

      // update domain
      if ( $domain->update() )
        echo "Domain '".$name."' is now up to date.\n";
      else
        echo "Update to domain '".$name."' FAILED (".$domain->getError().")!\n";
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


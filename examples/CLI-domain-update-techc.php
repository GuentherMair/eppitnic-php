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
     (! isset($options['a']) && ! isset($options['r'])) ) {
  echo "SYNTAX: " . $argv[0] . " -d DOMAIN -a CONTACT[:CONTACT:CONTACT:CONTACT:CONTACT:CONTACT] -r CONTACT[:CONTACT:CONTACT:CONTACT:CONTACT:CONTACT]\n";
  exit(SYNTAX_ERROR);
}


// set values
$name = $options['d'];
$contact_add = array_slice(split(":", $options['a']), 0, 6);
$contact_remove = array_slice(split(":", $options['r']), 0, 6);


// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ( $session->login() === FALSE ) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

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
if ( $domain->update() )
  echo "Domain '".$name."' is now up to date.\n";
else
  echo "Update to domain '".$name."' FAILED (".$domain->getError().")!\n";

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";



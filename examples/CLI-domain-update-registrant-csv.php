<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

if ( $argc < 3 ) {
  echo "SYNTAX: " . $argv[0] . " CSV-FILE REGISTRANT\n";
  exit(1);
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
  echo "CSV-FILE ".$arg[1]." not readable\n";
  exit(2);
}

// set the new registrant name
$newregistrant = $argv[2];

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    
    if ($contact->fetch($newregistrant)) {
      // get values
      $values = array();
      $values['name'] = $contact->get('name');
      $values['street'] = $contact->get('street');
      $values['city'] = $contact->get('city');
      $values['province'] = $contact->get('province');
      $values['postalcode'] = $contact->get('postalcode');
      $values['voice'] = $contact->get('voice');
      $values['fax'] = $contact->get('fax');
      $values['email'] = $contact->get('email');
      $values['authinfo'] = $contact->get('authinfo');
      $values['nationalitycode'] = $contact->get('nationalitycode');
      $values['entitytype'] = $contact->get('entitytype');
      $values['regcode'] = $contact->get('regcode');
      $values['consentforpublishing'] = $contact->get('consentforpublishing');

      print_r($values);

      while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
	$name = $data[0];

	// recreate domain object
	$domain = new Net_EPP_IT_Domain($nic, $db);
	$domain->debug = LOG_DEBUG;

	// load domain object
	$domain->fetch($name);

	// set registrant
	$domain->set('registrant', $newregistrant);

	// set random authinfo code
	$domain->set('authinfo', substr(md5(rand()), 0, 16));

	// update domain
	if ($domain->updateRegistrant())
	  echo "Domain '".$name."' is now up to date.\n";
	else
	  echo "Update to domain '".$name."' FAILED (".$domain->getError().")!\n";
      }
    }

    // logout
    if ($session->logout())
      echo "Logout OK.\n";
    else
      echo "Logout FAILED (".$session->getError().").\n";
  }
}

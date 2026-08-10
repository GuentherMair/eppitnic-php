<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = LOG_DEBUG;
$contact = new Contact($nic);
$contact->debug = LOG_DEBUG;

$handle = "GM0005";
$regcode = "12345678910";

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ($session->login() === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

// create empty contact object
echo "Creating new object...";
$contact = new Contact($nic);
$contact->debug = LOG_DEBUG;
echo " done.\n";

// updateing object
echo "Updateing local object '".$handle."':\n";
$contact->set('handle', $handle);
echo "- regCode has been set to '".$contact->get('regcode')."'\n";
$contact->set('regcode', $regcode);   

// send data to server
echo "Now updating data through EPP server...\n";
if ($contact->update()) {
  echo "Result code {$contact->svCode}, '{$contact->svMsg}'.\n";
  echo "Destroying current object...";
  unset($contact);
  echo " done.\n";

  echo "Creating new object...";
  $contact = new Contact($nic);
  $contact->debug = LOG_DEBUG;
  echo " done.\n";                     

  // retrieve some information from server
  echo "Fetching object data from EPP server:\n<br/><br/>";
  if ($contact->fetch($handle)) {
    echo " - name '" . $contact->get('name') . "'\n";
    echo " - street '" . $contact->get('street') . "'\n";
    echo " - city '" . $contact->get('city') . "'\n";
    echo " - regcode: '".$contact->get('regcode')."'\n";
  } else {              
    echo "Error: unable to fetch contact from server (".$contact->getError().")!\n";
  }

} else {
  echo "Error: unable to update contact (".$contact->getError().")!\n";
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


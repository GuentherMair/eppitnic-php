<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_IT_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;

$handle = "GM00002";
$regcode = "12345678910";

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

    // create empty contact object
    echo "Creating new object...";
    $contact = new Net_EPP_IT_Contact($nic, $db);
    $contact->debug = LOG_DEBUG;
    echo " done.\n";

    // updateing object
    echo "Updateing local object '".$handle."':\n";
    $contact->set('handle', $handle);
    echo "- regCode has been set to '".$contact->get('regcode')."'\n";
    $contact->set('regcode', $regcode);   

    // send data to server
    echo "Now updating data through EPP server...\n";
    if ( $contact->update() ) {
      echo "Result code ".$contact->svCode.", '".$contact->svMsg."'.\n";
      echo "Destroying current object...";
      unset($contact);
      echo " done.\n";

      echo "Creating new object...";
      $contact = new Net_EPP_IT_Contact($nic, $db);
      $contact->debug = LOG_DEBUG;
      echo " done.\n";                     

      // retrieve some information from server
      echo "Fetching object data from EPP server:\n<br/><br/>";
      if ( $contact->fetch($handle) ) {
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
    if ( $session->logout() ) {
      echo "Logout OK.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}


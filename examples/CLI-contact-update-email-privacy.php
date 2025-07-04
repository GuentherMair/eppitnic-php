<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/WebInterface/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_WebInterface_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

$domain_list = array();
$contact_list = array();

if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  echo "Greeting OK.\n";

  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    $domain_list = $domain->listDomains();
    //print_r($domain_list);

    foreach ($domain_list as $name => $values) {
      //echo $values['domain'].": ".$values['registrant']."\n";
      $contact_list[$values['registrant']]['domains'][] = $values['domain'];
    }
    //print_r($contact_list);
    foreach ($contact_list as $name => $values) {
      $contact = new Net_EPP_IT_Contact($nic, $db);
      $contact->debug = LOG_DEBUG;
      if ($contact->fetch($name)) {
        $contact->set('consentforpublishing', FALSE);
        if (in_array($contact->get('email'), array('', 'n.a.'))) {
          $contact->set('email', 'info@'.$values['domains'][0]);
          echo $name . ": set contact to info@".$values['domains'][0]."\n";
        }
        if ($contact->update())
          $contact->storeDB();
      } else {
        echo "[FAILURE]: unable to fetch " . $name . "\n";
      }
    }

    if ( $session->logout() )
      echo "Logout OK.\n";
    else
      echo "Logout FAILED (".$session->getError().").\n";
  }
}

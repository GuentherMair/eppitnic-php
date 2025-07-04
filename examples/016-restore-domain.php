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

/*
 * dump domain data
 */
function dump_domain($domain) {
  if ( $domain->fetch($name) ) {
    echo " - Registrant: " . $domain->get('registrant') . "\n";
    echo " - Admin-C: " . $domain->get('admin') . "\n";
    echo " - Tech-C: " . $domain->get('tech') . "\n";
    echo " - Status: " . $domain->state() . "\n";
    $ns = $domain->get('ns');
    foreach ($ns as $name) {
      echo " - NS: " . $name['name'] . "\n";
    }
  } else {
    echo "UNABLE TO FETCH DOMAIN ".$name."!!\n";
  }
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

    // lookup domain
    $name = "test1234567890.it";
    $domain->set('domain', $name);

    dump_domain($domain);

    switch ( $domain->restore($name) ) {
      case FALSE:
        echo "Domain '".$name."' - RESTORE FAILED!\n";
        //print_r($domain->result['body']);
        break;
      case TRUE:
        echo "Domain '".$name."', restore succeeded.\n";
        break;
    }
    echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";

    dump_domain($domain);

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

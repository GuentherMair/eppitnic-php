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
    $name = "transfer-domain-0001.it";
    $authinfo = "c93cdd6f1e78fb44";
    $newregistrant = "GM0001";

    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' does not exist, sorry!\n";
        echo "Please make sure:\n";
        echo " - this domain exists\n";
        echo " - is owned by another registrar/mantainer\n";
        echo " - to change this file (".__FILE__."), changing the authinfo\n";
        break;
      case FALSE:
        $domain->transferStatus($name);
        echo "Transfer-Status: ".$domain->svMsg."\n";
        if ( $domain->transferReject($name, $authinfo) ) {
          echo "Transfer OK";
          echo " (code ".$domain->svCode.", '".$domain->svMsg."').\n";
        } else {
          echo "Transfer FAILED!\n";
          print_r($domain->result['body']);
        }
        $domain->transferStatus($name);
        echo "Transfer-Status: ".$domain->svMsg."\n";
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

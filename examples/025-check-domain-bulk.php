<?php

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
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

    // test check domain (single)
    echo "Starting single domain lookup...\n";
    $name = "some-weired-domain.it";
    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' is available.\n";
        break;
      case FALSE:
        echo "Domain '".$name."' is NOT available.\n";
        break;
      default:
        echo "Error: '".$name."'.\n";
        break;
    }
    echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";

    // test check domain (bulk)
    echo "Starting bulk domain lookup... (remember: there is a maximum of 5 domain that can be checked)\n";
    $names = array("test.it", "x.it", "registro.it", "some-other-domain.it", "still-works.it", "notchecked1.it", "notchecked2.it");
    $result = $domain->check($names);
    if ( is_array($result) ) {
      foreach ($result as $name => $values) {
        switch ( $values['available'] ) {
          case TRUE:
            echo "Contact '".$name."' is free.\n";
            break;
          case FALSE:
            echo "Contact '".$name."' already in use ('".$values['reason']."').\n";
            break;
        }
      }
    } else {
      switch ( $result ) {
        case TRUE:
          echo "Contact is free.\n";
          break;
        case FALSE:
          echo "Contact already in use.\n";
          break;
        default:
          echo "Error looking up contact.\n";
          break;
      }
      echo "Reason code ".$domain->svCode.", '".$domain->svMsg."'.\n";
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

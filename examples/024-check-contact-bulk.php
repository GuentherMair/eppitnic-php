<?php

set_include_path('.:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_IT_Client("config.xml");
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;

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

    // test check contact (single)
    echo "Starting single contact lookup...\n";
    $name = "GM00001";
    switch ( $contact->check($name) ) {
      case TRUE:
        echo "Contact '".$name."' is free.\n";
        break;
      case FALSE:
        echo "Contact '".$name."' already in use.\n";
        break;
      default:
        echo "Error: '".$name."'.\n";
        break;
    }

    // test check contact (bulk)
    echo "Starting bulk contact lookup... (remember: there is a maximum of 5 contacts that can be checked)\n";
    $names = array("GM00001", "GM00002", "XY00001", "XY00002", "XY00003", "XY00004-notchecked", "XY00005-notchecked");
    $result = $contact->check($names);
    if ( is_array($result) ) {
      foreach ($result as $name => $value) {
        switch ( $value ) {
          case TRUE:
            echo "Contact '".$name."' is free.\n";
            break;
          case FALSE:
            echo "Contact '".$name."' already in use.\n";
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

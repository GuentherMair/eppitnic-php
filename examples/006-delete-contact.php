<?php

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

    // test check contact
    $name = "GM00001";
    switch ( $contact->check($name) ) {
      case TRUE:
        echo "Contact '".$name."' is available.\n";
        $contact->set('handle', $name);
        $contact->set('name', 'Guenther Mair');
        $contact->set('street', 'via Andriano 7/G');
        $contact->set('city', 'Bolzano');
        $contact->set('province', 'BZ');
        $contact->set('postalcode', '39010');
        $contact->set('countrycode', 'IT');
        $contact->set('voice', '+39.3486914569');
        $contact->set('email', 'guenther.mair@hoslo.ch');
        $contact->set('authinfo', 'ABC1234567');
        $contact->set('nationalitycode', 'IT');
        $contact->set('entitytype', '1');
        $contact->set('regcode', 'MRAGTH78P24F132L');
        if ( $contact->create() === FALSE ) {
          echo "Create contact '".$contact->get('handle')."' failed.\n";
          echo "Result code ".$contact->svCode.", '".$contact->svMsg."'.\n";
        } else {
          echo "Create contact '".$contact->get('handle')."' created.\n";
          echo "Result code ".$contact->svCode.", '".$contact->svMsg."'.\n";

          echo "Now trying to delete...\n";
          if ( $contact->delete($name) === FALSE ) {
            echo "Delete contact '".$name."' failed.\n";
            echo "Result code ".$contact->svCode.", '".$contact->svMsg."'.\n";
          } else {
            echo "Contact '".$name."' removed.\n";
            echo "Result code ".$contact->svCode.", '".$contact->svMsg."'.\n";

            if ( $contact->check($name) ) {
              echo "Contact '".$name."' is available again.\n";
            } else {
              echo "THIS SHOULD NEVER HAPPEN... contact '".$name."' is not available!\n";
            }
            echo "Result code ".$contact->svCode.", '".$contact->svMsg."'.\n";
          }
        }
        break;
      case FALSE:
        echo "Contact '".$name."' already in use.\n";
        $rs = $contact->delete($name);
        break;
      default:
        echo "Error: '".$name."'.\n";
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

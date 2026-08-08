<?php

$init = ($argc == 2) ? $argv[1] : "DUP";

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$contact = new Net_EPP_IT_Contact($nic, $db);

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ($session->login() === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

$result = $db->dbConnect->Execute("SELECT handle FROM contacts WHERE active = 1 AND handle like ".$db->escape($init."%").";");
while ( ! $result->EOF) {
  $name = $result->Fields('handle');
  if ($contact->delete($name)) {
    echo "[SUCCESS] Contact '".$name."' removed.\n";
    // try to delete or at least archive the handle
    $db->dbConnect->Execute("UPDATE contacts SET active = 0 WHERE active = 1 AND handle = '".$name."'; DELETE FROM contacts WHERE active = 1 AND handle = '".$name."';");
  } else {
    echo "[FAILURE] Contact '".$name."' NOT removed (".$contact->getError().").\n";
  }
  $result->MoveNext();
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


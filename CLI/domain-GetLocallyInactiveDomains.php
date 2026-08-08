<?php

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$domain = new Net_EPP_IT_Domain($nic, $db);

// send "hello"
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

try {
  // list in-active domains
  $stmt = $db->db->prepare("SELECT domain FROM domains WHERE active = 0");
  $stmt->execute();
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($domain->check($row['domain']) !== TRUE) {
      if ($domain->fetch($row['domain'])) {
        echo "Domain '{$row['domain']}' still exists and should be removed.\n";
        $state = $domain->get('status');
        foreach ($state as $s)
          echo " - state '{$s}'\n";
      }
    }
  }
} catch (PDOException $e) {
  echo "[FAILURE] A database error occured: " . $e->getMessage() . "\n";
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

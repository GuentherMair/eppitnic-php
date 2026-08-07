<?php

$init = ($argc == 2) ? $argv[1] : "DUP";

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$contact = new Net_EPP_IT_Contact($nic, $db);

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
  $stmt = $db->db->prepare("SELECT handle FROM tbl_contacts WHERE active = 1 AND handle LIKE :init");
  $stmt->execute(array(":init" => "{$init}%"));
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($contact->delete($row['name'])) {
      echo "[SUCCESS] Contact '{$row['name']}' removed.\n";

      // try to delete or at least archive the handle
      $stmt2 = $db->db->prepare("UPDATE tbl_contacts SET active = 0 WHERE active = 1 AND handle = :name; DELETE FROM tbl_contacts WHERE active = 1 AND handle = :name2");
      $stmt2->execute(array(":name" => $row['name'], ":name2" => $row['name']));
    } else {
      echo "[FAILURE] Contact '{$row['name']}' NOT removed (".$contact->getError().").\n";
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

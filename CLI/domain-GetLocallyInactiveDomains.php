<?php

//error_reporting(E_ERROR | E_WARNING | E_PARSE);
//set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$domain = new Net_EPP_IT_Domain($nic, $db);

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    try {
      // list in-active domains
      $stmt = $db->db->prepare("SELECT domain FROM tbl_domains WHERE active = 0");
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

    // close session
    if ($session->logout()) {
      echo "Your remaining credit: {$session} EUR.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }
  }
}

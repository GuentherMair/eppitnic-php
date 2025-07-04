<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));
error_reporting(E_ERROR);

if ( $argc < 2 ) {
  echo "SYNTAX: " . $argv[0] . " CSV-FILE\n";
  exit(1);
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
  echo "CSV-FILE ".$arg[1]." not readable\n";
  exit(2);
}

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  // perform login
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
      $name = $data[0];
      $registrant = $data[1];
      $admin = $data[1];
      $tech = array($data[2]);
      $ns = array($data[3], $data[4]);
      if (isset($data[5]))
        $ns[] = $data[5];

      $lockfile = "/tmp/domain-created_".$name;
      if (file_exists($lockfile)) {
        echo "Domain '".$name."' was already created - ignoring.\n";
        continue;
      }

      // lookup domain
      $domain->set('domain', $name);
      switch ($domain->check($name)) {
        case TRUE:
          $domain->set('domain', $name);
          $domain->set('registrant', $registrant);
          $domain->set('admin', $admin);
          foreach ($tech as $tmp)
            $domain->addTECH($tmp);
          foreach ($ns as $tmp)
            $domain->addNS($tmp);
          $domain->set('authinfo', substr(rand(), 0, 32));
          if ($domain->create()) {
            echo "Domain '".$name."' created (code ".$domain->svCode.", '".$domain->svMsg."').\n";
            touch($lockfile);
            if ( ! empty($argv[2]))
              mail($argv[2], "domain '".$name."' created", "code ".$domain->svCode.", '".$domain->svMsg."'");
          } else {
            echo "Domain '".$name."' NOT created (code ".$domain->svCode.", '".$domain->svMsg."' / '".$domain->extValueReasonCode."', '".$domain->extValueReason."').\n";
          }
          break;
        default:
          echo "Domain '".$name."' NOT created (code ".$domain->svCode.", '".$domain->svMsg."')\n";
          break;
      }
    }
    fclose($handle);

    // logout
    if ( ! $session->logout())
      echo "Logout FAILED (".$session->getError().").\n";
  }
}

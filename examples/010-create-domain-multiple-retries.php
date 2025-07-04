<?php

if ($argc < 2) {
  echo "SYNTAX: {$argv[0]} DOMAIN\n";
  exit(1);
}
$name = trim($argv[1]);

// how often to retry and how
$retries = 240;
$sleep_initial = 15; // start this job 1 at 08:59 or 15:59 !!!
$usleep_between = 500000; // between retries sleep 0.5 seconds (500000 microseconds)

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  echo "Greeting OK.\n";

  // take a nap
  sleep($sleep_initial);

  // perform login
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    $domain->set('domain', $name);
    $domain->set('registrant', 'REGISTRANT');
    $domain->set('admin', 'ADMIN');
    $domain->set('tech', 'TECHC');
    $domain->set('ns', 'ns1.yourdomain.tld');
    $domain->set('ns', 'ns2.yourdomain.tld');
    $domain->set('authinfo', substr(rand(), 0, 32));

    $status = FALSE;
    while ($retries > 0) {
      $status = $domain->create();
      if ($status) {
        echo "Domain '{$name}' created.\n";
        break;
      } else {
        echo "Try n. {$retries} Domain '{$name}' NOT created (".$domain->getError().").\n";
        usleep($usleep_between);
      }
      $retries--;
    }

    // logout
    if ($session->logout()) {
      echo "Logout OK.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}

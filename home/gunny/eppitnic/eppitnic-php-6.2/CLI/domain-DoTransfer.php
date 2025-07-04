<?php

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:f:i:");
if (( ! isset($options['f']) && ! (isset($options['d']) && isset($options['i']))) ||
    (isset($options['f']) && isset($options['d']))) {
  echo "SYNTAX: {$argv[0]} (-f CSV-FILE|-d DOMAIN[:DOMAIN:...] -i AUTHINFO[:AUTHINFO:...])\n";
  echo "\n";
  echo "  -f CSV FILE containing domain names transfer + authinfo codes\n";
  echo "\n";
  echo "  -d DOMAIN(s) to transfer\n";
  echo "  -i AUTHINFO code(s) necessary for each domain\n";
  echo "\n";
  exit(1);
}

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(2);
}

// verify domain names
$domains = array();
$authinfos = array();
if (isset($options['f'])) {
  $tmp = array();
  $fh = fopen($options['f'], "r");
  while (($data = fgetcsv($fh, 1000, ",")) !== FALSE) {
    if (count($data) <> 2)
      continue;
    $tmp[] = $data[0];
    $authinfos[] = $data[1];
  }
  fclose($fh);
} else {
  $tmp = explode(":", $options['d']);
  $authinfos = explode(":", $options['i']);
}
foreach ($tmp as $domain)
  if (substr($domain, -3) == '.it')
    $domains[] = $domain;
if (count($domains) < 1) {
  echo "No valid .IT domain given!\n";
  exit(4);
}
if (count($domains) <> count($authinfos)) {
  echo "Number of .IT domain names does not correlate to amount of authinfo codes given!\n";
  exit(8);
}


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
    for ($i = 0; $i < count($domains); $i++) {
      // re-create domain object
      $domain = new Net_EPP_IT_Domain($nic, $db);
      //$domain->debug = LOG_DEBUG;

      $name = $domains[$i];
      $authinfo = $authinfos[$i];

      // lookup domain
      switch ($domain->check($name)) {
        case TRUE:
          echo "Domain '{$name}' does not exist, sorry!\n";
          echo "Please make sure:\n";
          echo " - this domain exists\n";
          echo " - is owned by another registrar/mantainer\n";
          echo " - to change this file (".__FILE__."), changing the authinfo\n";
          break;
        case FALSE:
          $domain->transferStatus($name);
          $statusPrev = $domain->get('trStatus');
          if ($domain->transfer($name, $authinfo)) {
            echo "[SUCCESS] Transfer '${name}' OK";
          } else {
            echo "[FAILURE] Transfer '${name}' failed (".$domain->getError().")";
          }
          $domain->transferStatus($name);
          $statusNow = $domain->get('trStatus');
          echo ", transfer status changed from '{$statusPrev}' to '{$statusNow}'\n";
          break;
      }
    }

    // close session
    if ($session->logout()) {
      echo "Your remaining credit: {$session} EUR.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }
  }
}
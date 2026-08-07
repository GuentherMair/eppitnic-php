<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:f:u:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILE|-d DOMAIN[:DOMAIN:...]) [-u USERID]\n";
  echo "\n";
  echo "  -f FILE      file containing domain names to import (one per line)\n";
  echo "  -d DOMAIN    domain name(s) to import, given as colon-separated list\n";
  echo "  -u USERID    user ACL to store imported domains/contacts under (defaults to 1)\n";
  echo "\n";
  exit(1);
}

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(2);
}

$userid = isset($options['u']) ? (int)$options['u'] : 1;

// verify domain names
$domains = array();
$tmp = isset($options['f']) ? explode("\n", trim(file_get_contents($options['f']))) : explode(":", $options['d']);
foreach ($tmp as $name) {
  $name = trim($name);
  if (substr($name, -3) == '.it')
    $domains[] = $name;
}
if (count($domains) < 1) {
  echo "No valid .IT domain given!\n";
  exit(3);
}

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$domain = new Net_EPP_IT_Domain($nic, $db);

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
  exit(4);
}

// login
if ($session->login() === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(5);
}

// import domains and print result
$results = $domain->import(implode(" ", $domains), $userid);
foreach ($results as $name => $result) {
  echo "{$name}:\n";
  foreach ($result as $step => $state)
    echo "  - {$step}: {$state}\n";
}

// close session
if ($session->logout()) {
  echo "Your remaining credit: {$session} EUR.\n";
} else {
  echo "Logout FAILED (".$session->getError().").\n";
}

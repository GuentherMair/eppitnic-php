<?php

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:f:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILE|-d DOMAIN[:DOMAIN:...])\n";
  echo "\n";
  echo "  -f FILE containing domain names to delete\n";
  echo "  -d DOMAIN name(s) to delete, given as colon-separated list on command line\n";
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
$tmp = isset($options['f']) ? explode("\n", trim(file_get_contents($options['f']))) : explode(":", $options['d']);
foreach ($tmp as $domain)
  if (substr($domain, -3) == '.it')
    $domains[] = $domain;
if (count($domains) < 1) {
  echo "No valid .IT domain given!\n";
  exit(4);
}

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
//$session->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    foreach ($domains as $name) {
      $domain = new Net_EPP_IT_Domain($nic, $db);
      //$domain->debug = LOG_DEBUG;
    
      // lookup domain
      switch ($domain->check($name)) {
	case TRUE:
	  echo "Domain '{$name}' is still available, sorry!\n";
	  break;
	case FALSE:
	  if ($domain->delete($name)) {
            echo "[SUCCESS] Domain '{$name}' deleted.\n";
	  } else {
	    echo "[FAILURE] Domain '{$name}' not deleted (".$domain->getError().")\n";
	  }
	  break;
	default:
	  echo "Error checking '{$name}'.\n";
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

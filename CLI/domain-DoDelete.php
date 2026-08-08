<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:f:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILE|-d DOMAIN[:DOMAIN:...])\n";
  echo "\n";
  echo "  -f FILE containing domain names to delete\n";
  echo "  -d DOMAIN name(s) to delete, given as colon-separated list on command line\n";
  echo "\n";
  exit(SYNTAX_ERROR);
}

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

// verify domain names
$domains = array();
$tmp = isset($options['f']) ? explode("\n", trim(file_get_contents($options['f']))) : explode(":", $options['d']);
foreach ($tmp as $domain)
  if (substr($domain, -3) == '.it')
    $domains[] = $domain;
if (count($domains) < 1) {
  echo "No valid .IT domain given!\n";
  exit(INVALID_INPUT);
}

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
//$session->debug = LOG_DEBUG;

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

foreach ($domains as $name) {
  $domain = new Net_EPP_IT_Domain($nic);
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

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

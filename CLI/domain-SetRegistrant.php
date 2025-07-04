<?php

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:f:r:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILENAME|-d DOMAIN[:DOMAIN:...]) -r REGISTRANT\n";
  echo "\n";
  echo "  -f FILE containing domain names\n";
  echo "  -d DOMAIN name(s) given as colon-separated list on command line\n";
  echo "\n";
  echo "  -r registrant contact to set\n";
  echo "\n";
  echo " If no parameter except '-d' or '-f' is given (they are mutualy exclusive!), this command will diplay information about the domain.\n";
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

// set the registrant
$registrant = $options['r'];
if (empty($registrant)) {
  echo "No registrant given!\n";
  exit(8);
}


$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$contact = new Net_EPP_IT_Contact($nic, $db);
$domain = new Net_EPP_IT_Domain($nic, $db);

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    if ($contact->fetch($registrant)) {
      foreach ($domais as $name) {
	// re-create domain object
	$domain = new Net_EPP_IT_Domain($nic, $db);
	$domain->fetch($name);
	$domain->set('registrant', $registrant);
	$domain->set('authinfo', substr(md5(rand()), 0, 16));

	// update domain
	if ($domain->updateRegistrant()) {
	  echo "[SUCCESS] Domain '{$name}' is now up to date.\n";
	} else {
	  echo "[FAILURE] Update to domain '{$name}' FAILED (".$domain->getError().")!\n";
        }
      }
    } else {
      echo "[FAILURE] Unable to get new registrant handle '{$registrant}'!\n";
    }

    // close session
    if ($session->logout()) {
      echo "Your remaining credit: {$session} EUR.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }
  }
}

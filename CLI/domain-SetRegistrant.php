<?php

use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

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
  exit(SYNTAX_ERROR);
}

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

// verify domain names
$domain_names = array();
$tmp = isset($options['f']) ? explode("\n", trim(file_get_contents($options['f']))) : explode(":", $options['d']);
foreach ($tmp as $domain)
  if (substr($domain, -3) == '.it')
    $domain_names[] = $domain;
if (count($domain_names) < 1) {
  echo "No valid .IT domain given!\n";
  exit(INVALID_INPUT);
}

// set the registrant
$registrant = $options['r'];
if (empty($registrant)) {
  echo "No registrant given!\n";
  exit(INVALID_INPUT);
}

$nic = new Client();
$session = new Session($nic);
$contact = new Contact($nic);
$domain = new Domain($nic);

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

if ($contact->fetch($registrant)) {
  foreach ($domain_names as $domain_name) {
	// re-create domain object
	$domain = new Domain($nic);
	$domain->fetch($domain_name);
	$domain->set('registrant', $registrant);
	$domain->set('authinfo', substr(md5(rand()), 0, 16));

	// update domain
	if ($domain->updateRegistrant()) {
	  echo "[SUCCESS] Domain '{$domain_name}' is now up to date.\n";
	} else {
	  echo "[FAILURE] Update to domain '{$domain_name}' FAILED (".$domain->getError().")!\n";
    }
  }
} else {
  echo "[FAILURE] Unable to get new registrant handle '{$registrant}'!\n";
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

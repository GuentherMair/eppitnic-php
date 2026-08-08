<?php

if ($argc < 2) {
  echo "SYNTAX: {$argv[0]} DOMAIN\n";
  exit(SYNTAX_ERROR);
}
$name = trim($argv[1]);

// how often to retry and how
$retries = 240;
$sleep_initial = 15; // start this job 1 at 08:59 or 15:59 !!!
$usleep_between = 500000; // between retries sleep 0.5 seconds (500000 microseconds)
require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic);
$domain->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// take a nap
sleep($sleep_initial);

// perform login
if ($session->login() === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
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
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


<?php

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

/*
 * dump domain data
 */
function dump_domain($domain) {
  echo " - Registrant: " . $domain->get('registrant') . "\n";
  echo " - Admin-C: " . $domain->get('admin') . "\n";
  $tech = $domain->get('tech');
  if ( ! is_array($tech)) {
    echo " - Tech-C: " . $tech . "\n";
  } else foreach ($tech as $single_tech) {
    echo " - Tech-C: " . $single_tech . "\n";
  }
  $state = $domain->get('status');
  foreach ($state as $s)
    echo " - state '" . $s . "'\n";
  $ns = $domain->get('ns');
  foreach ($ns as $name)
    echo " - NS: " . $name['name'] . "\n";
}

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

// lookup domain
$name = "testABC12345.it";
$domain->set('domain', $name);

dump_domain($domain);

if ($domain->restore($name))
  echo "Restore domain '{$name}' succeeded.\n";
else
  echo "Restore domain '{$name}' FAILED (".$domain->getError().")!\n";

dump_domain($domain);

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


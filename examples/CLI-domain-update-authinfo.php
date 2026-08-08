<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

if ( $argc < 3 ) {
  echo "SYNTAX: " . $argv[0] . " DOMAIN AUTHINFO\n";
  exit(SYNTAX_ERROR);
}

$name = $argv[1];
$authinfo = $argv[2];

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
  exit(HELLO_FAILED);
}
echo "Greeting OK.\n";

// perform login
if ( $session->login() === FALSE ) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}
echo "Login OK.\n";

// some details
switch ( $domain->check($name) ) {
  case TRUE:
    echo "Domain '".$name."' is available.\n";
    break;
  case FALSE:
    echo "Domain '".$name."' is NOT available.\n";
    break;
  default:
    echo "Error: '".$name."' (".$domain->getError().").\n";
    exit(DOMAIN_CHECK_FAILED);
    break;
}

// destroy domain object
unset($domain);

// recreate domain object
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

// load domain object
$domain->fetch($name);

// update domain
$domain->set('authinfo', $authinfo);
if ( $domain->update() )
  echo "Domain '".$name."' is now up to date.\n";
else
  echo "Update to domain '".$name."' FAILED (".$domain->getError().")!\n";

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";



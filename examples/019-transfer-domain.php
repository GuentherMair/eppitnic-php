<?php

use Net\EPP\Client;
use Net\EPP\IT\Domain;
use Net\EPP\IT\Session;

require_once dirname(__FILE__).'/../vendor/autoload.php';

$nic = new Client();
$session = new Session($nic);
$session->debug = true;
$domain = new Domain($nic);
$domain->debug = true;

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
$name = "transfer-domain-01.it";
$authinfo = "261412394";
$newregistrant = "GM0001";

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
    echo "Transfer-Status: ".$domain->get('trStatus')."\n";
    if ($domain->transfer($name, $authinfo, $newregistrant))
      echo "Transfer OK\n";
    else
      echo "Transfer FAILED (".$domain->getError().")!\n";
    $domain->transferStatus($name);
    echo "Transfer-Status: ".$domain->get('trStatus')."\n";
    break;
}

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";


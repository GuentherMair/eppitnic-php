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
    if ( $domain->transferApprove($name, $authinfo) )
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


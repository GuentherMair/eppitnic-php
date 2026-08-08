<?php

use Algo26\IdnaConvert\ToUnicode;
use RedBeanPHP\R;

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Domain.php';

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
  exit(SYNTAX_ERROR);
}

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

$user_id = isset($options['u']) ? (int)$options['u'] : 1;

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
  exit(INVALID_INPUT);
}

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
$domain = new Net_EPP_IT_Domain($nic);

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
  exit(HELLO_FAILED);
}

// login
if ($session->login() === FALSE) {
  echo "Login FAILED (".$session->getError().").\n";
  exit(LOGIN_FAILED);
}

// import domains and print result
$contact = new Net_EPP_IT_Contact($nic);
$idn_decoder = new ToUnicode();

$results = [];
foreach (array_unique($domains) as $name) {
  $result = [
    'step1_domain'     => 'unknown',
    'step2_registrant' => 'unknown',
    'step3_reg_store'  => 'unknown',
    'step4_dom_store'  => 'unknown',
  ];

  // IT-NIC does not respond to queries for "xn--..." domain names!
  $name = $idn_decoder->convert(strtolower($name));
  if ( ! $domain->fetch($name)) {
    $result['step1_domain'] = 'not found';
    $domain->deleteDomainDB($name, $user_id, true);
    $results[$name] = $result;
    continue;
  }
  $result['step1_domain'] = 'found';

  if ( ! $contact->fetch($domain->get('registrant'))) {
    $result['step2_registrant'] = 'not found';
    $results[$name] = $result;
    continue;
  }
  $result['step2_registrant'] = 'found';

  // store/update contact -- if the registrant already exists locally, keep its current owner
  $registrant = R::getRow("SELECT user_id FROM contacts WHERE handle = ?", [$domain->get('registrant')]);
  $effectiveUserID = empty($registrant) ? $user_id : (int)$registrant['user_id'];
  $result['step3_reg_store'] = $contact->storeDB($effectiveUserID) ? 'stored' : 'not stored';

  // store/update domain
  if ($domain->storeDB($effectiveUserID)) {
    $result['step4_dom_store'] = 'stored';
    R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
  } else {
    $result['step4_dom_store'] = 'not stored';
  }

  $results[$name] = $result;
}

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

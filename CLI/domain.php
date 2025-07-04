<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
//$session->debug = LOG_DEBUG;


// retrieve and test command line options
$options = getopt("d:f:r:a:o:t:c:i:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILENAME|-d DOMAIN[:DOMAIN:...]) [-r NS[:NS:NS:NS:NS:NS]] [-a NS[:...]] [-o TECH[:TECH:TECH:TECH:TECH:TECH]] [-t TECH[:...]] [-c ADMIN] [-i AUTHINFO]\n";
  echo "\n";
  echo "  -f FILE containing domain names\n";
  echo "  -d DOMAIN name(s) given as colon-separated list on command line\n";
  echo "\n";
  echo "  -r NS record to remove (max. 6)\n";
  echo "  -a NS record to add (max. 6)\n";
  echo "\n";
  echo "  -o technical contact to remove (max. 6)\n";
  echo "  -t technical contact to add (max. 6)\n";
  echo "\n";
  echo "  -c admin contact to set\n";
  echo "\n";
  echo "  -i set a new authinfo code\n";
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

// decide whether just to dump domain infos or not
if (isset($options['a']) ||
    isset($options['r']) ||
    isset($options['c']) ||
    isset($options['o']) ||
    isset($options['i'])) {
  $fetch_only = FALSE;
} else {
  $fetch_only = TRUE;
}

// this is called when updateing the domain
function update_domain($domain, $options) {
  $ns_add = array_slice(explode(":", $options['a']), 0, 6);
  $ns_remove = array_slice(explode(":", $options['r']), 0, 6);
  $contact_add = array_slice(explode(":", $options['t']), 0, 6);
  $contact_remove = array_slice(explode(":", $options['o']), 0, 6);
  $admin = $options['c'];
  $authinfo = $options['i'];

  // add NS records
  foreach ($ns_add as $single_ns)
    if ( ! empty($single_ns)) {
      echo "Adding NS: {$single_ns}\n";
      $domain->addNS($single_ns);
    }

  // remove NS records
  foreach ($ns_remove as $single_ns)
    if ( ! empty($single_ns) ) {
      echo "Removing NS: {$single_ns}\n";
      $domain->remNS($single_ns);
    }

  // add technical contact
  foreach ($contact_add as $single_contact)
    if ( ! empty($single_contact)) {
      echo "Adding TECH-C: {$single_contact}\n";
      $domain->addTECH($single_contact);
    }

  // remove technical contact
  foreach ($contact_remove as $single_contact)
    if ( ! empty($single_contact)) {
      echo "Removing TECH-C: {$single_contact}\n";
      $domain->remTECH($single_contact);
    }

  // set new admin
  if ( ! empty($admin))
    $domain->set('admin', $admin);

  // set new authinfo
  if ( ! empty($authinfo))
    $domain->set('authinfo', $authinfo);

  // update domain
  if ($domain->update())
    echo "The domain is now up to date.\n";
  else
    echo "Update to the domain FAILED (".$domain->getError().")!\n";
}


// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    foreach ($domains as $name) {
      // re-create domain object
      $domain = new Net_EPP_IT_Domain($nic, $db);

      // lookup domain
      switch ($domain->check($name)) {
        case TRUE:
          echo "Domain '{$name}' is still available, sorry!\n";
          break;
        case FALSE:
          echo "Domain '{$name}' taken, fetching information...\n";
          if ($domain->fetch($name)) {

            // if no update operation was requested, display domain information
            if ($fetch_only) {
              echo $domain;
            } else {
              update_domain($domain, $options);
            }
          } else {
            echo "Fetch domain FAILED (".$domain->getError().")\n";
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

<?php

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:f:r:a:t:n:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILE|-d DOMAIN[:DOMAIN:...]) -r REGISTRANT [-a ADMIN] -t TECH[:TECH:TECH:TECH:TECH:TECH] -n NS:NS[:NS:NS:NS:NS]\n";
  echo "\n";
  echo "  -f FILE containing domain names to create\n";
  echo "  -d DOMAIN name(s) to craeate, given as colon-separated list on command line\n";
  echo "\n";
  echo " -r registrant contact\n";
  echo " -a administrative contact\n";
  echo " -t technical contact(s) (1-6)\n";
  echo " -n nameserver records to add (2-6)\n";
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

// verify other properties
$registrant = $options['r'];
if (empty($registrant)) {
  echo "No registrant specified!\n";
  exit(8);
}

$admin = $options['a'];
if (empty($admin))
  $admin = $registrant;

$tech = array_slice(explode(":", $options['t']), 0, 6);
if (count($tech) < 1) {
  echo "No technical contact specified!\n";
  exit(16);
}

$ns = array_slice(explode(":", $options['n']), 0, 6);
if (count($ns) < 2) {
  echo "You need to specify at least 2 nameservers!\n";
  exit(32);
}


$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
//$session->debug = LOG_DEBUG;

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
      //$domain->debug = LOG_DEBUG;

      // lookup domain
      switch ($domain->check($name)) {
        case TRUE:
          $domain->set('domain', $name);
          $domain->set('registrant', $registrant);
          $domain->set('admin', $admin);
          foreach ($tech as $tmp)
            $domain->addTECH($tmp);
          foreach ($ns as $tmp)
            $domain->addNS($tmp);
          $domain->set('authinfo', substr(rand(), 0, 32));
          if ( ! $domain->create()) {
            echo "Domain '{$name}' NOT created trough epp.nic.it (code {$domain->svCode}, '{$domain->svMsg}' / '{$domain->extValueReasonCode}', '{$domain->extValueReason}').\n";
            if ((int)$domain->extValueReasonCode == 9078 || (int)$domain->svCode == 2308) {
              echo "Domain '{$name}' is available but needs to be restored through epp-deleted.nic.it.\n";

              // logout old session
              if ( ! $session->logout())
                echo "Verification session logout failed (code {$session->svCode}, '{$session->svMsg}').\n";

              // append "-deleted" to server's hostname
              $cfg = preg_replace('/<server>https:\/\/(.*).nic.it<\/server>/', '<server>https://${1}-deleted.nic.it</server>', file_get_contents('config.xml'));

              // re-do session using connection to server for restoring domains
              $nic = new Net_EPP_Client($cfg);
              $db = new Net_EPP_StorageDB($nic->EPPCfg->db);
              $session = new Net_EPP_IT_Session($nic, $db);
              $domain = new Net_EPP_IT_Domain($nic, $db);

              // send "hello"
              if ( ! $session->hello()) {
                echo "Connection failed.\n";
              } else {
                // perform login
                if ($session->login() === FALSE) {
                  echo "Login failed (code {$session->svCode}, '{$session->svMsg}').\n";
                } else {
                  // configure domain
                  $domain->set('domain', $name);
                  $domain->set('registrant', $registrant);
                  $domain->set('admin', $admin);
                  foreach ($tech as $tmp)
                    $domain->addTECH($tmp);
                  foreach ($ns as $tmp)
                    $domain->addNS($tmp);
                  $domain->set('authinfo', substr(rand(), 0, 32));

                  if ($domain->create())
                    echo "Domain '{$name}' created.\n";
                  else
                    echo "Domain '{$name}' NOT created (code {$domain->svCode}, '{$domain->svMsg}' / '{$domain->extValueReasonCode}', '{$domain->extValueReason}').\n";
                }
              }
            } else {
              echo "Domain '{$name}' NOT created (code {$domain->svCode}, '{$domain->svMsg}' / '{$domain->extValueReasonCode}', '{$domain->extValueReason}').\n";
            }
          }
          break;
        case FALSE:
          echo "Domain '{$name}' exists (if it was deleted maybe you would want to restore it).\n";
          break;
        default:
          echo "Error checking '{$name}' (".$domain->getError().").\n";
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

<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:f:r:a:t:n:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILE|-d DOMAIN[:DOMAIN:...]) -r REGISTRANT [-a ADMIN] -t TECH[:TECH:TECH:TECH:TECH:TECH] -n NS:NS[:NS:NS:NS:NS]\n";
  echo "\n";
  echo "  -f FILE containing domain names to create, one per line. Each line is\n";
  echo "     either a bare domain name (uses -r/-a/-t/-n below for that domain),\n";
  echo "     or a ';'-separated row 'domain;registrant;tech[:tech:...];ns:ns[:...]'\n";
  echo "     to set a distinct registrant/tech/ns for that domain\n";
  echo "  -d DOMAIN name(s) to craeate, given as colon-separated list on command line\n";
  echo "\n";
  echo " -r registrant contact (required unless every -f row supplies its own)\n";
  echo " -a administrative contact\n";
  echo " -t technical contact(s) (1-6)\n";
  echo " -n nameserver records to add (2-6)\n";
  echo "\n";
  exit(SYNTAX_ERROR);
}

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

// each entry: ['domain', 'registrant', 'admin', 'tech', 'ns'], the latter four
// null when the line didn't supply its own and should fall back to -r/-a/-t/-n
$domains = array();
if (isset($options['f'])) {
  foreach (explode("\n", trim(file_get_contents($options['f']))) as $line) {
    $line = trim($line);
    if ($line === '') continue;

    // self-contained CSV row: domain;registrant;tech[:tech:...];ns:ns[:ns:...]
    $fields = explode(";", $line);
    if (count($fields) >= 4 && substr($fields[0], -3) == '.it') {
      $domains[] = array(
        'domain'     => $fields[0],
        'registrant' => $fields[1],
        'admin'      => $fields[1],
        'tech'       => array_slice(explode(":", $fields[2]), 0, 6),
        'ns'         => array_slice(explode(":", $fields[3]), 0, 6),
      );
    } else if (substr($line, -3) == '.it') {
      $domains[] = array('domain' => $line, 'registrant' => null, 'admin' => null, 'tech' => null, 'ns' => null);
    }
  }
} else {
  foreach (explode(":", $options['d']) as $name) {
    if (substr($name, -3) == '.it') {
      $domains[] = array('domain' => $name, 'registrant' => null, 'admin' => null, 'tech' => null, 'ns' => null);
    }
  }
}
if (count($domains) < 1) {
  echo "No valid .IT domain given!\n";
  exit(INVALID_INPUT);
}

// -r/-a/-t/-n are the fallback for domains that didn't bring their own values
$needsFallback = false;
foreach ($domains as $d) {
  if ($d['registrant'] === null) { $needsFallback = true; break; }
}

$registrant = $options['r'] ?? null;
$admin = $options['a'] ?? $registrant;
$tech = isset($options['t']) ? array_slice(explode(":", $options['t']), 0, 6) : array();
$ns = isset($options['n']) ? array_slice(explode(":", $options['n']), 0, 6) : array();

if ($needsFallback) {
  if (empty($registrant)) {
    echo "No registrant specified!\n";
    exit(INVALID_INPUT);
  }
  if (count($tech) < 1) {
    echo "No technical contact specified!\n";
    exit(INVALID_INPUT);
  }
  if (count($ns) < 2) {
    echo "You need to specify at least 2 nameservers!\n";
    exit(INVALID_INPUT);
  }
}

foreach ($domains as &$d) {
  if ($d['registrant'] === null) {
    $d['registrant'] = $registrant;
    $d['admin']      = $admin;
    $d['tech']       = $tech;
    $d['ns']         = $ns;
  }
}
unset($d);


$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
//$session->debug = LOG_DEBUG;

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

foreach ($domains as $entry) {
  $name = $entry['domain'];
  $registrant = $entry['registrant'];
  $admin = $entry['admin'];
  $tech = $entry['tech'];
  $ns = $entry['ns'];

  if (empty($tech) || count($ns) < 2) {
    echo "Domain '{$name}' skipped: needs at least 1 tech contact and 2 nameservers.\n";
    continue;
  }

  // re-create domain object
  $domain = new Net_EPP_IT_Domain($nic);
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

          // re-do session using the "-deleted" endpoint for restoring domains
          $nic = new Net_EPP_Client(getConfig('epp')['server_deleted']);
          $session = new Net_EPP_IT_Session($nic);
          $domain = new Net_EPP_IT_Domain($nic);

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

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

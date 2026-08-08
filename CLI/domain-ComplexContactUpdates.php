<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php';
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Session.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';
require_once dirname(__FILE__).'/../Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:f:t:T:p:P:e:E:");

function show_usage($argv) {
  echo "SYNTAX: {$argv[0]} (-f FILENAME|-d DOMAIN[:DOMAIN:...]) [OPTIONS]\n";
  echo "\n";
  echo "Domain selection (one required):\n";
  echo "  -f FILE      File containing domain names (one per line)\n";
  echo "  -d DOMAIN    Domain name(s) as colon-separated list\n";
  echo "\n";
  echo "Tech-C replacement (required):\n";
  echo "  -t HANDLE    Old tech-c handle to replace\n";
  echo "  -T HANDLE    New tech-c handle\n";
  echo "\n";
  echo "Phone replacement:\n";
  echo "  -p PHONE     Old phone number to replace\n";
  echo "  -P PHONE     New phone number\n";
  echo "\n";
  echo "Email replacement:\n";
  echo "  -e EMAIL     Old email address to replace\n";
  echo "  -E EMAIL     New email address\n";
  echo "\n";
}

if ( ( ! isset($options['d']) && ! isset($options['f'])) || // no domain
     (isset($options['d']) && isset($options['f'])) ||      // manual domains AND file stated
     ( ! isset($options['t'])) ||                           // no old tech-c
     ( ! isset($options['T']))                              // no new tech-c
   ) {
  show_usage($argv);
  exit(SYNTAX_ERROR);
}

$nic = new Net_EPP_Client();
$session = new Net_EPP_IT_Session($nic);
//$session->debug = LOG_DEBUG;

// retrieve and test command line options
if (isset($options['f']) && ! is_readable($options['f'])) {
  echo "[{$options['f']}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

// verify domain names
$domains = array();
$tmp = isset($options['f']) ? explode("\n", trim(file_get_contents($options['f']))) : explode(":", $options['d']);
foreach ($tmp as $domain)
  if (substr($domain, -3) == '.it')
    $domains[] = $domain;
if (count($domains) < 1) {
  echo "No valid .IT domain given!\n";
  exit(INVALID_INPUT);
}

function update_contact($handle, &$options) {
  global $nic;

  // create a new+clean contact object
  $contact = new Net_EPP_IT_Contact($nic);

  // fetch the contact by its handle
  if (!$contact->fetch($handle)) {
    echo "- fetching handle '{$handle}' FAILED with error '".$contact->getError()."'!\n";
    return;
  }

  // by default we won't update the contact
  $changed = false;

  // update email in contact
  if (!isset($options['e']) && !isset($options['E'])) {
    $email = $contact->get("email");
    if ($email == $options['e']) {
      $changed = true;
      $contact->set("email", $options['E']);
      echo "  - will change 'email' from '{$email}'\n";
    }
  }

  // update landline numbers in contact
  if (!isset($options['p']) && !isset($options['P'])) {
    $voice = $contact->get("voice");
    if ($voice == $options['p']) {
      $changed = true;
      $contact->set("voice", $options['P']);
      echo "  - will change 'voice' from '{$voice}'\n";
    }

    $fax = $contact->get("fax");
    if ($fax == $options['p']) {
      $changed = true;
      $contact->set("fax", $options['P']);
      echo "  - will change 'fax' from '{$fax}'\n";
    }
  }

  // exit...
  if (!$changed) {
    return;
  }

  // ... or update
  if (!$contact->update()) {
    echo "  - updating contact FAILED with error '".$contact->getError()."'!\n";
  }
}

function update_domain(&$domain, &$options) {
  $old_tech_c = $options['t'];
  $new_tech_c = $options['T'];

  // verify if the tech-c needs to be replaced
  $techc = $domain->get("tech");
  if (!is_array($techc)) {
    $techc = [ $techc ];
  }
  if (in_array($old_tech_c, $techc)) {
    echo "- removing contact '{$old_tech_c}' from tech-c's: '".implode("', '", $techc)."'\n";
    $domain->addTECH($new_tech_c);
    $domain->remTECH($old_tech_c);
    if (!$domain->update()) {
      echo "- updating domain FAILED with error '".$domain->getError()."'!\n";
    }
  }

  // verify remaining tech contacts (remove old/new tech-c's)
  if (($key = array_search([$old_tech_c, $new_tech_c], $techc)) !== false) {
    unset($techc[$key]);
  }
  if (count($techc) > 0) {
    foreach ($techc as $contact) {
      echo "- verifying tech contact '{$contact}':\n";
      update_contact($contact, $options);
    }
  }

  // verify admin contact
  $contact = $domain->get("admin");
  echo "- verifying admin contact '{$contact}'\n";
  update_contact($contact, $options);

  // verify registrant
  $contact = $domain->get("registrant");
  echo "- verifying registrant '{$contact}'\n";
  update_contact($contact, $options);
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

foreach ($domains as $name) {
  $domain = new Net_EPP_IT_Domain($nic);
  echo "Verifying domain '{$name}':\n";
  if ($domain->fetch($name)) {
    update_domain($domain, $options);
  } else {
    echo "Fetch domain FAILED (".$domain->getError().")\n";
  }
}

// remind the user to remove (delete) the old tech-c
echo "\n";
echo "Operations concluded - if the tech-c was successfully replaced, you may now remove(delete) it.\n";
echo "If unsure, simply try to delete it anyways, as a delete request for a contact still locked to any domain will fail.\n";
echo "\n";

// logout
if ( ! $session->logout() ) {
  echo "Logout FAILED (code ".$session->svCode.", '".$session->svMsg."').\n";
  exit(LOGOUT_FAILED);
}

// all done
echo "Logout OK, your remaining credit: {$session} EUR.\n";

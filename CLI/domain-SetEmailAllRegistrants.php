<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';


// retrieve and test command line options
$options = getopt("d:f:r:");
if (( ! isset($options['d']) && ! isset($options['f'])) ||
    (isset($options['d']) && isset($options['f']))) {
  echo "SYNTAX: {$argv[0]} (-f FILENAME|-d DOMAIN[:DOMAIN:...]) -e REGISTRANT\n";
  echo "\n";
  echo "  -f FILE containing domain names\n";
  echo "  -d DOMAIN name(s) given as colon-separated list on command line\n";
  echo "\n";
  echo "  -e email address to use for updating all registrants\n";
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

// set the registrant email
$registrant_email = $options['e'];
if (empty($registrant_email)) {
  echo "No registrant email given!\n";
  exit(8);
}


$nic = new Net_EPP_Client();
$db = new Net_EPP_StorageDB($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);


// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  echo "Greeting OK.\n";

  // perform login
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    foreach ($domais as $name) {
        $domain = new Net_EPP_IT_Domain($nic, $db);
        if ($domain->fetch($name)) {
            $contact = new Net_EPP_IT_Contact($nic, $db);
            $contact->fetch($domain->get('registrant'));
            $contact->set('email', $registrant_email);
            if ($contact->update()) {
                echo "Destroying current object...";
                unset($contact);
                echo " done.\n";

                echo "Creating new object...";
                $contact = new Net_EPP_IT_Contact($nic, $db);
                echo " done.\n";

                echo "Fetching updated object data from EPP server:\n";
                if ($contact->fetch($domain->get('registrant'))) {
                  echo " - email '" . $contact->get('email') . "'\n";
                } else {
                  echo "Error: unable to fetch contact from server (".$contact->getError().")!\n";
                }
              } else {
                echo "Error: unable to update contact (".$contact->getError().")!\n";
              }
        }
    }

    // logout
    if ($session->logout()) {
      echo "Logout OK.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}
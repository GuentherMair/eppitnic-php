<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

$nic = new Net_EPP_IT_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;


// retrieve and test command line options
$options = getopt("d:a:r:c:o:i:");
if ( ! isset($options['d']) ) {
  echo "SYNTAX: " . $argv[0] . " -d DOMAIN [-a NS[:NS]] [-r NS[:NS]] [-c CONTACT[:CONTACT]] [-o CONTACT[:CONTACT]] [-i AUTHINFO]\n";
  echo "\n";
  echo " -a NS record to add (max. 6)\n";
  echo " -r NS record to remove (max. 6)\n";
  echo " -c technical contact to add (max. 6)\n";
  echo " -o technical contact to remove (max. 6)\n";
  echo " -i set a new authinfo code\n";
  echo "\n";
  exit(1);
}

// set domain name to fetch/update
$name = $options['d'];

// decide whether just to dump domain infos or not
if ( isset($options['a']) ||
     isset($options['r']) ||
     isset($options['c']) ||
     isset($options['o']) ||
     isset($options['i']) ) {
  $fetch_only = FALSE;
} else {
  $fetch_only = TRUE;
}


// this is used when only a domain name was given
function display_domain($domain) {
  echo " - Registrant: " . $domain->get('registrant') . "\n";
  echo " - Admin-C: " . $domain->get('admin') . "\n";
  $tech = $domain->get('tech');
  if ( ! is_array($tech) ) {
    echo " - Tech-C: " . $tech . "\n";
  } else foreach ($tech as $single_tech) {
    echo " - Tech-C: " . $single_tech . "\n";
  }
  echo " - AuthInfo: " . $domain->get('authinfo') . "\n";
  $state = $domain->get('status');
  foreach ( $state as $s )
    echo " - state '" . $s . "'\n";
  $ns = $domain->get('ns');
  foreach ($ns as $name) {
    echo " - NS: " . $name['name'] . "\n";
  }
}

// this is called when updateing the domain
function update_domain($domain, $options) {
  $ns_add = array_slice(split(":", $options['a']), 0, 6);
  $ns_remove = array_slice(split(":", $options['r']), 0, 6);
  $contact_add = array_slice(split(":", $options['c']), 0, 6);
  $contact_remove = array_slice(split(":", $options['o']), 0, 6);
  $authinfo = $options['i'];

  // add NS records
  foreach ($ns_add as $single_ns)
    if ( ! empty($single_ns) ) {
      echo "Adding NS: ".$single_ns."\n";
      $domain->addNS($single_ns);
    }

  // remove NS records
  foreach ($ns_remove as $single_ns)
    if ( ! empty($single_ns) ) {
      echo "Removing NS: ".$single_ns."\n";
      $domain->remNS($single_ns);
    }

  // add technical contact
  foreach ($contact_add as $single_contact)
    if ( ! empty($single_contact) ) {
      echo "Adding TECH-C: ".$single_contact."\n";
      $domain->addTECH($single_contact);
    }

  // remove technical contact
  foreach ($contact_remove as $single_contact)
    if ( ! empty($single_contact) ) {
      echo "Removing TECH-C: ".$single_contact."\n";
      $domain->remTECH($single_contact);
    }

  // set new authinfo
  if ( ! empty($authinfo) )
    $domain->set('authinfo', $authinfo);

  // update domain
  if ( $domain->update() )
    echo "The domain is now up to date.\n";
  else
    echo "Update to the domain FAILED (".$domain->getError().")!\n";
}


// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  echo "Greeting OK.\n";

  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    echo "Login OK.\n";

    // recreate domain object
    $domain = new Net_EPP_IT_Domain($nic, $db);
    $domain->debug = LOG_DEBUG;

    // lookup domain
    switch ( $domain->check($name) ) {
      case TRUE:
        echo "Domain '".$name."' is still available, sorry!\n";
        break;
      case FALSE:
        echo "Domain '".$name."' taken, fetching information...\n";
        if ( $domain->fetch($name) ) {

          // if no update operation was requested, display domain information
          if ( $fetch_only ) {
            display_domain($domain);
          } else {
            update_domain($domain, $options);
          }

        } else {
          echo "Fetch domain FAILED (".$domain->getError().")\n";
        }
        break;
      default:
        echo "Error: '".$name."'.\n";
        break;
    }

    // logout
    if ( $session->logout() ) {
      echo "Logout OK.\n";
    } else {
      echo "Logout FAILED (".$session->getError().").\n";
    }

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}  


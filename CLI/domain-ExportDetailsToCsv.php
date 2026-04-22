#!/usr/bin/php
<?php

error_reporting(E_NOTICE ^ E_ERROR);

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
$options = getopt("f:c:");
if (!isset($options['f']) || !isset($options['c'])) {
  echo "SYNTAX: {$argv[0]} -f TXT_FILE_WITH_DOMAIN_NAMES -c CSV_FILENAME_FOR_EXPORT\n";
  echo "\n";
  echo "  -f filename containing the list of domains to export (one per line)\n";
  echo "  -c filename to export CSV data into\n";
  echo "\n";
  exit(1);
}

$domains_file = $options['f'];
$csv_file = $options['c'];

// retrieve and test command line options
if (file_exists($csv_file)) {
  echo "{$csv_file} already exists!\n";
  exit(2);
}

$domains = [];
$tmp = explode("\n", trim(file_get_contents($domains_file)));
foreach ($tmp as $domain)
  if (strtolower(substr($domain, -3)) == '.it')
    $domains[] = strtolower($domain);
if (count($domains) < 1) {
  echo "No valid .IT domain given in input file!\n";
  exit(3);
}

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    $report = [];
    foreach ($domains as $name) {
      // re-create domain object
      $domain = new Net_EPP_IT_Domain($nic, $db);

      // lookup domain
      switch ($domain->check($name)) {
        case FALSE:
          echo "Domain '{$name}' exists, fetching information...\n";
          if ($domain->fetch($name)) {
            // is array
            $dns = [];
            foreach($domain->get('ns') as $k => $v)
              $dns[] = $k;

            // might be array (only handles the first one)
            $techs = [];
            foreach((array)$domain->get('tech') as $k => $v)
              $techs[] = $k;

            $domdata = [
              "domain" => $name,
              "status" => implode(" / ", $domain->get('status')),
              "created" => $domain->get('crDate'),
              "expires" => $domain->get('exDate'),
              "dns" => implode(", ", $dns),
            ];

            $contact = new Net_EPP_IT_Contact($nic, $db);
            $contact->fetch($domain->get('registrant'));
            $regdata = [
              "registrant" => $contact->get('handle'),
              "rname" => $contact->get('name'),
              "rorg" => $contact->get('org'),
              "rstreet" => $contact->get('street'),
              "rstreet2" => $contact->get('street2'),
              "rstreet3" => $contact->get('street3'),
              "rcity" => $contact->get('city'),
              "rprovince" => $contact->get('province'),
              "rpostalcode" => $contact->get('postalcode'),
              "rcountrycode" => $contact->get('countrycode'),
              "rvoice" => $contact->get('voice'),
              "rfax" => $contact->get('fax'),
              "remail" => $contact->get('email'),
              "rregcode" => $contact->get('regcode'),
            ];

            $contact = new Net_EPP_IT_Contact($nic, $db);
            $contact->fetch($domain->get('admin'));
            $admindata = [
              "admin" => $contact->get('handle'),
              "aname" => $contact->get('name'),
              "aorg" => $contact->get('org'),
              "astreet" => $contact->get('street'),
              "astreet2" => $contact->get('street2'),
              "astreet3" => $contact->get('street3'),
              "acity" => $contact->get('city'),
              "aprovince" => $contact->get('province'),
              "apostalcode" => $contact->get('postalcode'),
              "acountrycode" => $contact->get('countrycode'),
              "avoice" => $contact->get('voice'),
              "afax" => $contact->get('fax'),
              "aemail" => $contact->get('email'),
              "aregcode" => $contact->get('regcode'),
            ];

            $contact = new Net_EPP_IT_Contact($nic, $db);
            $contact->fetch($techs[0]);
            $techdata = [
              "techs" => implode(", ", $techs),
              "tech" => $contact->get('handle'),
              "tname" => $contact->get('name'),
              "torg" => $contact->get('org'),
              "tstreet" => $contact->get('street'),
              "tstreet2" => $contact->get('street2'),
              "tstreet3" => $contact->get('street3'),
              "tcity" => $contact->get('city'),
              "tprovince" => $contact->get('province'),
              "tpostalcode" => $contact->get('postalcode'),
              "tcountrycode" => $contact->get('countrycode'),
              "tvoice" => $contact->get('voice'),
              "tfax" => $contact->get('fax'),
              "temail" => $contact->get('email'),
              "tregcode" => $contact->get('regcode'),
            ];

            $report[] = array_merge($domdata, $regdata, $admindata, $techdata);
          }
          break;
        default:
        case TRUE:
          echo "Domain '{$name}' not found!\n";
          $report[] = [
            "domain" => $name,
            "status" => "(not found)",
            "created" => "",
            "expires" => "",
            "dns" => "",

            "registrant" => "",
            "rname" => "",
            "rorg" => "",
            "rstreet" => "",
            "rstreet2" => "",
            "rstreet3" => "",
            "rcity" => "",
            "rprovince" => "",
            "rpostalcode" => "",
            "rcountrycode" => "",
            "rvoice" => "",
            "rfax" => "",
            "remail" => "",
            "rregcode" => "",

            "admin" => "",
            "aname" => "",
            "aorg" => "",
            "astreet" => "",
            "astreet2" => "",
            "astreet3" => "",
            "acity" => "",
            "aprovince" => "",
            "apostalcode" => "",
            "acountrycode" => "",
            "avoice" => "",
            "afax" => "",
            "aemail" => "",
            "aregcode" => "",

            "techs" => "",
            "tech" => "",
            "tname" => "",
            "torg" => "",
            "tstreet" => "",
            "tstreet2" => "",
            "tstreet3" => "",
            "tcity" => "",
            "tprovince" => "",
            "tpostalcode" => "",
            "tcountrycode" => "",
            "tvoice" => "",
            "tfax" => "",
            "temail" => "",
            "tregcode" => "",
          ];
          break;
      }
    }

    try {
      $fp = fopen($csv_file, 'w');
      fputcsv($fp, array_keys($report[0]), ',', '"', '');
      foreach ($report as $row) {
        fputcsv($fp, array_values($row), ',', '"', '');
      }
      fclose($fp);
    } catch (Exception $e) {
      echo 'Caught exception: ',  $e->getMessage(), "\n";
    }

    // close session
    $session->logout();
  }
}

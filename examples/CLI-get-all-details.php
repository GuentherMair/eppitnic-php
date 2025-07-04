<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

if ( $argc < 2 ) {
  echo "SYNTAX: " . $argv[0] . " CSV-FILE\n";
  exit(1);
}

if (($handle = fopen($argv[1], "r")) === FALSE) {
  echo "CSV-FILE ".$arg[1]." not readable\n";
  exit(2);
}

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';
require_once 'class.csv.php';

$nic = new Net_EPP_Client();
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$contact = new Net_EPP_IT_Contact($nic, $db);
$contact->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

if ( $argc < 2 ) {
  echo "SYNTAX: " . $argv[0] . " CONTACT\n";
  exit(1);
}

$name = $argv[1];

// send "hello"
if ( ! $session->hello() ) {
  echo "Connection FAILED.\n";
  print_r( $session->result );
} else {
  // perform login
  if ( $session->login() === FALSE ) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {
    $details = array();

    $details[] = array(
      '00_domain',
      '01_authinfo',
      '02_ns',
      '03_registrant',
      '04_r_name',
      '05_r_street',
      '06_r_city',
      '07_r_province',
      '08_r_zip',
      '09_r_voice',
      '10_r_fax',
      '11_r_email',
      '12_r_authinfo',
      '13_r_nationalitycode',
      '14_r_entitytype',
      '15_r_regcode',
      '16_r_consentforpublishing',
      '17_admin',
      '18_a_name',
      '19_a_street',
      '20_a_city',
      '21_a_province',
      '22_a_zip',
      '23_a_voice',
      '24_a_fax',
      '25_a_email',
      '26_a_authinfo',
      '27_a_nationalitycode',
      '28_a_entitytype',
      '29_a_regcode',
      '30_a_consentforpublishing',
      '31_tech',
      '32_t_name',
      '33_t_street',
      '34_t_city',
      '35_t_province',
      '36_t_postalcode',
      '37_t_voice',
      '38_t_fax',
      '39_t_email',
      '40_t_authinfo',
      '41_t_nationalitycode',
      '42_t_entitytype',
      '43_t_regcode',
      '44_t_consentforpublishing',
    );

    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
      $name = $data[0];
      echo "{$name}\n";

      if ($domain->fetch($name)) {
        $newelement = array();

        $newelement['00_domain'] = $name;
        $newelement['01_authinfo'] = $domain->get('authinfo');
        $ns_details = $domain->get('ns');
        $ns_names = array();
        foreach ($ns_details as $ns_name => $ns_settings)
          $ns_names[] = $ns_name;
        $newelement['02_ns'] = implode(',', $ns_names);

        $registrant = $domain->get('registrant');
	$contact->fetch($registrant);
        $newelement['03_registrant'] = $registrant;
	$newelement['04_r_name'] = $contact->get('name');
	$newelement['05_r_street'] = $contact->get('street');
	$newelement['06_r_city'] = $contact->get('city');
	$newelement['07_r_province'] = $contact->get('province');
	$newelement['08_r_postalcode'] = $contact->get('postalcode');
	$newelement['09_r_voice'] = $contact->get('voice');
	$newelement['10_r_fax'] = $contact->get('fax');
	$newelement['11_r_email'] = $contact->get('email');
	$newelement['12_r_authinfo'] = $contact->get('authinfo');
	$newelement['13_r_nationalitycode'] = $contact->get('nationalitycode');
	$newelement['14_r_entitytype'] = $contact->get('entitytype');
	$newelement['15_r_regcode'] = $contact->get('regcode');
	$newelement['16_r_consentforpublishing'] = $contact->get('consentforpublishing');

        $admin = $domain->get('admin');
	$contact->fetch($admin);
        $newelement['17_admin'] = $admin;
	$newelement['18_a_name'] = $contact->get('name');
	$newelement['19_a_street'] = $contact->get('street');
	$newelement['20_a_city'] = $contact->get('city');
	$newelement['21_a_province'] = $contact->get('province');
	$newelement['22_a_postalcode'] = $contact->get('postalcode');
	$newelement['23_a_voice'] = $contact->get('voice');
	$newelement['24_a_fax'] = $contact->get('fax');
	$newelement['25_a_email'] = $contact->get('email');
	$newelement['26_a_authinfo'] = $contact->get('authinfo');
	$newelement['27_a_nationalitycode'] = $contact->get('nationalitycode');
	$newelement['28_a_entitytype'] = $contact->get('entitytype');
	$newelement['29_a_regcode'] = $contact->get('regcode');
	$newelement['30_a_consentforpublishing'] = $contact->get('consentforpublishing');

        $t = $domain->get('tech');
        $tech = is_array($t) ? array_shift($t) : $t;
	$contact->fetch($tech);
        $newelement['31_tech'] = $tech;
	$newelement['32_t_name'] = $contact->get('name');
	$newelement['33_t_street'] = $contact->get('street');
	$newelement['34_t_city'] = $contact->get('city');
	$newelement['35_t_province'] = $contact->get('province');
	$newelement['36_t_postalcode'] = $contact->get('postalcode');
	$newelement['37_t_voice'] = $contact->get('voice');
	$newelement['38_t_fax'] = $contact->get('fax');
	$newelement['39_t_email'] = $contact->get('email');
	$newelement['40_t_authinfo'] = $contact->get('authinfo');
	$newelement['41_t_nationalitycode'] = $contact->get('nationalitycode');
	$newelement['42_t_entitytype'] = $contact->get('entitytype');
	$newelement['43_t_regcode'] = $contact->get('regcode');
	$newelement['44_t_consentforpublishing'] = $contact->get('consentforpublishing');

        $details[] = $newelement;
      }
    }

    foreach ($details as $line)
      $csv .= CSV::rowToCSV($line, ";", "\r\n");

    file_put_contents("all-details.csv", $csv);

    // logout
    if ( ! $session->logout() )
      echo "Logout FAILED (".$session->getError().").\n";
  }
}

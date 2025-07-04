<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));
error_reporting(E_ERROR);

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

// retrieve and test command line options
$options = getopt("d:n:r:a:t:");
if ( ! isset($options['d']) ) {
  echo "SYNTAX: " . $argv[0] . " -d DOMAIN [-n NS:NS[:NS] -r REGISTRANT -a ADMIN-C -t TECH-C[:TECH-C]]\n";
  echo "\n";
  echo " -n NS records to add (max. 6)\n";
  echo " -r registrant contact\n";
  echo " -a administrative contact\n";
  echo " -t technical contact\n";
  echo "\n";
  exit(1);
}

// set domain name to restore
$name = $options['d'];
$ns = array_slice(split(":", $options['n']), 0, 6);
$registrant = $options['r'];
$admin = $options['a'];
$tech = array_slice(split(":", $options['t']), 0, 6);

/*
 * build XML config
 */
function buildConfigXML($server = 'epp_server') {
  global $data;

  if (empty($data['db_name'])) {
    $xmlfile = realpath(dirname(__FILE__)."/config.xml");
    $xml = @simplexml_load_file($xmlfile);
    $data['epp_server'] = $xml->server;
    $data['epp_server_deleted'] =  $xml->server_deleted;
    $data['epp_username'] = $xml->username;
    $data['epp_password'] = $xml->password;
    $data['debugfile'] = $xml->debugfile;
    $data['db_type'] = $xml->adodb->dbtype;
    $data['db_host'] = $xml->adodb->dbhost;
    $data['db_name'] = $xml->adodb->dbname;
    $data['db_user'] = $xml->adodb->dbuser;
    $data['db_pwd'] = $xml->adodb->dbpwd;
  }

  return "<config>
    <DEBUG>0</DEBUG>
    <server>".$data[$server]."</server>
    <username>".$data['epp_username']."</username>
    <password>".$data['epp_password']."</password>
    <debugfile>".$data['debugfile']."</debugfile>
    <lang>en</lang>
    <smarty>
      <use_sub_dirs>false</use_sub_dirs>
      <template_dir>".dirname(__FILE__)."/templates/</template_dir>
      <config_dir>".dirname(__FILE__)."/smarty/config/</config_dir>
      <compile_dir>".dirname(__FILE__)."/smarty/compile/</compile_dir>
      <cache_dir>".dirname(__FILE__)."/smarty/cache/</cache_dir>
    </smarty>
    <adodb>
      <dbtype>".$data['db_type']."</dbtype>
      <dbhost>".$data['db_host']."</dbhost>
      <dbname>".$data['db_name']."</dbname>
      <dbuser>".$data['db_user']."</dbuser>
      <dbpwd>".$data['db_pwd']."</dbpwd>
    </adodb>
  </config>";
}

$nic = new Net_EPP_Client(buildConfigXML('epp_server_deleted'));
$db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

// send "hello"
if ( ! $session->hello()) {
  echo "Connection FAILED.\n";
  print_r($session->result);
} else {
  // perform login
  if ($session->login() === FALSE) {
    echo "Login FAILED (".$session->getError().").\n";
  } else {

    // set parameters
    $domain->set('domain', $name);
    $domain->set('registrant', $registrant);
    $domain->set('admin', $admin);
    foreach ($tech as $tmp)
      $domain->addTECH($tmp);
    foreach ($ns as $tmp)
      $domain->addNS($tmp);
    $domain->set('authinfo', substr(rand(), 0, 32));

    // create
    if ($domain->create())
      echo "Domain '".$name."' created.\n";
    else
      echo "Domain '".$name."' NOT created trough epp-deleted.nic.it (code ".$domain->svCode.", '".$domain->svMsg."' / '".$domain->extValueReasonCode."', '".$domain->extValueReason."').\n";

    // logout
    if ( ! $session->logout())
      echo "Logout FAILED (".$session->getError().").\n";

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}

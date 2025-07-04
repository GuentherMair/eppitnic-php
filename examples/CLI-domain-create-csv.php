<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));
error_reporting(E_ERROR);

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

$nic = new Net_EPP_Client(buildConfigXML());
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
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
      $name = $data[0];
      $registrant = $data[1];
      $admin = $data[1];
      $tech = array($data[2]);
      $ns = array($data[3], $data[4]);
      if (isset($data[5]))
        $ns[] = $data[5];

      // lookup domain
      $domain->set('domain', $name);
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
          if ($domain->create()) {
            echo "Domain '".$name."' created trough epp.nic.it (code ".$domain->svCode.", '".$domain->svMsg."'.\n";
          } else {
            echo "Domain '".$name."' NOT created trough epp.nic.it (code ".$domain->svCode.", '".$domain->svMsg."' / '".$domain->extValueReasonCode."', '".$domain->extValueReason."').\n";
            if ((int)$domain->extValueReasonCode == 9078 || (int)$domain->svCode == 2308) {
              echo "Domain '".$name."' is available but needs to be restored through epp-deleted.nic.it.\n";

              // logout old session
              if ( ! $session->logout())
                echo "Verification session logout failed (code ".$session->svCode.", '".$session->svMsg."').\n";

              $nic = new Net_EPP_Client(buildConfigXML('epp_server_deleted'));
              $db = new Net_EPP_IT_StorageDB($nic->EPPCfg->adodb);
              $session = new Net_EPP_IT_Session($nic, $db);
              $domain = new Net_EPP_IT_Domain($nic, $db);

              // send "hello"
              if ( ! $session->hello()) {
                echo "Connection failed.\n";
              } else {
                // perform login
                if ($session->login() === FALSE) {
                  echo "Login failed (code ".$session->svCode.", '".$session->svMsg."').\n";
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
                    echo "Domain '".$name."' created trough epp-deleted.nic.it (code ".$domain->svCode.", '".$domain->svMsg."'.\n";
                  else
                    echo "Domain '".$name."' NOT created (code ".$domain->svCode.", '".$domain->svMsg."' / '".$domain->extValueReasonCode."', '".$domain->extValueReason."').\n";
                }
              }
            } else {
              echo "Domain '".$name."' NOT created (code ".$domain->svCode.", '".$domain->svMsg."' / '".$domain->extValueReasonCode."', '".$domain->extValueReason."').\n";
            }
          }
          break;
        case FALSE:
          if ($domain->restore($name))
            echo "Restore domain '".$name."' succeeded.\n";
          else
            echo "Restore domain '".$name."' FAILED (".$domain->getError().")!\n";
          break;
        default:
          echo "Error: '".$name."' (".$domain->getError().").\n";
          break;
      }
    }
    fclose($handle);

    // logout
    if ( ! $session->logout())
      echo "Logout FAILED (".$session->getError().").\n";

    // print credit
    echo "Your credit: ".sprintf("%.2f", $session->showCredit())." EUR\n";
  }
}

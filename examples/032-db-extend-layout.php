<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

/**
 * This class is an sample extension of the storage driver provided with the
 * library. It shows how to alter the handling of a field of type array.
 */
class MyStorageWrapper extends Net_EPP_StorageDB
{
  function __construct($cfg) {
    parent::__construct($cfg);
  }

  /**
   * these functions override the core methods and separate the tech-array into single fields
   */
  protected function doStore($table, $elements, $userid = 1) {
    if ( ! is_array($elements) )
      return $this->error(4, "second paramenter must be an array!");

    $keys = array();
    $values = array();
    foreach ($elements as $k => $v) {
      $keys[] = $k;
      if ($k == "tech") {
        $values[":{$k}"] = array_shift($v);
        $i = 2;
        foreach ($v as $remaining) {
          $keys[] = $k . $i;
          $values[":{$k}{$i}"] = $remaining;
          $i++;
        }
      } else if (($k == "clTRData") || ($k == "svHTTPData") || is_array($v)) {
        $values[":{$k}"] = $this->dbSerializePrefix.base64_encode(serialize($v));
      } else if (($k == 'crDate') || ($k == 'exDate')) {
        $values[":{$k}"] = date("Y-m-d", strtotime($v));
      } else {
        $values[":{$k}"] = $v;
      }
    }

    // ACL
    if ($userid > 1 && in_array($table, $this->tablesWithACL)) {
      $keys[] = "userID";
      $values[":userID"] = $userid;
    }

    // execute query
    try {
      $stmt = $this->db->prepare("INSERT INTO {$table} (".implode(", ", $keys).") VALUES (".implode(', ', array_keys($values)).")");
      if ($stmt->execute($values)) {
        return $this->setError(0, "information stored to '{$table}'");
      } else {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to store given data to '{$table}': {$errorInfo[2]}");
      }
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to store given data to '{$table}': " . $e->getMessage());
    }
  }

  /**
   * these functions override the core methods and separate the tech-array into single fields
   */
  protected function doUpdate($table, $elements, $index, $handle, $userid = 1) {
    if ( ! is_array($elements))
      return $this->error(4, "second paramenter must be an array!");

    $keys = array();
    $values = array();
    foreach ($elements as $k => $v) {
      $keys[] = $k;
      if ($k == "tech") {
        $values[":{$k}"] = array_shift($v);
        $i = 2;
        foreach ($v as $remaining) {
          $keys[] = $k . $i;
          $values[":{$k}{$i}"] = $remaining;
          $i++;
        }
      } else if (($k == "clTRData") || ($k == "svHTTPData") || is_array($v)) {
        $values[":{$k}"] = $this->dbSerializePrefix.base64_encode(serialize($v));
      } else if (($k == 'crDate') || ($k == 'exDate')) {
        $values[":{$k}"] = date("Y-m-d", strtotime($v));
      } else {
        $values[":{$k}"] = $v;
      }
    }

    $wKeys = array("{$index}=:handle");
    $values[":handle"] = $handle;

    // ACL
    if ($userid > 1 && in_array($table, $this->tablesWithACL)) {
      $wKeys[] = "userID=:userID";
      $values[":userID"] = $userid;
    }

    // execute query
    try {
      $stmt = $this->db->prepare("UPDATE {$table} set ".implode(", ", $keys)." WHERE ".implode(" AND ", $wKeys));
      if ($stmt->execute($values)) {
        return $this->setError(0, "updated '{$table}' with INDEX {$index}='{$handle}'");
      } else {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to store given data to '{$table}': {$errorInfo[2]}");
      }
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to store given data to '{$table}': " . $e->getMessage());
    }

    // an error occurred
    $e = $stmt->errorInfo();
    return $this->setError($e[1], "{$e[0]}: unable to update '{$table}' using INDEX {$index}='{$handle}' and user ID {$userid}: {$e[2]}");
  }

  /**
   * these functions override the core methods and separate the tech-array into single fields
   */
  protected function doRetrieve($table, $index, $value, $strict = TRUE, $order = null, $userid = 1) {
    $elements = parent::doRetrieve($table, $index, $value, $strict = TRUE, $order = null, $userid = 1);
    print_r($elements);
  }
}




$nic = new Net_EPP_Client();
$db = new MyStorageWrapper($nic->EPPCfg->db);
$session = new Net_EPP_IT_Session($nic, $db);
$session->debug = LOG_DEBUG;
$domain = new Net_EPP_IT_Domain($nic, $db);
$domain->debug = LOG_DEBUG;

if ($argc < 2) {
  echo "SYNTAX: {$argv[0]} DOMAIN\n";
  echo "\n";
  echo "ATTENTION!\n";
  echo " In order to use this example you will have to extend your current\n";
  echo " DB schema by adding fields tech2 - tech6 like this:\n";
  echo "\n";
  echo " alter table tbl_domains add column tech2 varchar(32);\n";
  echo " alter table tbl_domains add column tech3 varchar(32);\n";
  echo " alter table tbl_domains add column tech4 varchar(32);\n";
  echo " alter table tbl_domains add column tech5 varchar(32);\n";
  echo " alter table tbl_domains add column tech6 varchar(32);\n";
  echo "\n";
  exit(1);
}

$name = $argv[1];

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

    // lookup domain
    switch ($domain->check($name)) {
      case TRUE:
        echo "Domain '{$name}' is still available, sorry!\n";
        break;
      case FALSE:
        echo "Domain '{$name}' not available, fetching information...\n";
        if ($domain->fetch($name)) {
          // dump some information about the domain we just fetched
          echo " - Registrant: " . $domain->get('registrant') . "\n";
          echo " - Admin-C: " . $domain->get('admin') . "\n";
          $tech = $domain->get('tech');
          if ( ! is_array($tech)) {
            echo " - Tech-C: {$tech}\n";
          } else foreach ($tech as $single_tech) {
            echo " - Tech-C: {$single_tech}\n";
          }
          echo " - AuthInfo: " . $domain->get('authinfo') . "\n";
          $state = $domain->get('status');
          foreach ($state as $s)
            echo " - state '{$s}'\n";
          $ns = $domain->get('ns');
          foreach ($ns as $name)
            echo " - NS: {$name['name']}\n";

          // now make use of our new methods
          echo "\n";
          echo "Performing DB operations:\n";
          echo $domain->storeDB();
          echo $domain->loadDB();
          $domain->addTECH('XYZ');
          echo $domain->updateDB();
          echo $domain->loadDB();
        } else {
          echo "FAILED (".$domain->getError().").\n";
        }
        break;
      default:
        echo "Error: '{$name}' (".$domain->getError().").\n";
        break;
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

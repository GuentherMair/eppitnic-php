<?php

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'Net/EPP/Client.php';
require_once 'Net/EPP/StorageDB.php';

/**
 * This class is an sample extension of the storage driver provided with the
 * library. It shows how to add an alternative, public "myRetrieve" method.
 */
class MyStorageWrapper extends Net_EPP_StorageDB
{
  function __construct($cfg) {
    parent::__construct($cfg);
  }

  /**
   * retrieve information from DB - this just wraps around the protected doRetrieve method
   *
   * @access   protected
   * @param    string    the table to retrieve information from
   * @param    string    the column to look at
   * @param    string    the value to look up
   * @param    boolean   whether or not to use a strict comparison between for value on column
   * @param    string    parameter to SQLs "ORDER BY"-clause (ie. "id DESC") - modify at own risk!
   * @return   array     results OR FALSE in case of failure
   */
  public function myRetrieve($table, $index, $value, $strict = TRUE, $order = null) {
    return $this->doRetrieve($table, $index, $value, $strict, $order);
  }
}

$nic = new Net_EPP_Client();
$db = new MyStorageWrapper($nic->EPPCfg->db);

$db->setDBMaxEntries(10);
$data_array = $db->myRetrieve("tbl_transactions", "clTRType", "check-%", FALSE, "clTRType ASC, id DESC");
echo count($data_array) . " elements found:\n";
foreach ( $data_array as $values ) {
  $oldbug = is_array($values['clTRObject']) ? implode(";", $values['clTRObject']) : $values['clTRObject'];
  printf("ID [%05d] - clTRID [%s] - %s '%s'\n", $values['id'], $values['clTRID'], $values['clTRType'], $oldbug);
}

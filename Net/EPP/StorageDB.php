<?php

require_once 'Net/EPP/StorageInterface.php';

/**
 * A simple class handling the EPP registration storage in a database.
 *
 * PHP version 5.3
 *
 * LICENSE:
 *
 * Copyright (c) 2009-2017, Günther Mair <info@inet-services.it>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1) Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2) Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3) Neither the name of Günther Mair nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category    Net
 * @package     Net_EPP_StorageDB
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id$
 */
class Net_EPP_StorageDB implements Net_EPP_StorageInterface
{
  public    $db;

  public    $dbMaxEntries      = 50;
  public    $dbSerializePrefix = "__SERIALIZED:";
  public    $dbMagicQuotes     = TRUE;
  public    $dbForceQuotes     = FALSE;

  protected $dberrCode         = 0;
  protected $dberrMsg          = "";
  protected $tablesWithACL     = array('tbl_contacts', 'tbl_domains', 'tbl_transfers');

  /**
   * Class constructor
   *
   *  - initialize database connection
   *
   * @access   public
   * @param    array  db connection paramenters (dbtype, dbhost, dbuser, dbpwd, dbname)
   */
  function __construct($cfg) {
    try {
      $this->db = new PDO("{$cfg->dbtype}:host={$cfg->dbhost};dbname={$cfg->dbname};charset=utf8", $cfg->dbuser, $cfg->dbpwd);
      $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
    } catch (PDOException $e) {
      die("Unable to connect to database '{$cfg->dbname}' on '{$cfg->dbhost}' with user '{$cfg->dbuser}': ".$e->getMessage());
      //return $this->setError(1, "unable to connect to database '{$cfg->dbname}' on '{$cfg->dbhost}' with user '{$cfg->dbuser}': ".$e->getMessage());
    }

    if ( ! is_object($this->db))
      die("Unable to connect to database '{$cfg->dbname}' on '{$cfg->dbhost}' with user '{$cfg->dbuser}' - connection request did not return a PDO Object.");
  }

  /**
   * set internal error code and message
   *
   * @access   protected
   * @param    int       error number
   * @param    string    error message
   * @return   boolean   status
   */
  protected function setError($errno, $error) {
    $this->dberrCode = $errno;
    $this->dberrMsg = $error;
    return ($errno == 0) ? TRUE : FALSE;
  }

  /**
   * get internal error message
   *
   * @access   public
   * @return   string    error message
   */
  public function getError() {
    return $this->dberrMsg;
  }

  /**
   * get internal error code
   *
   * @access   protected
   * @return   integer   error code
   */
  protected function getErrorCode() {
    return $this->dberrCode;
  }

  /**
   * store data to DB
   *
   * @access   protected
   * @param    string    information store (tbl_transactions, tbl_responses, tbl_msgqueue, ...)
   * @param    array     information to be stored
   * @param    string    user ACL
   * @return   boolean   status
   */
  protected function doStore($table, $elements, $userid = 1) {
    if ( ! is_array($elements))
      return $this->setError(4, "second paramenter must be an array!");

    $keys = array();
    $values = array();
    foreach ($elements as $k => $v) {
      $keys[] = $k;
      if (($k == "clTRData") || ($k == "svHTTPData") || is_array($v)) {
        $values[":{$k}"] = $this->dbSerializePrefix.base64_encode(serialize($v));
      } else if (($k == 'crDate') || ($k == 'exDate')) {
        $values[":{$k}"] = date("Y-m-d", strtotime($v));
      } else {
        $values[":{$k}"] = $v;
      }
    }

    // ACL settings
    if ($userid > 1 && in_array($table, $this->tablesWithACL)) {
      $keys[] = "userID";
      $values[":userID"] = $userid;
    }

    // execute query
    try {
      $stmt = $this->db->prepare("INSERT INTO {$table} (".implode(", ", $keys).") VALUES (".implode(', ', array_keys($values)).")");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to store given data to '{$table}': {$errorInfo[2]}");
      }

      return $this->setError(0, "information stored to '{$table}'");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to store given data to '{$table}': " . $e->getMessage());
    }
  }

  /**
   * update data in DB
   *
   * @access   protected
   * @param    string    information store (tbl_transactions, tbl_responses, tbl_msgqueue, ...)
   * @param    array     information to be stored
   * @param    string    the column to look at
   * @param    string    the value to look up
   * @param    string    user ACL
   * @return   boolean   status
   */
  protected function doUpdate($table, $elements, $index, $handle, $userid = 1) {
    if ( ! is_array($elements))
      return $this->setError(4, "second paramenter must be an array!");

    $keys = array();
    $values = array();
    foreach ($elements as $k => $v) {
      $keys[] = "{$k}=:{$k}";
      if (($k == "clTRData") || ($k == "svHTTPData") || is_array($v)) {
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
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to update '{$table}' using INDEX {$index}='{$handle}' and user ID {$userid}: {$errorInfo[2]}");
      }

      return $this->setError(0, "updated '{$table}' with INDEX {$index}='{$handle}'");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to update '{$table}' using INDEX {$index}='{$handle}' and user ID {$userid}: " . $e->getMessage());
    }
  }

  /**
   * store transaction data to DB (doStore wrapper)
   *
   * @access   public
   * @param    string    client transaction ID
   * @param    string    client transaction type
   * @param    string    client transaction data
   * @return   boolean   status
   */
  public function storeTransaction($clTRID, $clTRType, $clTRObject, $clTRData) {
    return $this->doStore("tbl_transactions",
      array("clTRID"        => $clTRID,
            "clTRType"      => $clTRType,
            "clTRObject"    => $clTRObject,
            "clTRData"      => $clTRData));
  }

  /**
   * store answers from EPP server to DB (doStore wrapper)
   *
   * @access   protected
   * @param    string    client transaction ID
   * @param    string    server transaction ID
   * @param    string    server EPP response code
   * @param    string    status flag (should be "0" for initialization)
   * @param    array     server HTTP response code, headers and body
   * @param    string    table name
   * @param    string    extended server error code (optional)
   * @param    string    extended server error message (optional)
   * @return   boolean   status
   */
  protected function storeAnswer($clTRID, $svTRID, $svEPPCode, $status, $response, $table, $extValueReasonCode = "", $extValueReason = "") {
    return $this->doStore($table,
      array("clTRID"             => $clTRID,
            "svTRID"             => $svTRID,
            "svEPPCode"          => $svEPPCode,
            "status"             => $status,
            "svHTTPCode"         => $response['code'],
            "svHTTPHeaders"      => $response['headers'],
            "svHTTPData"         => $response['body'],
            "extValueReasonCode" => $extValueReasonCode,
            "extValueReason"     => $extValueReason));
  }

  /**
   * store responses to DB (doStore wrapper)
   *
   * @access   public
   * @param    string    client transaction ID
   * @param    string    server transaction ID
   * @param    string    server EPP response code
   * @param    string    status flag (should be "0" for initialization)
   * @param    array     server HTTP response code, headers and body
   * @return   boolean   status
   */
  public function storeResponse($clTRID, $svTRID, $svCode, $status, $response, $extValueReasonCode, $extValueReason) {
    return $this->storeAnswer($clTRID, $svTRID, $svCode, $status, $response, "tbl_responses", $extValueReasonCode, $extValueReason);
  }

  /**
   * store message (poll) data to DB (doStore wrapper)
   *
   * @access   public
   * @param    string    client transaction ID
   * @param    string    server transaction ID
   * @param    string    server EPP response code
   * @param    string    status flag (should be "0" for initialization)
   * @param    array     server HTTP response code, headers and body
   * @return   boolean   status
   */
  public function storeMessage($clTRID, $svTRID, $svCode, $status, $response) {
    return $this->doStore("tbl_msgqueue",
      array("clTRID"             => $clTRID,
            "svTRID"             => $svTRID,
            "status"             => $status,
            "svHTTPCode"         => $response['code'],
            "svHTTPHeaders"      => $response['headers'],
            "svHTTPData"         => $response['body']));
  }

  /**
   * store parsed messages (poll) to DB
   *
   * @access   public
   * @param    array     data to be stored
   * @return   boolean   status
   */
  public function storeParsedMessage($elements) {
    return $this->doStore("tbl_messages", $elements);
  }

  /**
   * store contact to DB
   *
   * @access   public
   * @param    array     contact information to be stored
   * @return   boolean   status
   */
  public function storeContact($elements, $userid = 1) {
    return $this->doStore("tbl_contacts", $elements, $userid);
  }

  /**
   * store domain to DB
   *
   * @access   public
   * @param    array     domain information to be stored
   * @return   boolean   status
   */
  public function storeDomain($elements, $userid = 1) {
    return $this->doStore("tbl_domains", $elements, $userid);
  }

  /**
   * update stored contact in DB
   *
   * @access   public
   * @param    array     contact information to be updated
   * @param    string    contact to be updated
   * @param    string    user ACL
   * @return   boolean   status
   */
  public function updateContact($elements, $contact, $userid = 1) {
    return $this->doUpdate("tbl_contacts", $elements, "handle", $contact, $userid);
  }

  /**
   * update stored domain in DB
   *
   * @access   public
   * @param    array     domain information to be updated
   * @param    string    domain to be updated
   * @param    string    user ACL
   * @return   boolean   status
   */
  public function updateDomain($elements, $domain, $userid = 1) {
    return $this->doUpdate("tbl_domains", $elements, "domain", $domain, $userid);
  }

  /**
   * set the maximum value for dbMaxEntries (default: 50)
   * Use 0 for no limit!
   *
   * @access   public
   * @param    integer   the maximum value for dbMaxEntries
   */
  public function setDBMaxEntries($dbMaxEntries) {
    if ((int)$dbMaxEntries < 0)
      $dbMaxEntries = 0;
    return $this->dbMaxEntries = (int)$dbMaxEntries;
  }

  /**
   * retrieve information from DB
   *
   * @access   protected
   * @param    string    the table to retrieve information from
   * @param    string    the column to look at
   * @param    string    the value to look up
   * @param    boolean   whether or not to use a strict comparison between for value on column
   * @param    string    parameter to SQLs "ORDER BY"-clause (ie. "id DESC") - modify at own risk!
   * @param    string    user ACL
   * @return   array     results OR FALSE in case of failure
   */
  protected function doRetrieve($table, $index, $value, $strict = TRUE, $order = null, $userid = 1) {
    $keys = array();
    $values = array();
    if ($value === null) {
      $keys[] = "1 = 1";
    } else if ($strict === TRUE) {
      $keys[] = "{$index}=:index";
      $values[":index"] = $value;
    } else {
      $keys[] = "{$index} LIKE :index";
      $values[":index"] = "%{$value}%";
    }

    // ACL settings
    if ($userid > 1 && in_array($table, $this->tablesWithACL)) {
      $keys[] = "userID=:userID";
      $values[":userID"] = $userid;
    }

    // sort order
    if ($order === null)
      $order = "id DESC";

    // limit amount of entries retrieved
    $limit = ($this->dbMaxEntries == 0) ? "" : " LIMIT {$this->dbMaxEntries}";

    // execute query
    try {
      $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE ".implode(' AND ', $keys)." ORDER BY {$order}{$limit}");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get data from '{$table}': {$errorInfo[2]}");
      }

      // construct numbered return array
      $elements = array();
      $prefix_length = strlen($this->dbSerializePrefix);
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $newRow = array();
        foreach ($row as $c => $v) {
          $newRow[$c] = (substr($v, 0, $prefix_length) == $this->dbSerializePrefix)
            ? unserialize(base64_decode(substr($v, $prefix_length)))
            : $v;
        }
        $elements[] = $newRow;
      }
      return $elements;
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get data from '{$table}': " . $e->getMessage());
    }
  }

  /**
   * retrieve transactions from DB
   *
   * @access   public
   * @param    string    optional transaction ID to look up
   * @return   array     results OR FALSE in case of failure
   */
  public function retrieveTransaction($clTRID = null) {
    return $this->doRetrieve("tbl_transactions", "clTRID", $clTRID);
  }
 
  /**
   * retrieve responses from DB
   *
   * @access   public
   * @param    string    optional transaction ID to look up
   * @return   array     results OR FALSE in case of failure
   */
  public function retrieveResponse($clTRID = null) {
    return $this->doRetrieve("tbl_responses", "clTRID", $clTRID);
  }
 
  /**
   * retrieve messages from DB
   *
   * @access   public
   * @param    string    optional transaction ID to look up
   * @return   array     results OR FALSE in case of failure
   */
  public function retrieveMessage($clTRID = null) {
    return $this->doRetrieve("tbl_msgqueue", "clTRID", $clTRID);
  }

  /**
   * archive a parsed message (ie. for automated transfer handling like
   * clientRejected, clientApproved, serverApproved)
   *
   * @access   public
   * @param    integer   ID of the parsed message
   * @return   boolean   status
   */
  public function archiveParsedMessage($id) {
    try {
      $stmt = $this->db->prepare("UPDATE tbl_messages SET archived = 1 WHERE id=:id");
      if ( ! $stmt->execute(array(":id" => $id))) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to update message table: {$errorInfo[2]}");
      }

      return $this->setError(0, "message table update");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to update message table: " . $e->getMessage());
    }
  }

  /**
   * retrieve parsed messages from DB
   *
   * @access   public
   * @param    boolean   whether to retrieve all or only active messages
   * @param    string    user ACL
   * @return   boolean   status
   */
  public function retrieveParsedMessages($active = true, $userid = 1) {
    // set conditions (archived or not / user ACL)
    $conditions = $active ? "t.archived = 0" : "1 = 1";
    $conditions .= ($userid > 1) ? (" AND d.userid = ".(int)$userid) : "";

    try {
      // execute query
      $stmt = $this->db->prepare("
        SELECT
          t.*,
          DATE_FORMAT('createdTime', '%d-%m-%Y') AS date,
          d.userid,
          d.registrant,
          u.billingID
        FROM
          tbl_messages t
        LEFT JOIN
          tbl_domains d
        ON
          t.domain = d.domain
        LEFT JOIN
          tbl_users u
        ON
          d.userid = u.id
        WHERE
          {$conditions}
        ORDER BY id DESC");

      // construct numbered return array
      if ( ! $stmt->execute()) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get data from tbl_messages: {$errorInfo[2]}");
      }

      $elements = array();
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
        $elements[] = $row;
      return $elements;
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get data from tbl_messages: " . $e->getMessage());
    }
  }

  /**
   * retrieve a contact from DB
   *
   * @access   public
   * @param    string    contact to look up
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function retrieveContact($contact, $userid = 1) {
    $tmp = $this->doRetrieve("tbl_contacts", "handle", $contact, TRUE, null, $userid);
    if (($tmp === FALSE) || (count($tmp) <> 1))
      return FALSE;
    else
      return $tmp[0];
  }

  /**
   * retrieve a domain from DB
   *
   * @access   public
   * @param    string    domain to look up
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function retrieveDomain($domain, $userid = 1) {
    $tmp = $this->doRetrieve("tbl_domains", "domain", $domain, TRUE, null, $userid);
    if (($tmp === FALSE) || (count($tmp) <> 1))
      return FALSE;
    else
      return $tmp[0];
  }

  /**
   * class destructor
   */
  function __destruct() {
    // nothing to close - waiting for PDO to cleanup connections on its own
  }
}

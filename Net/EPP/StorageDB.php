<?php

require_once 'Net/EPP/StorageInterface.php';

/**
 * A simple class handling the EPP registration storage in a database.
 *
 * LICENSE:
 *
 * Copyright (c) Günther Mair <info@inet-services.it>
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
   * class destructor
   */
  function __destruct() {
    // nothing to close - waiting for PDO to cleanup connections on its own
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
   * list contacts stored in DB
   *
   * @access   public
   * @param    string    user ACL
   * @param    boolean   restrict search to active contacts (default TRUE)
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function listContacts($userid = 1, $activeOnly = TRUE) {
    $keys = array("1 = 1");
    $values = array();

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
    }

    // list only active contacts?
    if ($activeOnly === TRUE) {
      $keys[] = "active = :active";
      $values[":active"] = 1;
    }

    try {
      // execute query
      $stmt = $this->db->prepare("SELECT handle, org, name, userid FROM tbl_contacts WHERE ".implode(' AND ', $keys)." ORDER BY org, name ASC");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to list contacts: {$errorInfo[2]}");
      }
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to list contacts: " . $e->getMessage());
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * list users stored in DB
   *
   * @access   public
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function listUsers() {
    try {
      // execute query
      $stmt = $this->db->prepare("
        SELECT
          u.id,
          u.username,
          u.description,
          d.num_domains,
          c.num_contacts
        FROM
          tbl_users u
        LEFT JOIN
          (SELECT userID, count(userID) AS num_domains FROM tbl_domains GROUP BY userID) d
        ON
          u.id = d.userID
        LEFT JOIN
          (SELECT userID, count(userID) AS num_contacts FROM tbl_contacts GROUP BY userID) c
        ON
          u.id = c.userID
        ORDER BY
          username ASC");
      if ( ! $stmt->execute()) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to list users: {$errorInfo[2]}");
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to list users: " . $e->getMessage());
    }
  }

  /**
   * retrieve user from DB (with some more details)
   *
   * @access   public
   * @param    integer   userid
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function retrieveUser($userid) {
    try {
      // execute query
      $stmt = $this->db->prepare("
        SELECT
          u.*,
          d.num_domains,
          c.num_contacts
        FROM
          tbl_users u
        LEFT JOIN
          (SELECT userID, count(userID) AS num_domains FROM tbl_domains GROUP BY userID) d
        ON
          u.id = d.userID
        LEFT JOIN
          (SELECT userID, count(userID) AS num_contacts FROM tbl_contacts GROUP BY userID) c
        ON
          u.id = c.userID
        WHERE
          u.id = :userid");
      if ( ! $stmt->execute(array(":userid" => $userid))) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get user: {$errorInfo[2]}");
      }

      return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get user: " . $e->getMessage());
    }
  }

  /**
   * add user to DB
   *
   * @access   public
   * @param    integer   userid
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function storeUser($elements) {
    return $this->doStore("tbl_users", $elements);
  }

  /**
   * add user to DB
   *
   * @access   public
   * @param    integer   userid
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function updateUser($elements, $userid) {
    return $this->doUpdate("tbl_users", $elements, "id", $userid);
  }

  /**
   * delete user from DB
   *
   * @access   public
   * @param    integer   userid
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function deleteUser($userid) {
    try {
      // verify integrity (query counts)
      $stmt = $this->db->prepare("
        SELECT
          d.num_domains,
          c.num_contacts
        FROM
         (SELECT count(*) AS num_domains FROM tbl_domains WHERE userID = :userid) d,
         (SELECT count(*) AS num_contacts FROM tbl_contacts WHERE userID = :userid2) c");
      if ( ! $stmt->execute(array(":userid" => $userid, ":userid2" => $userid))) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get count of associated objects: {$errorInfo[2]}");
      }

      // verify integrity (check counts)
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($row['num_domains'] > 0 || $row['num_contacts'] > 0)
        return $this->setError(16, "unable to remove user with id '{$userid}': remove associated objects first ({$row['num_domains']} domains, {$row['num_contacts']} contacts)");

      // remove entry
      $stmt = $this->db->prepare("DELETE FROM tbl_users WHERE id = :userid");
      if ( ! $stmt->execute(array(":userid" => $userid))) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to delete user: {$errorInfo[2]}");
      }

      return TRUE;
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get user: " . $e->getMessage());
    }
  }

  /**
   * list domains stored in DB
   *
   * @access   public
   * @param    string    user ACL
   * @param    string    restrict search to this registrant
   * @param    boolean   restrict search to active domains (default TRUE)
   * @param    integer   restrict search to domains older then X months
   * @param    boolean   include Transfer-In domains (default TRUE)
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function listDomains($userid = 1, $registrant = null, $activeOnly = TRUE, $age = 0, $transferin = TRUE) {
    $domains = array();
    $keys = array("1 = 1");
    $values = array();

    try {
      // ACL settings
      if ($userid > 1) {
        $keys[] = "userID = :userID";
        $values[":userID"] = $userid;
      }

      // restrict search to a specific registrant
      if ( ! is_null($registrant)) {
        $keys[] = "registrant = :registrant";
        $values[":registrant"] = $registrant;
      }

      // execute query for domains in transfer-in (active-contact & age restrictions are NOT applied!)
      if ($transferin) {
        $stmt = $this->db->prepare("
          SELECT
            concat(domain, ' (transfer-in)') as domain,
            registrant,
            userid
          FROM
            tbl_transfers
          WHERE
            ".implode(' AND ', $keys)."
          ORDER BY
            domain ASC");
        if ( ! $stmt->execute($values)) {
          $errorInfo = $stmt->errorInfo();
          return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to list domains: {$errorInfo[2]}");
        }

        // fetch results
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
      }

      // list only active contacts?
      if ($activeOnly === TRUE) {
        $keys[] = "active = :active";
        $values[":active"] = 1;
      }

      // list only domains older then X months?
      if ($age > 0) {
        $keys[] = "exDate < DATE_SUB(CURDATE(), INTERVAL :age MONTH)";
        $values[":age"] = (int)$age;
      }

      // execute query for active domains
      $stmt = $this->db->prepare("
        SELECT
          domain,
          registrant,
          userid
        FROM
          tbl_domains
        WHERE
          ".implode(' AND ', $keys)."
        ORDER BY
          domain ASC");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to list domains: {$errorInfo[2]}");
      }

      return array_merge($domains, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to list domains: " . $e->getMessage());
    }
  }

  /**
   * set active state of a contact stored in DB to 0
   *
   * @access   public
   * @param    string    contact name
   * @param    string    user ACL
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function deleteContact($contact, $userid = 1) {
    $keys = array("handle = :handle");
    $values = array(":handle" => $contact);

    $keysSubQuery = array("registrant = :registrant");
    $valuesSubQuery = array(":registrant" => $contact);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
      $keysSubQuery[] = "userID = :userID";
      $valuesSubQuery[":userID"] = $userid;
    }

    try {
      // execute query
      $stmt = $this->db->prepare("
        UPDATE
          tbl_contacts
        SET
          active = 0
        WHERE
          ".implode(' AND ', $keys)." AND
          (SELECT COUNT(1) FROM tbl_domains WHERE ".implode(' AND ', $keysSubQuery)." AND active = 1) = 0");
      if ( ! $stmt->execute(array_merge($values, $valuesSubQuery))) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to deactivate contact {$contact}: {$errorInfo[2]}");
      }

      return $this->setError(0, "deactivated contact {$contact}");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to deactivate contact {$contact}: " . $e->getMessage());
    }
  }

  /**
   * set active state of a contact stored in DB to 1
   *
   * @access   public
   * @param    string    contact name
   * @param    string    user ACL
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function restoreContact($contact, $userid = 1) {
    $keys = array("handle = :handle");
    $values = array(":handle" => $contact);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // execute query
      $stmt = $this->db->prepare("
        UPDATE
          tbl_contacts
        SET
          active = 1
        WHERE
          ".implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to activate contact {$contact}: {$errorInfo[2]}");
      }

      return $this->setError(0, "activated contact {$contact}");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to activate contact {$contact}: " . $e->getMessage());
    }
  }

  /**
   * set active state of a domain stored in DB to 0
   *
   * @access   public
   * @param    string    domain name
   * @param    string    user ACL
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function deleteDomain($domain, $userid = 1) {
    $keys = array("domain = :domain");
    $values = array(":domain" => $domain);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // execute query
      $stmt = $this->db->prepare("
        UPDATE
          tbl_domains
        SET
          active = 0
        WHERE
          ".implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to deactivate domain {$domain}: {$errorInfo[2]}");
      }

      return $this->setError(0, "deactivated domain {$domain}");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to deactivate domain {$domain}: " . $e->getMessage());
    }
  }

  /**
   * set active state of a domain stored in DB to 1
   *
   * @access   public
   * @param    string    domain name
   * @param    string    user ACL
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function restoreDomain($domain, $userid = 1) {
    $keys = array("domain = :domain");
    $values = array(":domain" => $domain);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // execute query
      $stmt = $this->db->prepare("
        UPDATE
          tbl_domains
        SET
          active = 1
        WHERE
          ".implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to activate domain {$domain}: {$errorInfo[2]}");
      }

      return $this->setError(0, "activated domain {$domain}");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to activate domain: " . $e->getMessage());
    }
  }

  /**
   * update lastInvoice date of a domain stored in DB
   *
   * there is no need for user ACLs since this method should obviously only
   * be called by an automated cron job
   *
   * @access   public
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function renewDomains() {
    try {
      // execute query
      $stmt = $this->db->prepare("
        UPDATE
          tbl_domains
        SET
          lastInvoice = CURDATE()
        WHERE
          active = 1 AND
          lastInvoice < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)");
      if ( ! $stmt->execute()) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to renew domains: {$errorInfo[2]}");
      }

      return $this->setError(0, "all domains renewed");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to renew domains: " . $e->getMessage());
    }
  }

  /**
   * load list of domains from DB which have last been invoiced a year ago
   *
   * there is no need for user ACLs since this method should obviously only
   * be called by an automated cron job
   *
   * @access   public
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function invoiceableDomains() {
    try {
      // execute query for active domains
      $stmt = $this->db->prepare("
        SELECT
          d.domain AS name,
          u.billingID
        FROM
          tbl_domains d,
          tbl_users u
        WHERE
          d.active = 1 AND
          d.lastInvoice < DATE_SUB(CURDATE(), INTERVAL 1 YEAR) AND
          d.userid = u.id");
      if ( ! $stmt->execute()) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to list domains: {$errorInfo[2]}");
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to list domains: " . $e->getMessage());
    }
  }

  /**
   * store ccounting data in DB
   *
   * @access   public
   * @param    string    operation type
   * @param    string    billingID (client reference number)
   * @param    string    object being invoiced
   * @param    string    date string / invoice period (optional - this defaults to a ISO 8601 date)
   * @return   boolean   status
   */
  public function doAccount($operation, $billingID, $object, $date = NULL) {
    $elements = array();
    $elements['operation'] = $operation;
    $elements['billingID'] = $billingID;
    $elements['object'] = $object;
    $elements['date'] = is_null($date) ? date('c') : $date;

    return $this->doStore('tbl_accounting', $elements);
  }

  /**
   * retrieve accountable services from DB
   *
   * @access   public
   * @return   array    accountable services
   */
  public function accountableServices() {
    try {
      // execute query for active domains
      $stmt = $this->db->prepare("SELECT id, operation, billingID, object, date, time FROM tbl_accounting WHERE status = 0 ORDER BY id DESC");
      if ( ! $stmt->execute()) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to list domains: {$errorInfo[2]}");
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to list domains: " . $e->getMessage());
    }
  }

  /**
   * close accountable services in DB (status = 1)
   *
   * @access   public
   * @param    array    accountable services
   * @return   boolean  status
   */
  public function closeAccountableServices($records) {
    if ( ! is_array($records))
      return FALSE;

    // look for the highest ID
    $maxID = 0;
    foreach ($records as $record)
      $maxID = ((int)$record['id'] > $maxID) ? (int)$record['id'] : $maxID;

    try {
      // execute query
      $stmt = $this->db->prepare("UPDATE tbl_accounting SET status = 1 WHERE id <= :maxID");
      if ( ! $stmt->execute(array(':maxID' => $maxID))) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to close accountable services with max. ID {$maxID}: {$errorInfo[2]}");
      }

      $this->setError(0, "closed accountable services with max. ID {$maxID}");
      return $stmt->rowCount();
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to close accountable services with max. ID {$maxID}: " . $e->getMessage());
    }
  }

  /**
   * retrieve amount of items with exDate in the next X days
   *
   * @access   public
   * @param    int      number of days
   * @return   boolean  status
   */
  public function creditForecast($days) {
    try {
      // count items we have
      $stmt = $this->db->prepare("SELECT count(1) AS items FROM tbl_domains WHERE exDate < DATE_ADD(current_timestamp, INTERVAL :days DAY)");
      if ( ! $stmt->execute(array(":days" => (int)$days))) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to retrieve count of domains in last {$days} days: {$errorInfo[2]}");
      }

      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $items = $row['items'];

      // create approximation / forecast
      $stmt = $this->db->prepare("SELECT count(1) AS forecast FROM tbl_domains WHERE crDate > DATE_SUB(current_timestamp, INTERVAL 1 YEAR)");
      if ( ! $stmt->execute()) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to retrieve count of domains that expire the upcoming year: {$errorInfo[2]}");
      }

      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $forecast = $row['forecast'];
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get user: " . $e->getMessage());
    }

    // return forecast
    return (float)($items + ($forecast/360*(int)$days));
  }

  /**
   * store a domain to be transferred in DB
   *
   * @access   public
   * @param    string   domain name
   * @param    string   registrant handle
   * @param    array    technical contacts
   * @param    array    dns servers
   * @param    string   userid to restrict operation to
   * @return   boolean  status
   */
  public function storeTransfer($domain, $registrant, $techc, $dns, $userid = 1) {
    $elements = array();
    $elements['domain'] = $domain;
    $elements['registrant'] = $registrant;
    $elements['techc'] = $techc;
    $elements['dns'] = $dns;

    return $this->doStore('tbl_transfers', $elements, $userid);
  }

  /**
   * lookup domain transfers
   *
   * @access   public
   * @param    string   domain name
   * @param    string   userid to restrict operation to
   * @return   boolean  status
   */
  public function lookupTransfer($domain, $userid = 1) {
    $keys = array("domain = :domain");
    $values = array(":domain" => $domain);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
    }
    
    try {
      // execute query for active domains
      $stmt = $this->db->prepare("
        SELECT
          count(*) as num
        FROM
          tbl_transfers
        WHERE
          ".implode(' AND ', $keys)."
        ORDER BY
          domain ASC");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get data from transfer table: {$errorInfo[2]}");
      }

      return $stmt->fetchColumn();
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get data from transfer table: " . $e->getMessage());
    }
  }

  /**
   * list domains from the transfer list
   *
   * @access   public
   * @param    string   registrant to restrict search for
   * @param    string   userid to restrict search to
   * @return   array    list of domains, handles and user/contact emails
   */
  public function listTransfers($registrant = "", $userid = 1) {
    $keys = array("1 = 1");
    $values = array();

    // registrant
    if ($registrant) {
      $keys[] = "t.registrant = :registrant";
      $values[":registrant"] = $registrant;
    }

    // ACL settings
    if ($userid > 1) {
      $keys[] = "c.userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // execute query for active domains
      $stmt = $this->db->prepare("
        SELECT
          t.id,
          t.domain,
          t.techc,
          t.dns,
          t.userID AS transferUserID,
          c.name,
          c.email,
          u.id AS userID,
          u.billingID,
          u.email AS email_user
        FROM
          tbl_transfers t,
          tbl_contacts c,
          tbl_users u
        WHERE
          t.registrant = c.handle AND
          c.userid = u.id AND
          " . implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get data from transfer table: {$errorInfo[2]}");
        return array();
      }

      $records = array();
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $records[] = array(
          'id'             => $row['id'],
          'domain'         => $row['domain'],
          'techc'          => unserialize(base64_decode(substr($row['techc'], strlen($this->dbSerializePrefix)))),
          'dns'            => unserialize(base64_decode(substr($row['dns'], strlen($this->dbSerializePrefix)))),
          'name'           => $row['name'],
          'email'          => $row['email'],
          'transferUserID' => $row['transferUserID'],
          'userID'         => $row['userID'],
          'billingID'      => $row['billingID'],
          'email_user'     => $row['email_user'],
        );
      }
      return $records;
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      $this->setError($errorInfo[0], "unable to get data from transfer table: " . $e->getMessage());
      return array();
    }
  }

  /**
   * remove a domain from the transfer list
   *
   * @access   public
   * @param    string   domain name
   * @param    string   userid to restrict operation to
   * @return   boolean  status
   */
  public function deleteTransfer($domain, $userid = 1) {
    $keys = array("domain = :domain");
    $values = array(":domain" => $domain);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // execute query
      $stmt = $this->db->prepare("DELETE FROM tbl_transfers WHERE " . implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to update transfer table for {$domain}: {$errorInfo[2]}");
      }

      return $this->setError(0, "transfer entry removed for {$domain}");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to update transfer table for {$domain}: " . $e->getMessage());
    }
  }

  /**
   * auto-complete domain names
   *
   * @access   public
   * @return   mixed  data array
   */
  public function autocompleteDomain($search, $limit = 10, $userid = 1) {
    $domains = array();
    $keys = array("domain like :search");
    $values = array(":search" => "%{$search}%");

    // set default to 10
    if ($limit == 0)
      $limit = 10;

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // execute query for active domains
      $stmt = $this->db->prepare("SELECT domain FROM tbl_domains WHERE active = 1 AND ".implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get domains: {$errorInfo[2]}");
      }

      $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

      $stmt = $this->db->prepare("SELECT concat(domain, ' (transfer-in)') FROM tbl_transfers WHERE ".implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get domains: {$errorInfo[2]}");
      }

      $domains = array_merge($domains, $stmt->fetchAll(PDO::FETCH_COLUMN));
      sort($domains);
      return array_slice($domains, 0, $limit);
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to list domains: " . $e->getMessage());
    }
  }

  /**
   * collect expiring domains
   *
   * @access   public
   * @return   mixed  data array
   */
  public function expiringDomains($days, $userid = 1) {
    $keys = array("1 = 1");
    $values = array(":days" => $days);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "c.userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // execute query for active domains
      $stmt = $this->db->prepare("
        SELECT
          d.*,
          c.*,
          u.billingID
        FROM
          tbl_users u,
          tbl_contacts c,
          tbl_domains d
        WHERE
          d.exDate < NOW() + INTERVAL :days DAY AND
          d.active = 1 AND
          d.registrant = c.handle AND
          c.userid = u.id AND
          ".implode(' AND ', $keys)."
        ORDER BY
          d.exDate ASC");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get domain and contact data: {$errorInfo[2]}");
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get domain and contact data: " . $e->getMessage());
    }
  }

  /**
   * collect all available domain info for export
   *
   * @access   public
   * @return   mixed  data array
   */
  public function exportDomains($userid = 1) {
    $keys = array("1 = 1");
    $values = array();

    // ACL settings
    if ($userid > 1) {
      $keys[] = "d.userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // execute query for active domains
      $stmt = $this->db->prepare("
        SELECT
          d.*,
          d.active as domainActive,
          d.authinfo as domainAuthinfo,
          c.*,
          u.billingID
        FROM
          tbl_users u,
          tbl_contacts c,
          tbl_domains d
        WHERE
          d.registrant = c.handle AND
          c.userid = u.id AND
          ".implode(' AND ', $keys)."
        ORDER BY
          domain ASC");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get domain and contact data: {$errorInfo[2]}");
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get domain and contact data: " . $e->getMessage());
    }
  }

  /**
   * set reminder on a domain name
   *
   * @access   public
   * @param    string domain name
   * @param    string date
   * @param    string notice text to be sent
   * @return   mixed  data array
   */
  public function setReminder($domain, $date, $notice, $email, $userid = 1) {
    $keys = array("domain = :domain");
    $values = array(":domain" => $domain);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // test domain using ACL
      $stmt = $this->db->prepare("
        SELECT
          count(*) AS num
        FROM
          tbl_domains d
        WHERE
          ".implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get domain and contact data: {$errorInfo[2]}");
      }

      if ($stmt->fetchColumn() <> 1)
        return $this->setError(1, "domain does not belong to this user");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get domain and contact data: " . $e->getMessage());
    }

    // insert reminder
    $elements = array();
    $elements['date'] = $date;
    $elements['domain'] = $domain;
    $elements['notice'] = $notice;
    $elements['email'] = $email;

    return $this->doStore('tbl_reminder', $elements);
  }

  /**
   * collect all available domain info for export
   *
   * @access   public
   * @param    string optional domain (get one or all messages)
   * @return   mixed  data array
   */
  public function getReminder($domain = null, $userid = 1, $doRemind = false) {
    $keys = array("1 = 1");
    $values = array();

    // ACL settings
    if ($userid > 1) {
      $keys[] = "d.userID = :userID";
      $values[":userID"] = $userid;
    }

    // set condition
    if ( ! is_null($domain)) {
      $keys[] = "d.domain = :domain";
      $values[":domain"] = $domain;
    }

    // add condition for reminders
    if ($doRemind) {
      $keys[] = "r.date <= CURDATE()";
    }

    try {
      // execute query
      $stmt = $this->db->prepare("
        SELECT
          r.id,
          r.date,
          r.domain,
          r.email,
          r.notice
        FROM
          tbl_domains d,
          tbl_reminder r
        WHERE
          d.domain = r.domain AND
          r.active = 1 AND
          ".implode(' AND ', $keys)."
        ORDER BY
          r.date DESC");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get reminders: {$errorInfo[2]}");
      }

      return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get reminders: " . $e->getMessage());
    }
  }

  /**
   * collect all available domain info for export
   *
   * @access   public
   * @param    int    ID of message to be archived
   * @return   mixed  data array
   */
  public function archiveReminder($id, $userid = 1) {
    $keys = array("1 = 1");
    $values = array(":id" => $id);

    // ACL settings
    if ($userid > 1) {
      $keys[] = "d.userID = :userID";
      $values[":userID"] = $userid;
    }

    try {
      // test domain using ACL
      $stmt = $this->db->prepare("
        SELECT
          count(*) AS num
        FROM
          tbl_domains d,
          tbl_reminder r
        WHERE
          r.id = :id AND
          r.domain = d.domain AND
          ".implode(' AND ', $keys));
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to get reminder data: {$errorInfo[2]}");
      }

      if ($stmt->fetchColumn() <> 1)
        return $this->setError(1, "domain does not belong to this user");

      $stmt = $this->db->prepare("UPDATE tbl_reminder SET active = 0 WHERE id = :id");
      if ( ! $stmt->execute(array(":id" => (int)$id))) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to update reminder table: {$errorInfo[2]}");
      }

      return $this->setError(0, "reminder with ID {$id} archived");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to get reminder data: " . $e->getMessage());
    }
  }

  /**
   * change the users password
   *
   * @access   public
   * @param    string  new password
   * @param    int     user ID for whom to change the password
   * @return   boolean success state
   */
  public function changePassword($newPassword, $userid) {
    $values = array(
      ":newPassword" => $newPassword,
      ":userid" => $userid,
    );

    try {
      // execute query
      $stmt = $this->db->prepare("UPDATE tbl_users SET password = md5(:newPassword) WHERE id = :userid");
      if ( ! $stmt->execute($values)) {
        $errorInfo = $stmt->errorInfo();
        return $this->setError($errorInfo[0], "{$errorInfo[1]}: unable to change password for user with ID {$userid}: {$errorInfo[2]}");
      }

      return $this->setError(0, "password changed for user with ID {$userid}");
    } catch (PDOException $e) {
      $errorInfo = $this->db->errorInfo();
      return $this->setError($errorInfo[0], "unable to change password for user with ID {$userid}: " . $e->getMessage());
    }
  }
}

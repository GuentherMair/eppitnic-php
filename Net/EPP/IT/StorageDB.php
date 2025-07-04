<?php

require_once 'Net/EPP/IT/StorageInterface.php';
require_once 'libs/adodb/adodb.inc.php';

/**
 * A simple class handling the EPP registrations with IT-NIC in a database.
 *
 * PHP version 5
 *
 * LICENSE:
 *
 * Copyright (c) 2009, Günther Mair <guenther.mair@hoslo.ch>
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
 * @package     Net_EPP_IT_StorageDB
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: StorageDB.php 230 2010-10-28 15:49:46Z gunny $
 */
class Net_EPP_IT_StorageDB implements Net_EPP_IT_StorageInterface
{
  public    $dbConnect;

  public    $dbMaxEntries      = 50;
  public    $dbSerializePrefix = "__SERIALIZED:";
  public    $dbMagicQuotes     = TRUE;

  protected $dberrCode         = 0;
  protected $dberrMsg          = "";

  /**
   * Class constructor
   *
   *  - initialize database connection
   *
   * @access   public
   * @param    array  db connection paramenters (dbtype, dbhost, dbuser, dbpwd, dbname)
   */
  function __construct($cfg) {
    if ( ! $cfg->dbmagicquotes )
      $this->dbMagicQuotes = FALSE;
    $this->dbConnect = ADONewConnection($cfg->dbtype);
    if ( ! $this->dbConnect )
      return $this->setError(1, "unable to load adodb database driver '".$cfg->dbConnecttype."': ".$this->dbConnect->ErrorMsg());
    if ( ! $this->dbConnect->Connect($cfg->dbhost, $cfg->dbuser, $cfg->dbpwd, $cfg->dbname) )
      return $this->setError(2, "unable to connect to database '".$cfg->dbname."' on '".$cfg->dbhost."' with user '".$cfg->dbuser."': ".$this->dbConnect->ErrorMsg());
    $this->dbConnect->setFetchMode(ADODB_FETCH_ASSOC);
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
    return ($errno == 0 ) ? TRUE : FALSE;
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
   * escape SQL string
   * (reduce size)
   *
   * @access   protected
   * @param    string    data to escape
   * @return   string    escaped sql string
   */
  protected function escape($data) {
    return $this->dbConnect->qstr($data, ($this->dbMagicQuotes ? get_magic_quotes_gpc() : FALSE));
  }

  /**
   * internal ACL default settings
   * (reduce size)
   *
   * @access   protected
   * @param    int       userID
   * @param    string    optional table name
   * @return   string    ACL string to be added to sql queries
   */
  protected function ACL($userID, $table = null) {
    return ( $userID == "1" ) ? "" : " and ".( ($table === null) ? "" : $table."." )."userID=".$this->escape($userID);
  }

  /**
   * store data to DB
   *
   * @access   protected
   * @param    string    information store (tbl_transactions, tbl_responses, tbl_msgqueue, ...)
   * @param    array     information to be stored
   * @param    string    user ACL (only allowed for tbl_contacts and tbl_domains)
   * @return   boolean   status
   */
  protected function doStore($table, $elements, $userID = 1) {
    if ( ! is_array($elements) )
      return $this->setError(4, "second paramenter must be an array!");

    $keys = array();
    $values = array();
    foreach ($elements as $key => $value) {
      $keys[] = $key;
      if ( ($key == "clTRData") || ($key == "svHTTPData") || is_array($value) )
        $values[] = "'".$this->dbSerializePrefix.base64_encode(serialize($value))."'";
      else
        $values[] = $this->escape($value);
    }

    // ACL settings
    if ( in_array($table, array('tbl_contacts', 'tbl_domains')) ) {
      $keys[] = 'userID';
      $values[] = $this->escape($userID);
    }

    // execute query
    if ( $this->dbConnect->Execute("INSERT INTO ".$table." (".implode(",", $keys).") VALUES (".implode(",", $values).")") )
      return $this->setError(0, "information stored to '".$table."'");
    else
      return $this->setError(8, "unable to store given data to '".$table."': ".$this->dbConnect->ErrorMsg());
  }

  /**
   * update data in DB
   *
   * @access   protected
   * @param    string    information store (tbl_transactions, tbl_responses, tbl_msgqueue, ...)
   * @param    array     information to be stored
   * @param    string    the column to look at
   * @param    string    the value to look up
   * @param    string    user ACL (only allowed for tbl_contacts and tbl_domains)
   * @return   boolean   status
   */
  protected function doUpdate($table, $elements, $index, $handle, $userID = 1) {
    if ( ! is_array($elements) )
      return $this->setError(4, "second paramenter must be an array!");

    $update = array();
    foreach ($elements as $key => $value) {
      if ( ($key == "clTRData") || ($key == "svHTTPData") || is_array($value) )
        $update[] = $key . "='".$this->dbSerializePrefix.base64_encode(serialize($value))."'";
      else
        $update[] = $key . "=".$this->escape($value);
    }

    // execute query
    if ( $this->dbConnect->Execute("UPDATE ".$table." set ".implode(",", $update)." WHERE ".$index."='".$handle."'".$this->ACL($userID)) )
      return $this->setError(0, "updated '".$table."' with INDEX ".$index."='".$handle."'");
    else
      return $this->setError(16, "unable to update '".$table."' with INDEX ".$index."='".$handle."' and ACL '".$userID."': ".$this->dbConnect->ErrorMsg());
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
  public function storeContact($elements, $userID = 1) {
    return $this->doStore("tbl_contacts", $elements, $userID);
  }

  /**
   * store domain to DB
   *
   * @access   public
   * @param    array     domain information to be stored
   * @return   boolean   status
   */
  public function storeDomain($elements, $userID = 1) {
    return $this->doStore("tbl_domains", $elements, $userID);
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
  public function updateContact($elements, $contact, $userID = 1) {
    return $this->doUpdate("tbl_contacts", $elements, "handle", $contact, $userID);
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
  public function updateDomain($elements, $domain, $userID = 1) {
    return $this->doUpdate("tbl_domains", $elements, "domain", $domain, $userID);
  }

  /**
   * set the maximum value for dbMaxEntries (default: 50)
   * Use 0 for no limit!
   *
   * @access   public
   * @param    integer   the maximum value for dbMaxEntries
   */
  public function setDBMaxEntries($dbMaxEntries) {
    if ( (int)$dbMaxEntries < 0 )
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
   * @param    string    user ACL (only allowed for tbl_contacts and tbl_domains)
   * @return   array     results OR FALSE in case of failure
   */
  protected function doRetrieve($table, $index, $value, $strict = TRUE, $order = null, $userID = 1) {
    $elements = array();

    // if no value was specified, multiple results are requested
    if ( $value === null )
      $condition = "1 = 1";
    else
      $condition = ( $strict === TRUE ) ? $index." = '".$value."'" : $index." like ".$this->escape($value);

    // if requested choose a different sort order
    $sort_order = ( $order === null ) ? "id DESC" : $order;

    // execute query
    if ( $this->dbMaxEntries == 0 )
      $result = $this->dbConnect->Execute("SELECT * FROM ".$table." WHERE ".$condition.$this->ACL($userID)." ORDER BY ".$sort_order);
    else
      $result = $this->dbConnect->SelectLimit("SELECT * FROM ".$table." WHERE ".$condition.$this->ACL($userID). " ORDER BY ".$sort_order, $this->dbMaxEntries);

    // first evaluation of the result
    if ( $result === FALSE )
      return $this->setError(8, "unable to get data from '".$table."': ".$this->dbConnect->ErrorMsg());

    // construct return array
    $x = 0;
    $prefix_length = strlen($this->dbSerializePrefix);
    while ( !$result->EOF ) {
      for ( $i = 0, $num = $result->FieldCount(); $i < $num; $i++ ) {
        $field = $result->FetchField($i);
        if ( substr($result->Fields($field->name), 0, $prefix_length) == $this->dbSerializePrefix )
          $elements[$x][$field->name] = unserialize(base64_decode(substr($result->Fields($field->name), $prefix_length)));
        else
          $elements[$x][$field->name] = $result->Fields($field->name);
      }
      $result->MoveNext();
      $x++;
    }
  
    return $elements;
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
    if ( $this->dbConnect->Execute("UPDATE tbl_messages SET archived = 1 WHERE id = '".(int)$id."'") )
      return $this->setError(32, "unable to update message table: ".$this->dbConnect->ErrorMsg());
    else
      return TRUE;
  }

  /**
   * retrieve parsed messages from DB
   *
   * @access   public
   * @param    boolean   whether to retrieve all or only active messages
   * @param    string    user ACL
   * @return   boolean   status
   */
  public function retrieveParsedMessages($active = true, $userID = 1) {
    $elements = array();

    // set primary condition (archived or not)
    if ( $active )
      $condition = 'archived = 0';
    else
      $condition = '1 = 1';

    // set user ACL
    if ( $userID > 1 )
      $acl = ' and d.userID = '.$userID;
    else
      $acl = '';

    // execute query
    $result = $this->dbConnect->Execute("
      SELECT
        t.*,
        d.userID,
        d.registrant
      FROM
        tbl_messages t
      LEFT JOIN
        tbl_domains d
      ON
        t.domain = d.domain
      WHERE
        ".$condition.$acl."
      ORDER BY id ASC"); // DON'T CHANGE!! A new transfer state message should overwrite an existing entry when transforming into an associative array

    // first evaluation of the result
    if ( $result === FALSE )
      return $this->setError(8, "unable to get data from tbl_messages: ".$this->dbConnect->ErrorMsg());

    // construct return array
    $x = 1;
    while ( !$result->EOF ) {
      for ( $i = 0, $num = $result->FieldCount(); $i < $num; $i++ ) {
        $field = $result->FetchField($i);
        $elements[$x][$field->name] = $result->Fields($field->name);
      }
      $result->MoveNext();
      $x++;
    }
  
    return $elements;
  }

  /**
   * retrieve a contact from DB
   *
   * @access   public
   * @param    string    contact to look up
   * @return   array     result OR FALSE in case of failure or ambiguity
   */
  public function retrieveContact($contact, $userID = 1) {
    $tmp = $this->doRetrieve("tbl_contacts", "handle", $contact, TRUE, null, $userID);
    if ( ($tmp === FALSE) || (count($tmp) <> 1) )
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
  public function retrieveDomain($domain, $userID = 1) {
    $tmp = $this->doRetrieve("tbl_domains", "domain", $domain, TRUE, null, $userID);
    if ( ($tmp === FALSE) || (count($tmp) <> 1) )
      return FALSE;
    else
      return $tmp[0];
  }

  /**
   * class destructor
   */
  function __destruct() {
    $this->dbConnect->Close();
  }
}


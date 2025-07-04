<?php

require_once 'Net/EPP/IT/log_severity.php';

/**
 * An abstract class for other EPP objects (session, contact, domain).
 *
 * It provides:
 *  - public variables available inside all objects
 *  - a generic constructor and protected variables for Client and Storage
 *  - a generic error code handler
 *  - a generic ExecuteQuery method
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
 * @package     Net_EPP_IT_AbstractObject
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: AbstractObject.php 35 2009-12-22 20:58:55Z gunny $
 */
abstract class Net_EPP_IT_AbstractObject
{
  protected $client;
  protected $storage;

  protected $trues = array(TRUE, "true", "TRUE", 1);
  protected $falses = array(FALSE, "false", "FALSE", 0, null);

  public    $debug = LOG_WARNING;

  public    $xmlQuery;  // xml query string
  public    $result;    // HTTP response string
  public    $xmlResult; // parsed reponse (SimpleXMLElement may be incomplete)

  public    $svCode;
  public    $svMsg;
  public    $svTRID;
  public    $extValueReasonCode;
  public    $extValueReason;

  /**
   * Class constructor
   *
   * @access   public
   * @param    Net_EPP_IT_Client            client class
   * @param    Net_EPP_IT_StorageInterface  storage class
   */
  public function __construct(&$client, &$storage) {
    $this->client  = $client;
    $this->storage = $storage;
  }

  /**
   * authinfo generator
   *
   * @access   public
   * @return   string[16]  random authinfo code
   */
  public function authinfo() {
    return substr(md5(rand()), 0, 16);
  }

  /**
   * check existence of iso-3166-1 code
   *
   * @access   protected
   * @param    string    iso-3166-1 code
   * @return   boolean   status
   */
  protected function is_iso3166_1($code) {
    return in_array($code, array(
           'AF','AX','AL','DZ','AS','AD','AO','AI','AQ','AG','AR','AM','AW',
           'AU','AT','AZ','BS','BH','BD','BB','BY','BE','BZ','BJ','BM','BT',
           'BO','BA','BW','BV','BR','IO','BN','BG','BF','BI','KH','CM','CA',
           'CV','KY','CF','TD','CL','CN','CX','CC','CO','KM','CG','CD','CK',
           'CR','CI','HR','CU','CY','CZ','DK','DJ','DM','DO','EC','EG','SV',
           'GQ','ER','EE','ET','FK','FO','FJ','FI','FR','GF','PF','TF','GA',
           'GM','GE','DE','GH','GI','GR','GL','GD','GP','GU','GT','GG','GN',
           'GW','GY','HT','HM','VA','HN','HK','HU','IS','IN','ID','IR','IQ',
           'IE','IM','IL','IT','JM','JP','JE','JO','KZ','KE','KI','KP','KR',
           'KW','KG','LA','LV','LB','LS','LR','LY','LI','LT','LU','MO','MK',
           'MG','MW','MY','MV','ML','MT','MH','MQ','MR','MU','YT','MX','FM',
           'MD','MC','MN','ME','MS','MA','MZ','MM','NA','NR','NP','NL','AN',
           'NC','NZ','NI','NE','NG','NU','NF','MP','NO','OM','PK','PW','PS',
           'PA','PG','PY','PE','PH','PN','PL','PT','PR','QA','RE','RO','RU',
           'RW','SH','KN','LC','PM','VC','WS','SM','ST','SA','SN','RS','SC',
           'SL','SG','SK','SI','SB','SO','ZA','GS','ES','LK','SD','SR','SJ',
           'SZ','SE','CH','SY','TW','TJ','TZ','TH','TL','TG','TK','TO','TT',
           'TN','TR','TM','TC','TV','UG','UA','AE','GB','US','UM','UY','UZ',
           'VU','VE','VN','VG','VI','WF','EH','YE','ZM','ZW'));
  }

  /**
   * check existence of iso-3166-2:IT code
   *
   * @access   protected
   * @param    string    iso-3166-2:IT code
   * @return   boolean   status
   */
  protected function is_iso3166_2IT($code) {
    return in_array($code, array(
           'AG','AL','AN','AO','AP','AQ','AR','AT','AV','BA','BG','BI','BL',
           'BN','BO','BR','BS','BZ','CA','CB','CE','CH','CI','CL','CN','CO',
           'CR','CS','CT','CZ','EN','FE','FG','FI','FO','FR','GE','GO','GR',
           'IM','IS','KR','LC','LE','LI','LO','LT','LU','MC','ME','MI','MN',
           'MO','MS','MT','NA','NO','NU','OG','OR','OT','PA','PC','PD','PE',
           'PG','PI','PN','PO','PR','PS','PT','PV','PZ','RA','RC','RE','RG',
           'RI','RM','RN','RO','SA','SI','SO','SP','SR','SS','SV','TA','TE',
           'TN','TO','TP','TR','TS','TV','UD','VA','VB','VC','VE','VI','VR',
           'VS','VT','VV'));
  }

  /**
   * check existence of iso-3166-1 code (european union)
   *
   * @access   protected
   * @param    string    iso-3166-1 code
   * @return   boolean   status
   */
  protected function is_iso3166_1EU($code) {
    return in_array($code, array(
           'BE','BG','DK','DE','EE','FI','FR','GR','IE','IT','LV','LT','LU',
           'MT','NL','AT','PL','PT','RO','SE','SK','SI','ES','CZ','HU','GB',
           'CY'));
  }

  /**
   * set error code and message
   *
   * @access   protected
   * @param    string    error message
   * @param    string    4-digit error code
   */
  protected function error($msg, $code = "0000") {
    $this->svMsg = $msg;
    $this->svCode = $code;
  }

  /**
   * execute ever returning queries to the server
   *
   * @access   protected
   * @param    string    client transaction type
   * @param    string    client transaction object
   * @param    boolean   store transaction and response
   * @return   boolean   status
   */
  protected function ExecuteQuery($clTRType, $clTRObject, $store = TRUE) {
    // store request
    if ( $store )
      $this->storage->storeTransaction(
        $this->client->get_clTRID(),
        $clTRType,
        $clTRObject,
        $this->xmlQuery);

    // send request + parse response
    $this->result = $this->client->sendRequest($this->xmlQuery);
    $this->xmlResult = $this->client->parseResponse($this->result['body']);

    // look for a server response code
    if ( is_object($this->xmlResult->response->result) ) {

      // look for a server message
      if ( is_object($this->xmlResult->response->result->msg) )
        $this->svMsg = $this->xmlResult->response->result->msg;
      else
        $this->svMsg = "";

      // look for a server message code
      $this->svCode = $this->xmlResult->response->result['code'];
      switch ( substr($this->svCode, 0, 1) ) {
        case "1":
          $return_code = TRUE;
          break;
        case "2":
        default:
          $return_code = FALSE;
          break;
      }

      // look for an extended server error message and code
      if ( is_object($this->xmlResult->response->result->extValue->reason) ) {
        $this->extValueReasonCode = $this->xmlResult->response->result->extValue->value->reasonCode;
        $this->extValueReason = $this->xmlResult->response->result->extValue->reason;
      } else {
        $this->extValueReasonCode = '';
        $this->extValueReason = '';
      }

    } else {
      $this->error("Unexpected result (no xml response code).");
      $return_code = FALSE;
    }

    // look for a server transaction ID
    if ( is_object($this->xmlResult->response->trID->svTRID) )
      $this->svTRID = $this->xmlResult->response->trID->svTRID;
    else
      $this->svTRID = "";

    // store response
    if ( $store )
      $this->storage->storeResponse(
        $this->client->get_clTRID(),
        $this->svTRID,
        $this->svCode,
        0,
        $this->result,
        $this->extValueReasonCode,
        $this->extValueReason);

    return $return_code;
  }

}

?>

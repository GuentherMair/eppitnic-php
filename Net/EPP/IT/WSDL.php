<?php

require_once 'Net/EPP/IT/Client.php';
require_once 'Net/EPP/IT/StorageDB.php';
require_once 'Net/EPP/IT/Session.php';
require_once 'Net/EPP/IT/Contact.php';
require_once 'Net/EPP/IT/Domain.php';

/**
 * This file provides a generic infrastructure to the WSDL interface.
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
 * @package     Net_EPP_IT_WSDL
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: WSDL.php 196 2010-10-24 14:52:57Z gunny $
 */

class Net_EPP_IT_WSDL
{
  public $nic;
  public $db;
  public $session;
  public $contact;
  public $domain;
  public $statusCode = 1000;
  public $statusMsg = '';
  public $connected = FALSE;

  /**
   * class constructor
   *
   * @access   public
   * @param    string   configuration file name or XML configuration string
   * @return   boolean  status
   */
  public function __construct($cfg = "../config-wsdl.xml") {
    // create EPP objects
    $this->nic = new Net_EPP_IT_Client($cfg);
    $this->db = new Net_EPP_IT_StorageDB($this->nic->EPPCfg->adodb);
    $this->session = new Net_EPP_IT_Session($this->nic, $this->db);
    $this->session->debug = LOG_DEBUG;
    $this->contact = new Net_EPP_IT_Contact($this->nic, $this->db);
    $this->contact->debug = LOG_DEBUG;
    $this->domain = new Net_EPP_IT_Domain($this->nic, $this->db);
    $this->domain->debug = LOG_DEBUG;
  }

  /**
   * build an error string and set the statusCode
   *
   * @access   public
   * @param    mixed    Net_EPP_IT_AbstractObject
   * @param    int      statusCode to set
   * @return   string   error message
   */
  public function createErrMsg($object, $statusCode) {
    $this->statusCode = $statusCode;
    $this->statusMsg = $object->getError();
    return $this->statusMsg;
  }

  /**
   * create a status description including error codes
   *
   * code definitions:
   *  1xxx     generic success codes
   *  2xxx     generic failure codes
   *  3xxx     method specific success codes
   *  4xxx     method specific failure codes
   *
   * @access   public
   * @return   boolean  status
   */
  public function statusDescription() {
    global $soapState;
    $debug = debug_backtrace();

    if ( $this->statusCode < 3000 )
      return $soapState['generic'][$this->statusCode] . $this->statusMsg;
    else
      return $soapState[$debug[1]['function']][$this->statusCode] . $this->statusMsg;
  }

  /**
   * perform connect and login
   *
   * @access   public
   * @return   boolean  status
   */
  public function connect() {
    if ( ! is_writeable($this->nic->compile_dir) )
      $this->createErrMsg($this->session, 2004); // smarty compile folder not writeable!
    else if ( ! $this->session->hello() )
      $this->createErrMsg($this->session, 2002); // connection failed
    else if ( $this->session->login() === FALSE )
      $this->createErrMsg($this->session, 2001); // login failed
    else
      $this->connected = TRUE;
    return ($this->statusCode == 1000) ? TRUE : FALSE;
  }

  /**
   * perform logout
   *
   * @access   public
   * @return   boolean  status
   */
  public function disconnect() {
    if ( $this->connected && ! $this->session->logout() )
      return FALSE;
    else
      return TRUE;
  }
}


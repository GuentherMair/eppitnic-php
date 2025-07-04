<?php

require_once 'Net/EPP/IT/AbstractObject.php';

/**
 * A simple class handling EPP sessions.
 *
 * Available methods:
 *  - hello
 *  - login
 *  - keepalive
 *  - logout
 *  - pollID
 *  - pollMessageCount
 *  - poll
 *  - showCredit
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
 * @package     Net_EPP_IT_Session
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: Session.php 167 2010-10-18 09:36:54Z gunny $
 */
class Net_EPP_IT_Session extends Net_EPP_IT_AbstractObject
{
  protected $credit = null;
  protected $messages = null;
  protected $msgID = null;
  protected $msgTitle = null;

  /**
   * session start
   *
   * @access   public
   * @return   boolean status
   */
  public function hello() {
    // fill xml template
    $this->xmlQuery = $this->client->fetch("hello");
    $this->client->clear_all_assign();

    // query server (will return false)
    $this->ExecuteQuery("hello", "", ($this->debug >= LOG_DEBUG));

    // this is the only query with no result code
    if ( (substr($this->result['code'], 0, 1) == "2") &&
         (is_object($this->xmlResult->greeting)) ) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * session login/logout background method
   *
   * @access   private
   * @param    string  login/logout
   * @return   mix     status (FALSE or EPP status code)
   */
  private function loginout($which) {
    // fetch template
    $this->xmlQuery = $this->client->fetch($which);
    $this->client->clear_all_assign();

    // query server
    if ( $this->ExecuteQuery($which, "", ($this->debug >= LOG_DEBUG)) ) {
      // see if we got the expected information
      if ( is_object($this->xmlResult->response->extension) ) {
        $tmp = $this->xmlResult->response->extension->children('http://www.nic.it/ITNIC-EPP/extepp-1.0');
        $this->credit = (float) $tmp->creditMsgData->credit;
      }
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * session login
   *
   * @access   public
   * @param    string optional new password
   * @return   mix    status (FALSE or EPP status code)
   */
  public function login($newPW = "") {
    // fill xml template
    $this->client->assign('username', $this->client->EPPCfg->username);
    $this->client->assign('password', $this->client->EPPCfg->password);
    $this->client->assign('lang', $this->client->EPPCfg->lang);
    if ( $newPW != "" ) $this->client->assign('newPW', $newPW);

    return $this->loginout("login");
  }

  /**
   * session keepalive
   *
   * @access   public
   * @return   boolean status
   */
  public function keepalive() {
    return $this->hello();
  }

  /**
   * session logout
   *
   * @access   public
   * @return   mix    status (FALSE or EPP status code)
   */
  public function logout() {
    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());

    return $this->loginout("logout");
  }

  /**
   * return current message ID
   * if queue has not yet been looked at, we are going to poll it once
   *
   * @access   public
   * @return   integer message ID on top of message stack
   */
  public function pollID() {
    if ($this->msgID === null) $this->poll(FALSE);
    return (int)$this->msgID;
  }

  /**
   * check number of messages in polling queue
   * if queue has not yet been looked at, we are going to poll it once
   *
   * @access   public
   * @return   integer amount of messages in queue
   */
  public function pollMessageCount() {
    if ($this->messages === null) $this->poll(FALSE);
    return (int)$this->messages;
  }

  /**
   * poll message queue
   *
   * @access   public
   * @param    boolean  store message to DB (defaults to TRUE)
   * @param    string   polling type (defaults to "req")
   * @param    string   message ID (default to empty)
   * @return   boolean  status
   */
  public function poll($store = TRUE, $type = "req", $msgID = null) {
    switch ($type) {
      case "req":
        break;
      case "ack":
        if ( empty($msgID) ) {
          $this->setError("Polling of type 'ack' requires a message ID to be set!");
          return FALSE;
        }
        break;
      default:
        $this->setError("Polling of type '".$type."' not supported, choose one of 'req' or 'ack'.");
        return FALSE;
        break;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('type', $type);
    if ( !empty($msgID) ) $this->client->assign('msgID', $msgID);

    // fetch template
    $this->xmlQuery = $this->client->fetch("poll");
    $this->client->clear_all_assign();

    // query server
    $qrs = $this->ExecuteQuery("poll", "poll", ($this->debug >= LOG_DEBUG));

    // look at message counter
    if ( is_object($this->xmlResult->response->msgQ[0]) ) {
      $this->messages = (int)$this->xmlResult->response->msgQ->attributes()->count;
      $this->msgID = (int)$this->xmlResult->response->msgQ->attributes()->id;
      $this->msgTitle = (string)$this->xmlResult->response->msgQ->msg;
    } else if ( $qrs === TRUE ) {
      $this->messages = 0;
    }

    // see if we want to store an answer
    if ( ($store === TRUE) && $qrs )
      $this->storage->storeMessage(
        $this->client->get_clTRID(),
        $this->svTRID,
        $this->svCode,
        0,
        $this->result);

    return $qrs;
  }

  /**
   * show credit
   *
   * @access   public
   * @return   mix    amount or null (if login did not succeed)
   */
  public function showCredit() {
    return $this->credit;
  }
}


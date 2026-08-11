<?php

namespace Net\EPP\IT;

use Net\EPP\AbstractObject;
use RedBeanPHP\R;

/**
 * A simple class handling EPP sessions.
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
 * @package     Net\EPP\IT\Session
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Session extends AbstractObject
{
  protected $credit = null;
  protected $messages = null;
  protected $msgID = null;
  protected $msgTitle = null;

  /**
   * Class as a string
   *
   * @return string credit
   */
  public function __toString(): string {
    return sprintf("%.2f", $this->credit);
  }

  /**
   * get a single variable/setting from class
   *
   * @param string $var variable name
   * @return mixed value of variable
   */
  public function get(string $var): mixed {
    return $this->$var;
  }

  /**
   * session start
   *
   * @return bool status
   */
  public function hello(): bool {
    // fill xml template
    $this->xmlQuery = $this->client->fetch("session-hello");
    $this->client->clearAllAssign();

    // query server (will return false)
    $this->ExecuteQuery("session-hello", "", ($this->debug >= LOG_DEBUG));

    // this is the only query with no result code
    if ((substr($this->result['code'], 0, 1) == "2") && (is_object($this->xmlResult->greeting))) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * session login/logout background method
   *
   * @param string $which login/logout
   * @return bool status
   */
  private function loginout(string $which): bool {
    // fetch template
    $this->xmlQuery = $this->client->fetch($which);
    $this->client->clearAllAssign();

    // query server
    if ($this->ExecuteQuery($which, "", ($this->debug >= LOG_DEBUG))) {
      // see if we got the expected information
      if (is_object($this->xmlResult->response->extension)) {
        $ns = $this->xmlResult->getNamespaces(TRUE);
        $tmp = $this->xmlResult->response->extension->children($ns['extepp']);
        $this->credit = (float)$tmp->creditMsgData->credit;
      }
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * session login
   *
   * @param string $newPW optional new password
   * @return bool status
   */
  public function login(string $newPW = ""): bool {
    // fill xml template
    $this->client->assign('username', $this->client->EPPCfg->username);
    $this->client->assign('password', $this->client->EPPCfg->password);
    $this->client->assign('lang', $this->client->EPPCfg->lang);
    $this->client->assign('newPW', $newPW);
    $this->client->assign('dnssec', @isset($this->client->EPPCfg->dnssec->active) ? (int)$this->client->EPPCfg->dnssec->active : 0);

    return $this->loginout("session-login");
  }

  /**
   * session keepalive
   *
   * @return bool status
   */
  public function keepalive(): bool {
    return $this->hello();
  }

  /**
   * session logout
   *
   * @return bool status
   */
  public function logout(): bool {
    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());

    return $this->loginout("session-logout");
  }

  /**
   * return current message ID
   * if queue has not yet been looked at, we are going to poll it once
   *
   * @return int message ID on top of message stack
   */
  public function pollID(): int {
    if ($this->msgID === null) $this->poll(FALSE);
    return (int)$this->msgID;
  }

  /**
   * check number of messages in polling queue
   * if queue has not yet been looked at, we are going to poll it once
   *
   * @return int amount of messages in queue
   */
  public function pollMessageCount(): int {
    if ($this->messages === null) $this->poll(FALSE);
    return (int)$this->messages;
  }

  /**
   * poll message queue
   *
   * @param bool $store store message to DB (defaults to TRUE)
   * @param string $type polling type (defaults to "req")
   * @param int|null $msgID message ID (default to empty)
   * @return bool status
   */
  public function poll(bool $store = TRUE, string $type = "req", ?int $msgID = null): bool {
    switch (strtolower($type)) {
      case "req":
        break;
      case "ack":
        if (empty($msgID)) {
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
    if ( ! empty($msgID)) $this->client->assign('msgID', $msgID);

    // fetch template
    $this->xmlQuery = $this->client->fetch("session-poll");
    $this->client->clearAllAssign();

    // query server
    $qrs = $this->ExecuteQuery("session-poll", "poll", ($this->debug >= LOG_DEBUG));

    // look at message counter
    if (is_object($this->xmlResult->response->msgQ[0])) {
      $this->messages = (int)$this->xmlResult->response->msgQ->attributes()->count;
      $this->msgID = (int)$this->xmlResult->response->msgQ->attributes()->id;
      $this->msgTitle = (string)$this->xmlResult->response->msgQ->msg;

      // parse message (only in case of a poll "req") and store it
      if ((strtolower($type) == "req") && ($store === TRUE)) {
        $parsed = $this->parsePollReq();
        R::exec("
          INSERT INTO messages (cl_trid, sv_trid, type, domain, ac_id, re_id, data)
          VALUES (:cl_trid, :sv_trid, :type, :domain, :ac_id, :re_id, :data)
        ", [
          ':cl_trid' => $this->client->get_clTRID(),
          ':sv_trid' => $this->svTRID,
          ':type'    => $parsed['type'],
          ':domain'  => $parsed['domain'],
          ':ac_id'   => $parsed['acID'] ?? null,
          ':re_id'   => $parsed['reID'] ?? null,
          ':data'    => $parsed['data'],
        ]);
      }
    } else if ($qrs === TRUE) {
      $this->messages = 0;
    }

    // see if we want to store an answer
    if (($store === TRUE) && $qrs) {
      R::exec("
        INSERT INTO msgqueue (cl_trid, sv_trid, sv_code, status, sv_httpcode, sv_httpheaders, sv_httpdata)
        VALUES (:cl_trid, :sv_trid, :sv_code, :status, :sv_httpcode, :sv_httpheaders, :sv_httpdata)
      ", [
        ':cl_trid'        => $this->client->get_clTRID(),
        ':sv_trid'        => $this->svTRID,
        ':sv_code'        => $this->svCode,
        ':status'         => 0,
        ':sv_httpcode'    => $this->result['code'],
        ':sv_httpheaders' => $this->result['headers'],
        ':sv_httpdata'    => $this->result['body'],
      ]);
    }

    return $qrs;
  }

  /**
   * method to remove trailing slashes from domain names (dnsErrorMsgData cases)
   *
   * @param string $domain domain name
   * @return string domain name
   */
  protected function stripTrailingDots(string $domain): string {
    return (substr($domain, strlen($domain)-1) == ".") ? substr($domain, 0, strlen($domain)-1) : $domain;
  }

  /**
   * try to parse message received by poll "req"
   *
   * @return array [message type], [domain], [human readable data]
   */
  protected function parsePollReq(): array {
    $ns = $this->xmlResult->getNamespaces(TRUE);

    // passwdReminder
    if (@is_object($this->xmlResult->response->extension->children($ns['extepp'])->passwdReminder->exDate)) {
      $exDate = (string)$this->xmlResult->response->extension->children($ns['extepp'])->passwdReminder->exDate;
      return array(
        'type'   => 'passwdReminder',
        'domain' => '',
        'data'   => $exDate,
      );
    }

    // creditMsgData
    if (@is_object($this->xmlResult->response->extension->children($ns['extepp'])->creditMsgData->credit)) {
      $credit = (string)$this->xmlResult->response->extension->children($ns['extepp'])->creditMsgData->credit;
      return array(
        'type'   => 'creditMsgData',
        'domain' => '',
        'data'   => (string)$this->xmlResult->response->msgQ->msg . " (" . $credit . ")",
      );
    }

    // delayedDebitAndRefundMsgData
    if (@is_object($this->xmlResult->response->extension->children($ns['extepp'])->delayedDebitAndRefundMsgData->amount)) {
      $name = (string)$this->xmlResult->response->extension->children($ns['extepp'])->delayedDebitAndRefundMsgData->name;
      $amount = (string)$this->xmlResult->response->extension->children($ns['extepp'])->delayedDebitAndRefundMsgData->amount;
      return array(
        'type'   => 'delayedDebitAndRefundMsgData',
        'domain' => '',
        'data'   => (string)$this->xmlResult->response->msgQ->msg . " (" . $name . " / " . $amount . ")",
      );
    }

    // simpleMsgData
    if (@is_object($this->xmlResult->response->extension->children($ns['extdom'])->simpleMsgData->name)) {
      $domain = (string)$this->xmlResult->response->extension->children($ns['extdom'])->simpleMsgData->name;
      $title = (string)$this->xmlResult->response->msgQ->msg;
      return array(
        'type'   => 'simpleMsgData',
        'domain' => $this->stripTrailingDots($domain),
        'data'   => $title,
      );
    }

    // dnsErrorMsgData
    if (@is_object($this->xmlResult->response->extension->children($ns['extdom'])->dnsErrorMsgData->report->domain)) {
      $domain = (string)$this->xmlResult->response->extension->children($ns['extdom'])->dnsErrorMsgData->report->domain->attributes()->name;
      $title = (string)$this->xmlResult->response->msgQ->msg;
      $msg = array();
      foreach (@$this->xmlResult->response->extension->children($ns['extdom'])->dnsErrorMsgData->report->domain->test as $child) {
        $msg[] = $child->attributes()->name . ": " . $child->attributes()->status;
      }
      return array(
        'type'   => 'dnsErrorMsgData',
        'domain' => $this->stripTrailingDots($domain),
        'data'   => $title . " (" . implode(", ", $msg) . ")",
      );
    }

    // chgStatusMsgData
    if (@is_object($this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->name)) {
      $domain = (string)$this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->name;
      $title = (string)$this->xmlResult->response->msgQ->msg;
      $msg = array();
      if (@is_object($this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->targetStatus)) {
        foreach (@$this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->targetStatus->children($ns['domain'])->status as $child) {
          $msg[] = $child->attributes()->s;
        }
        foreach (@$this->xmlResult->response->extension->children($ns['extdom'])->chgStatusMsgData->targetStatus->children($ns['rgp'])->rgpStatus as $child) {
          $msg[] = $child->attributes()->s;
        }
      }
      return array(
        'type'   => 'chgStatusMsgData',
        'domain' => $this->stripTrailingDots($domain),
        'data'   => $title . " (" . implode(", ", $msg) . ")",
      );
    }

    // dlgMsgData
    if (@is_object($this->xmlResult->response->extension->children($ns['extdom'])->dlgMsgData->name)) {
      $domain = (string)$this->xmlResult->response->extension->children($ns['extdom'])->dlgMsgData->name;
      $title = (string)$this->xmlResult->response->msgQ->msg;
      $msg = array();
      foreach (@$this->xmlResult->response->extension->children($ns['extdom'])->dlgMsgData->ns as $child) {
        $msg[] = (string)$child;
      }
      return array(
        'type'   => 'dlgMsgData',
        'domain' => $this->stripTrailingDots($domain),
        'data'   => $title . " (" . implode(", ", $msg) . ")",
      );
    }

    // domain transfers
    if (@is_object($this->xmlResult->response->resData->children($ns['domain'])->trnData->name)) {
      $domain = (string)$this->xmlResult->response->resData->children($ns['domain'])->trnData->name;
      $type = $this->xmlResult->response->resData->children($ns['domain'])->trnData->trStatus . "Transfer";
      $title = (string)$this->xmlResult->response->msgQ->msg . ": from " .
        @$this->xmlResult->response->resData->children($ns['domain'])->trnData->acID .
        " (" . @$this->xmlResult->response->resData->children($ns['domain'])->trnData->acDate . ") to " .
        @$this->xmlResult->response->resData->children($ns['domain'])->trnData->reID .
        " (" . @$this->xmlResult->response->resData->children($ns['domain'])->trnData->reDate . ")";
      // the acID field is necessary to compare transfer-out's in case of 'serverApproved' transfers.
      // Both are cast to string here: they are bound straight into the messages
      // INSERT by poll(), and a SimpleXMLElement only survives PDO binding via
      // its __toString(), which is an accident waiting to change.
      return array(
        'type'   => (string)$type,
        'domain' => $this->stripTrailingDots($domain),
        'data'   => $title,
        'acID'   => (string)@$this->xmlResult->response->resData->children($ns['domain'])->trnData->acID,
        'reID'   => (string)@$this->xmlResult->response->resData->children($ns['domain'])->trnData->reID,
      );
    }

    // unknown type
    return array(
      'type'   => 'unknown',
      'domain' => '',
      'data'   => (string)$this->xmlResult->response->msgQ->msg,
    );
  }

  /**
   * show credit
   *
   * @return float|null amount or null (if login did not succeed)
   */
  public function showCredit(): ?float {
    return $this->credit;
  }
}

<?php

require_once dirname(__FILE__).'/Session.php';
require_once dirname(__FILE__).'/Domain.php';
require_once dirname(__FILE__).'/Contact.php';

use RedBeanPHP\R;

/**
 * Orchestrates draining the EPP poll queue and reconciling domain transfer
 * state against it. Ported and cleaned up from the legacy WebInterface
 * controller's pollQueue()/verifyTransfer() methods.
 *
 * This is deliberately its own class rather than living inside
 * Net_EPP_IT_Session: everything it does operates on Domain objects
 * (loadDB/fetch/addNS/remNS/addTECH/remTECH/update/storeDB/deleteDomainDB),
 * so folding it into Session would give Session a dependency on Domain that
 * nothing else in Net/EPP/IT/ has (Domain depends on Contact, nothing
 * depends on Session).
 *
 * Login/logout of the EPP session is the caller's responsibility (matching
 * the legacy contract) -- see cronjobs/process-poll-queue.php, which wraps
 * both methods in withEppSession().
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
 * @package     Net_EPP_IT_PollProcessor
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
class Net_EPP_IT_PollProcessor
{
  protected $client;
  protected $domain;
  protected $contact;

  public function __construct(&$client) {
    $this->client  = $client;
    $this->domain  = new Net_EPP_IT_Domain($client);
    $this->contact = new Net_EPP_IT_Contact($client);
  }

  /**
   * drain the EPP server's poll queue: fetch + store + acknowledge every
   * currently queued message. Requires an already logged-in session.
   *
   * @access   public
   * @param    Net_EPP_IT_Session  an already connected/logged-in session
   * @return   array               human-readable log lines
   */
  public function drainQueue(Net_EPP_IT_Session $session): array {
    $log = [];
    $count = $session->pollMessageCount();
    $log[] = ($count === 0) ? "no messages in polling queue" : "{$count} messages in polling queue";

    while ($session->pollMessageCount() > 0) {
      if ($session->poll(TRUE, "req")) {
        $log[] = "stored message, " . $session->pollMessageCount() . " remaining";
        $session->poll(TRUE, "ack", $session->pollID());
      } else {
        $log[] = "FAILED to fetch message: " . $session->getError();
        break; // avoid spinning forever on a persistent failure
      }
    }
    return $log;
  }

  /**
   * reconcile domain transfer state: classify unarchived transfer-related
   * poll messages, then handle outgoing transfers (deactivate locally),
   * pending transfers (just note them), and open incoming transfer
   * requests (complete, reject, or leave pending as appropriate).
   *
   * Requires an already logged-in session for the live transferStatus()
   * fallback query and the update()/storeDB() calls it makes.
   *
   * @access   public
   * @return   array   human-readable log lines
   */
  public function verifyTransfer(): array {
    $log = [];

    $messages = R::getAll("SELECT * FROM messages WHERE archived_time IS NULL AND type LIKE '%Transfer'");
    $transferIn = [];
    $transferInRejected = [];
    $transferOut = [];
    $transferOutstanding = [];
    foreach ($messages as $msg) {
      switch ($msg['type']) {
        case "serverApprovedTransfer":
          // if we are the acquiring registrar, the domain came TO us; otherwise it left us
          if ($msg['ac_id'] == $this->client->EPPCfg->username) {
            $transferOut[$msg['domain']] = $msg;
          } else {
            $transferIn[$msg['domain']] = $msg;
          }
          break;
        case "clientRejectedTransfer":
          $transferInRejected[$msg['domain']] = $msg;
          break;
        case "clientApprovedTransfer":
          $transferIn[$msg['domain']] = $msg;
          break;
        case "pendingTransfer":
          $transferOutstanding[$msg['domain']] = $msg;
          break;
      }
    }

    // 1. OPEN TRANSFERS -- nothing to do but acknowledge we've seen them
    foreach ($transferOutstanding as $transfer) {
      $log[] = "'{$transfer['domain']}' pendingTransfer noted";
      R::exec("UPDATE messages SET archived_time = NOW() WHERE id = ?", [$transfer['id']]);
    }

    // 2. OUTGOING TRANSFERS -- domain left us, deactivate locally
    foreach ($transferOut as $transfer) {
      $log[] = "handling '{$transfer['domain']}' (transfer-out)";

      if ($this->domain->loadDB($transfer['domain'], 1, true)) {
        if ( ! $this->domain->deleteDomainDB($transfer['domain'], 1, true)) {
          $log[] = "  couldn't deactivate domain locally: " . $this->domain->getError();
        }
      } else {
        $log[] = "  domain not found locally, nothing to deactivate";
      }

      R::exec("UPDATE messages SET archived_time = NOW() WHERE id = ?", [$transfer['id']]);
    }

    // 3. INCOMING TRANSFERS -- reconcile every open local transfer request
    $transfers = R::getAll("
      SELECT
        t.id, t.domain, t.techc, t.dns, t.user_id AS transferUserID,
        c.name, c.email,
        u.id AS user_id, u.billing_id, u.email AS email_user
      FROM transfers t, contacts c, users u
      WHERE t.registrant = c.handle AND c.user_id = u.id");

    foreach ($transfers as $transfer) {
      $log[] = "verifying '{$transfer['domain']}' (transfer-in)";

      $archiveMsg = false;
      if (isset($transferIn[$transfer['domain']])) {
        $trStatus = $transferIn[$transfer['domain']]['type'];
        $archiveMsg = $transferIn[$transfer['domain']]['id'];
      } else if (isset($transferInRejected[$transfer['domain']])) {
        $trStatus = $transferInRejected[$transfer['domain']]['type'];
        $archiveMsg = $transferInRejected[$transfer['domain']]['id'];
      } else if ($this->domain->transferStatus($transfer['domain'])) {
        $trStatus = $this->domain->get('trStatus');
      } else {
        $trStatus = "noResponse";
      }

      switch ($trStatus) {
        case "clientApproved":
        case "clientApprovedTransfer":
        case "serverApproved":
        case "serverApprovedTransfer":
          $log[] = "  transfer is '{$trStatus}', completing locally";

          if ( ! $this->domain->fetch($transfer['domain'])) {
            $log[] = "  couldn't fetch current domain data, will retry next run: " . $this->domain->getError();
            break;
          }

          // reconcile tech contacts to the set requested at transfer time
          $tech = [];
          $currentTech = (array) $this->domain->get('tech');
          foreach ((array) $transfer['techc'] as $newTech) {
            $tech[$newTech] = $newTech;
            $this->domain->addTECH($newTech);
          }
          foreach ($currentTech as $existing) {
            if ( ! in_array($existing, $tech)) $this->domain->remTECH($existing);
          }

          // reconcile nameservers to the set requested at transfer time
          // (dns rows are [{name, ip}, ...], matching the shape used elsewhere in this codebase)
          $allNS = [];
          $currentNS = array_keys((array) $this->domain->get('ns'));
          foreach ((array) $transfer['dns'] as $newNS) {
            $name = is_array($newNS) ? ($newNS['name'] ?? '') : $newNS;
            if ($name === '') continue;
            $allNS[$name] = $name;
            $this->domain->addNS($name, isset($newNS['ip']) ? [$newNS['ip']] : null);
          }
          foreach ($currentNS as $existing) {
            if ( ! in_array($existing, $allNS)) $this->domain->remNS($existing);
          }

          $this->domain->update();

          R::exec("INSERT INTO accounting (operation, billing_id, object, date) VALUES ('transfer', ?, ?, CURDATE())", [$transfer['billing_id'], $transfer['domain']]);

          // transfer-in completing counts as a DNS-sync 'create' event (storeDB() fires it)
          $this->domain->storeDB((int) $transfer['transferUserID']);

          if ($archiveMsg !== false) {
            R::exec("UPDATE messages SET archived_time = NOW() WHERE id = ?", [$archiveMsg]);
          }
          R::exec("DELETE FROM transfers WHERE domain = ?", [$transfer['domain']]);
          break;

        case "clientRejected":
        case "clientRejectedTransfer":
        case "clientCancelled":
          $log[] = "  transfer is '{$trStatus}', removing transfer note";
          if ($archiveMsg !== false) {
            R::exec("UPDATE messages SET archived_time = NOW() WHERE id = ?", [$archiveMsg]);
          }
          R::exec("DELETE FROM transfers WHERE domain = ?", [$transfer['domain']]);
          break;

        case "pending":
          $log[] = "  transfer is 'pending', nothing to do yet";
          break;

        case "noResponse":
          $log[] = "  transfer state unknown -- no poll message yet and no server response";
          break;

        default:
          $log[] = "  transfer is '{$trStatus}', unhandled state";
          break;
      }
    }

    return $log;
  }
}

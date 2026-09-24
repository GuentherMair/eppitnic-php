<?php

namespace Eppitnic\Service;

use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Epp\Session;
use Eppitnic\Persistence\Scope;
use RedBeanPHP\R;

/**
 * Orchestrates draining the EPP poll queue and reconciling domain transfer
 * state against it. Ported and cleaned up from the legacy WebInterface
 * controller's pollQueue()/verifyTransfer() methods.
 *
 * This is deliberately its own class rather than living inside
 * Session: everything it does operates on Domain objects
 * (loadDB/fetch/addNS/remNS/addTECH/remTECH/update/storeDB/deleteDomainDB),
 * so folding it into Session would give Session a dependency on Domain that
 * nothing else in the protocol layer has (Domain depends on Contact, nothing
 * depends on Session).
 *
 * Login/logout of the EPP session is the caller's responsibility -- see
 * src/Cli/Command/PollProcessCommand.php, which wraps both methods in one
 * session.
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
 * @package     Eppitnic\Service\PollProcessor
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
class PollProcessor
{
  protected $client;
  protected $domain;
  protected $contact;

  public function __construct(Client &$client) {
    $this->client  = $client;
    $this->domain  = new Domain($client);
    $this->contact = new Contact($client);
  }

  /**
   * drain the EPP server's poll queue: fetch + store + acknowledge every
   * currently queued message. Requires an already logged-in session.
   *
   * @param Session $session an already connected/logged-in session
   * @return array human-readable log lines
   */
  public function drainQueue(Session $session): array {
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
   * Reconcile transfer state from the unarchived poll messages: outgoing
   * transfers deactivate locally, pending ones are noted, and open incoming
   * requests are completed, rejected or left. Needs a logged-in session.
   *
   * @return array human-readable log lines
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
          // acID is the *acting* client: per RFC 5731 the registrar that
          // approved the transfer, i.e. the losing one. So acID being us means
          // the domain left us; anything else means it came to us
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

      if ($this->domain->loadDB($transfer['domain'], Scope::operator(1))) {
        if ( ! $this->domain->deleteDomainDB($transfer['domain'], Scope::operator(1))) {
          $log[] = "  couldn't deactivate domain locally: " . $this->domain->getError();
        }
      } else {
        $log[] = "  domain not found locally, nothing to deactivate";
      }

      R::exec("UPDATE messages SET archived_time = NOW() WHERE id = ?", [$transfer['id']]);
    }

    // 3. INCOMING TRANSFERS -- reconcile every open local transfer request
    $transfers = R::getAll("
      SELECT t.id, t.domain, t.techc, t.dns
      FROM transfers t, contacts c
      WHERE t.registrant = c.handle");

    foreach ($transfers as $transfer) {
      $log[] = "verifying '{$transfer['domain']}' (transfer-in)";

      // techc/dns are stored serialize()d, so casting with (array) instead
      // would push the blob itself to the registry as a handle. '?: []' guards
      // a corrupt column, unserialize() returning false where nobody is looking
      $techc = empty($transfer['techc']) ? [] : (unserialize($transfer['techc']) ?: []);
      $dns   = empty($transfer['dns'])   ? [] : (unserialize($transfer['dns'])   ?: []);

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
          foreach ($techc as $newTech) {
            $tech[$newTech] = $newTech;
            $this->domain->addTECH($newTech);
          }
          foreach ($currentTech as $existing) {
            if ( ! in_array($existing, $tech)) $this->domain->remTECH($existing);
          }

          // reconcile nameservers to the set requested at transfer time (dns
          // rows are [{name, ip}, ...], matching the shape used elsewhere in
          // this codebase)
          $allNS = [];
          $currentNS = array_keys((array) $this->domain->get('ns'));
          foreach ($dns as $newNS) {
            $name = is_array($newNS) ? ($newNS['name'] ?? '') : $newNS;
            if ($name === '') continue;
            $allNS[$name] = $name;
            $this->domain->addNS($name, isset($newNS['ip']) ? [$newNS['ip']] : null);
          }
          foreach ($currentNS as $existing) {
            if ( ! in_array($existing, $allNS)) $this->domain->remNS($existing);
          }

          $this->domain->update();

          // transfer-in completing counts as a DNS-sync 'create' event
          // (storeDB() fires it); done by this job, not by a person, and it
          // lands in its registrant's reseller like any other domain
          $this->domain->storeDB(null);

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

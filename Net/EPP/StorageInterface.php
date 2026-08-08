<?php

/**
 * A simple interface description for use in storage classes.
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
 */

interface Net_EPP_StorageInterface
{
  // local transactions
  public function storeTransaction($clTRID, $clTRType, $clTRObject, $clTRData);
  public function retrieveTransaction($clTRID = null);

  // server responses
  public function storeResponse($clTRID, $svTRID, $svCode, $status, $response, $extValueReasonCode, $extValueReason);
  public function retrieveResponse($clTRID = null);

  // messages from polling queue
  public function storeMessage($clTRID, $svTRID, $svCode, $status, $response);
  public function retrieveMessage($clTRID = null);
  public function storeParsedMessage($elements);
  public function retrieveParsedMessages($active = true, $user_id = 1);
  public function archiveParsedMessage($id);

  // contact operations
  public function storeContact($elements, $user_id = 1);
  public function retrieveContact($contact, $user_id = 1);
  public function updateContact($elements, $contact, $user_id = 1);

  // domain operations
  public function storeDomain($elements, $user_id = 1);
  public function retrieveDomain($domain, $user_id = 1);
  public function updateDomain($elements, $domain, $user_id = 1);

  // contact operations
  public function listContacts($user_id = 1, $activeOnly = TRUE);
  public function deleteContact($contact, $user_id = 1);
  public function restoreContact($contact, $user_id = 1);

  // domain operations
  public function listDomains($user_id = 1, $handle = null, $activeOnly = TRUE, $age = 0);
  public function deleteDomain($domain, $user_id = 1);
  public function restoreDomain($domain, $user_id = 1);
  public function invoiceableDomains();
  public function renewDomains();

  // accounting operations
  public function doAccount($operation, $billing_id, $object, $date = NULL);
  public function accountableServices();
  public function closeAccountableServices($records);
  public function creditForecast($days);

  // transfer operations
  public function storeTransfer($domain, $registrant, $techc, $dns, $user_id = 1);
  public function lookupTransfer($domain, $user_id = 1);
  public function listTransfers($registrant = "", $user_id = 1);
  public function deleteTransfer($domain, $user_id = 1);

  // reminder operations
  public function setReminder($domain, $date, $notice, $email, $user_id = 1);
  public function getReminder($domain = null, $user_id = 1, $doRemind = false);
  public function archiveReminder($id, $user_id = 1);

  // local account operations
  public function changePassword($newPassword, $user_id);

  // user operations
  public function listUsers();
  public function retrieveUser($user_id);
  public function storeUser($elements);
  public function updateUser($elements, $user_id);
  public function deleteUser($user_id);

  // generic operations
  public function expiringDomains($days, $user_id = 1);
  public function exportDomains($user_id = 1);
  public function autocompleteDomain($search, $limit, $user_id = 1);
}

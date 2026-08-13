<?php

namespace Net\EPP\IT;

use Net\EPP\AbstractObject;
use Net\EPP\ChangeTracking;
use Net\EPP\CheckResult;
use Net\EPP\Client;
use Net\EPP\LocalStorage;
use Net\EPP\Persistence\Changelog;
use Net\EPP\Service\PasswordService;
use Net\EPP\XmlBuilder;
use RedBeanPHP\R;

/**
 * This class handles contact objects.
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
 * @package     Net\EPP\IT\Contact
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Contact extends AbstractObject
{
  use ChangeTracking;
  use LocalStorage;

  protected static function storageTable(): string { return 'contacts'; }
  protected static function storageKeyColumn(): string { return 'handle'; }
  protected static function storageNoun(): string { return 'contact'; }

  /**
   * The contact's own fields.
   *
   * One list, because there were four: the property declarations, the switch
   * in set(), the ladder in updateDB(), and the array in storeDB(). Adding a
   * field meant touching all of them, and missing one was silent -- a field
   * that could be set but never persisted, or persisted but never marked
   * changed.
   */
  public const FIELDS = array(
    'name', 'org', 'street', 'street2', 'street3', 'city', 'province',
    'postalcode', 'countrycode', 'voice', 'fax', 'email', 'authinfo',
    'consentforpublishing', 'nationalitycode', 'entitytype', 'regcode',
    'schoolcode',
  );

  /** the seven fields that together make up <contact:addr> */
  private const ADDRESS_FIELDS = array(
    'street', 'street2', 'street3', 'city', 'province', 'postalcode', 'countrycode',
  );

  /**
   * Fields whose "unset" value is not the empty string.
   */
  private const FIELD_DEFAULTS = array(
    'consentforpublishing' => 0,
    'entitytype'           => 0,
  );

  /**
   * Fields set() must not mark dirty itself: each has a setter that decides
   * whether anything actually changed (setConsent(), setEntityType()).
   */
  private const FIELDS_WITH_SETTERS = array('consentforpublishing', 'entitytype');

  protected $user_id;              // use just in case of an updateRegistrant + change of agent
  protected $status;               // contact states (ok, linked, clientDeleteProhibited, clientUpdateProhibited)
  protected $handle;               // -

  protected $name;
  protected $org;
  protected $street;
  protected $street2;
  protected $street3;
  protected $city;
  protected $province;
  protected $postalcode;
  protected $countrycode;
  protected $voice;
  protected $fax;
  protected $email;
  protected $authinfo;
  protected $consentforpublishing;
  protected $nationalitycode;
  protected $entitytype;
  protected $regcode;
  protected $schoolcode;

  protected $max_check;

  /**
   * Class constructor
   *
   * (initializes authinfo)
   *
   * @param Client $client client class
   */
  function __construct(Client $client) {
    parent::__construct($client);

    $this->authinfo = $this->authinfo();
    $this->initValues();
  }

  /**
   * initialize values
   */
  protected function initValues(): void {
    $this->user_id   = 1;
    $this->status    = array();
    $this->handle    = "";
    $this->clearChanges();
    $this->max_check = 5;

    foreach (self::FIELDS as $field) {
      $this->$field = self::FIELD_DEFAULTS[$field] ?? "";
    }
  }

  /**
   * check for possible values of TRUE
   */
  private function isTrue(mixed $val): bool {
    if ($val === TRUE) {
      return TRUE;
    } else if ((string)$val == "1") {
      return TRUE;
    } else if (strtoupper($val) === "TRUE") {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * restrict access to variables, so we can keep track of changes to them
   *
   * @param string $var variable name
   * @param mixed $val value to set
   * @return mixed value set or FALSE if variable name does not exist
   */
  public function set(string $var, mixed $val): mixed {
    // convert to lower-case
    $var = strtolower($var);

    // Stored as given. Escaping happens once, where it is needed: XmlBuilder
    // escapes at serialization, and PDO parameters need none. Escaping here
    // instead meant the database held HTML entities -- an organisation really
    // named 'Rossi &amp; Figli' -- which every consumer then had to undo, and
    // which is wrong for XML anyway.
    $val = (string)$val;

    if ($var == "entitytype") {
      return $this->setEntityType($val);
    } else if ($var == "consentforpublishing" && $this->isTrue($val)) {
      return $this->setConsent();
    } else if ($var == "consentforpublishing" && ! $this->isTrue($val)) {
      return $this->unsetConsent();
    } else if (isset($this->$var)) {
      if ($this->$var == $val) {
        return FALSE; // value didn't change!
      } else {
        $this->$var = $val;
      }
    } else {
      return FALSE; // value doesn't exist!
    }

    // consentforpublishing and entitytype are dispatched above to setters that
    // decide for themselves whether anything changed, so they never reach here
    if (in_array($var, self::FIELDS, true)) {
      $this->markChanged($var);
    }
    return $this->$var;
  }

  /**
   * get a single variable/setting from class
   *
   * @param string $var variable name
   * @return mixed value of variable
   */
  public function get(string $var): mixed {
    $var = strtolower($var);
    return $this->$var;
  }

  /**
   * set the entity type which may be one of
   *
   * 0 - NON REGISTRANT CONTACT (admin-c/tech-c)
   * 1 - persone fisiche
   * 2 - società/imprese individuali (incluso istituti scolastici paritari gestiti da enti con finalità di lucro)
   * 3 - liberi professionisti
   * 4 - enti no-profit (incluso istituti scolastici paritari gestiti da enti no profit)
   * 5 - enti pubblici (incluso istituti scolastici gestiti da enti pubblici)
   * 6 - altri soggetti
   * 7 - soggetti stranieri equiparati ai precedenti escluso persone fisiche
   *
   * @param int $type entity type
   * @return bool status
   */
  protected function setEntityType(mixed $type): bool|int {
    $tmp = (int)$type;
    if (($tmp < 1) && ($tmp > 7)) {
      $tmp = 0; // failback to the default value
    }

    if ($this->entitytype == $tmp) {
      return FALSE;
    }

    $this->markChanged('entitytype');
    return $this->entitytype = $tmp;
  }

  /**
   * set consent for publishing
   *
   * @return string "true"
   */
  public function setConsent(): bool|int {
    if ($this->consentforpublishing == 1) {
      return FALSE;
    }

    $this->markChanged('consentforpublishing');
    return $this->consentforpublishing = 1;
  }

  /**
   * unset consent for publishing
   *
   * @return string "false"
   */
  public function unsetConsent(): bool|int {
    if ($this->consentforpublishing == 0) {
      return FALSE;
    }

    $this->markChanged('consentforpublishing');
    return $this->consentforpublishing = 0;
  }

  /**
   * check whether contact handles are free to be created
   *
   * @param array|string|null $contact one handle, several, or null for the one set
   * @return CheckResult the registry's answer, or a failure -- see CheckResult
   */
  public function check(array|string|null $contact = null): CheckResult {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ( ! is_array($contact)) {
      $contact = array($contact);
    }
    // array($null) / array("") is a one-element array, so the plain empty()
    // check below never fired for the case it was meant to catch
    $contact = array_values(array_filter($contact, fn($c) => (string)$c !== ""));
    if (empty($contact)) {
      $this->setError("Operation not allowed, set a handle!");
      return CheckResult::failure($this->getError());
    }

    $this->xmlQuery = XmlBuilder::contactCheck(
      $this->client->set_clTRID(),
      array_slice($contact, 0, $this->max_check)
    );

    if ( ! $this->ExecuteQuery("contact-check", implode(";", $contact))) {
      return CheckResult::failure($this->getError());
    }

    $tmp = $this->responseData('contact');
    if ($tmp === null || ! isset($tmp->chkData->cd)) {
      $this->setError("The registry accepted the check but returned no availability data.");
      return CheckResult::failure($this->getError());
    }

    $availability = [];
    foreach ($tmp->chkData->cd as $cd) {
      $available = (string)$cd->id->attributes()->avail === "true";
      $availability[(string)$cd->id] = [
        'available' => $available,
        'reason'    => $available ? 'OK' : (string)$cd->reason,
      ];
    }

    return CheckResult::of($availability);
  }

  /**
   * generate a random, registry-unique contact handle. check() doesn't
   * touch any other instance state, so this can be called on any contact
   * object wired to a live EPP session -- the one being prepared for
   * create() or an unrelated throwaway instance both work.
   *
   * @param int $maxAttempts max attempts before giving up
   * @return string a 16-character handle, confirmed available at the registry
   * @throws   \RuntimeException   if the registry could not be asked, or if no
   *           free handle turned up within $maxAttempts
   */
  public function generateHandle(int $maxAttempts = 5): string {
    for ($i = 0; $i < $maxAttempts; $i++) {
      // hex, not a password charset: a handle is an identifier, not a secret --
      // it lands in REST paths, CSV exports and a foreign key, and only has to
      // avoid colliding, which check() below confirms
      $handle = strtoupper(PasswordService::token(8)); // 16 hex chars
      $answer = $this->check($handle);

      // A check that never happened is not a taken handle. Retrying it four
      // more times only produces the same failure, and reporting it as "no
      // unique handle" sends the reader hunting for a collision that is not
      // there -- an unauthenticated session says exactly this.
      if ( ! $answer->answered()) {
        throw new \RuntimeException("Unable to check handle availability: " . $answer->error());
      }
      if ($answer->available() === TRUE) {
        return $handle;
      }
    }
    throw new \RuntimeException("Unable to generate a unique contact handle after {$maxAttempts} attempts");
  }

  /**
   * create contact
   *
   * @return bool status
   */
  public function create(): bool {
    $this->xmlQuery = XmlBuilder::contactCreate($this->client->set_clTRID(), [
      'id'                   => $this->handle,
      'name'                 => $this->name,
      'org'                  => $this->org,
      'street'               => [$this->street, $this->street2, $this->street3],
      'city'                 => $this->city,
      'sp'                   => $this->province,
      'pc'                   => $this->postalcode,
      'cc'                   => $this->countrycode,
      'voice'                => $this->voice,
      'fax'                  => $this->fax,
      'email'                => $this->email,
      'authinfo'             => $this->authinfo,
      'consentForPublishing' => $this->consentforpublishing,
      'nationalityCode'      => $this->nationalitycode,
      'entityType'           => $this->entitytype,
      'regCode'              => $this->regcode,
      'schoolCode'           => $this->schoolcode,
    ]);

    // query server and return answer (no handling of special return values)
    $response = $this->ExecuteQuery("contact-create", $this->handle);
    if ($response) {
      $this->status = array('ok');
      return $response;
    } else {
      return FALSE;
    }
  }

  /**
   * fetch contact through EPP
   *
   * @param string $contact contact to load
   * @return bool status
   */
  public function fetch(?string $contact = null): bool {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::contactInfo($this->client->set_clTRID(), $contact);

    // re-initialize object data
    $this->initValues();

    // query server
    if ($this->ExecuteQuery("contact-info", $contact)) {
      $this->clearChanges();
      $this->status = array();
      $this->handle = $contact;

      // the postalInfo, not just the infData: a contact without one is not a
      // contact, and reading through it would hand back a row of empty strings
      $tmp = $this->responseData('contact');
      if ($tmp === null || ! isset($tmp->infData->postalInfo->addr)) {
        $this->setError("The registry accepted the query but returned no contact data.");
        return FALSE;
      }

      $this->name =        (string)$tmp->infData->postalInfo->name;
      $this->org =         (string)$tmp->infData->postalInfo->org;
      $this->street =      (string)$tmp->infData->postalInfo->addr->street[0];
      $this->street2 =     (string)$tmp->infData->postalInfo->addr->street[1];
      $this->street3 =     (string)$tmp->infData->postalInfo->addr->street[2];
      $this->city =        (string)$tmp->infData->postalInfo->addr->city;
      $this->province =    (string)$tmp->infData->postalInfo->addr->sp;
      $this->postalcode =  (string)$tmp->infData->postalInfo->addr->pc;
      $this->countrycode = (string)$tmp->infData->postalInfo->addr->cc;
      $this->voice =       (string)$tmp->infData->voice;
      $this->fax =         (string)$tmp->infData->fax;
      $this->email =       (string)$tmp->infData->email;
      foreach ($tmp->infData->status as $singleState) {
        $this->status[] =  (string)$singleState->attributes()->s;
      }

      // the .it registrant details ride along as an extension; a contact that
      // is not a registrant has none
      $extcon = $this->responseExtension('extcon');
      if ($extcon !== null && isset($extcon->infData)) {
        $this->set('consentforpublishing', (string)$extcon->infData->consentForPublishing);
        $this->nationalitycode = (string)($extcon->infData->registrant->nationalityCode ?? '');
        $this->entitytype =         (int)($extcon->infData->registrant->entityType ?? 0);
        $this->regcode =         (string)($extcon->infData->registrant->regCode ?? '');
        $this->schoolcode =      (string)($extcon->infData->registrant->schoolCode ?? '');
      }

      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * delete contact
   *
   * @return bool status
   */
  public function delete(?string $contact = null): bool {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::contactDelete($this->client->set_clTRID(), $contact);

    // query server
    return $this->ExecuteQuery("contact-delete", $contact);
  }

  /**
   * update contact
   *
   * @return bool status
   */
  public function update(): bool {
    if ($this->handle == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }
    if ( ! $this->hasChanges()) {
      $this->setError("Handle did not change!");
      return FALSE;
    }

    // postalinfo
    $postalinfo = array();
    if ($this->changed('name')) {
      $postalinfo[] = array('name' => 'name', 'value' => $this->name);
    }
    if ($this->changed('org')) {
      $postalinfo[] = array('name' => 'org', 'value' => $this->org);
    }

    // address
    $addr = array();
    if ($this->changed(...self::ADDRESS_FIELDS)) {
      // the registry replaces <addr> wholesale, so one changed line means
      // sending all seven
      $addr[] = array('name' => 'street', 'value' => $this->street);
      $addr[] = array('name' => 'street', 'value' => $this->street2);
      $addr[] = array('name' => 'street', 'value' => $this->street3);
      $addr[] = array('name' => 'city', 'value' => $this->city);
      $addr[] = array('name' => 'sp', 'value' => $this->province);
      $addr[] = array('name' => 'pc', 'value' => $this->postalcode);
      $addr[] = array('name' => 'cc', 'value' => $this->countrycode);
    }

    // Contact information: only fields that actually changed appear here.
    //
    // This distinction is the whole point. In EPP an empty <contact:fax/>
    // means "remove the fax number", so the value carried here has to
    // separate "the caller set fax to an empty string" from "the caller never
    // mentioned fax at all". Every field used to be listed unconditionally,
    // with an empty value standing in for "unchanged" -- which the template
    // could not tell apart from a deliberate clear, so simply updating a
    // contact's email also wiped its fax at the registry.
    $contact = array();
    if ($this->changed('voice')) $contact[] = array('name' => 'voice',  'value' => $this->voice);
    if ($this->changed('fax'))   $contact[] = array('name' => 'fax',    'value' => $this->fax);
    if ($this->changed('email')) $contact[] = array('name' => 'email',  'value' => $this->email);

    // registrant information
    $registrant = array();
    if ($this->changed('nationalitycode')) {
      $registrant['nationalityCode'] = $this->nationalitycode;
    }
    if ($this->changed('entitytype')) {
      $registrant['entityType'] = $this->entitytype;
    }
    if ($this->changed('regcode')) {
      $registrant['regCode'] = $this->regcode;
    }
    if ($this->changed('schoolcode')) {
      $registrant['schoolCode'] = $this->schoolcode;
    }

    $this->xmlQuery = XmlBuilder::contactUpdate(
      $this->client->set_clTRID(),
      $this->handle,
      $postalinfo,
      $addr,
      $contact,
      $this->changed('authinfo') ? $this->authinfo : '',
      $this->changed('consentforpublishing') ? (int)$this->consentforpublishing : '',
      $registrant
    );

    // query server
    return $this->ExecuteQuery("contact-update", $this->handle);
  }

  /**
   * update contact status
   *
   * @param string $state clientDeleteProhibited, clientUpdateProhibited
   * @param string $adddel add, rem (optional, defaults to add)
   * @return bool status
   */
  public function updateStatus(string $state, string $adddel = "add"): bool {
    if ($this->handle == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }

    switch ($state) {
      case "clientDeleteProhibited":
      case "clientUpdateProhibited":
        break;
      default:
        $this->setError("State '".$state."' not allowed, expecting one of 'clientDeleteProhibited' or 'clientUpdateProhibited'.");
        return FALSE;
    }

    switch ($adddel) {
      case "add":
        $this->status = array_merge($this->status, array($state));
        break;
      case "rem":
        $this->status = array_diff($this->status, array($state));
        break;
      default:
        $this->setError("Function '".$adddel."' not allowed, expecting either 'add' or 'rem'.");
        return FALSE;
        break;
    }

    $this->xmlQuery = XmlBuilder::contactStatus($this->client->set_clTRID(), $this->handle, $adddel, $state);

    // query server
    $result = $this->ExecuteQuery("contact-status", $this->handle);
    if ($result) {
      $this->clearChanges();
    }
    return $result;
  }

  /**
   * store contact to DB
   *
   * Upserts: a handle that already exists locally is updated in place instead
   * of failing on the UNIQUE key. It was previously an INSERT only, so
   * re-storing a known contact always threw, was swallowed, and returned
   * FALSE -- which is why re-running an import reported 'not stored' for every
   * registrant it had already seen.
   *
   * Unlike Domain::storeDB() this cannot delete-then-insert: domains.registrant
   * is a foreign key onto contacts.handle, so removing the row would be
   * rejected for any contact currently used as a registrant.
   *
   * Two things are deliberately left alone when updating an existing row:
   * `user_id` (re-importing somebody else's contact must not silently reassign
   * ownership -- $user_id applies to new rows only) and `active` (a contact
   * deactivated on purpose by deleteContactDB() should not be resurrected as a
   * side effect of an import).
   *
   * @param int $user_id user ACL, applied to newly created rows only
   * @return bool status
   */
  public function storeDB(int $user_id = 1): bool {
    $data = ['status' => serialize($this->status)];
    foreach (self::FIELDS as $field) {
      $data[$field] = $this->$field;
    }

    $existing = R::getRow("SELECT id FROM contacts WHERE handle = ?", [$this->handle]);

    if (empty($existing)) {
      $data['handle'] = $this->handle;
      $data['user_id'] = $user_id;

      if ( ! $this->storageInsert($data, $this->handle)) {
        return FALSE;
      }
    } else {
      // isAdmin: an upsert is not a scoped write. $user_id says who owns a
      // *new* row, not who is allowed to touch an existing one -- the two
      // fields left out of $data above are exactly the ones that would move.
      if ( ! $this->storageUpdate($this->handle, $data, $user_id, true)) {
        return FALSE;
      }
    }

    Changelog::record(
      'contacts', $this->storageId($this->handle),
      empty($existing) ? 'create' : 'update', ['handle' => $this->handle], $user_id
    );
    return TRUE;
  }

  /**
   * load contact from DB
   *
   * @param string $contact contact to load
   * @param int $user_id user ACL
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @return bool status
   */
  public function loadDB(?string $contact = null, int $user_id = 1, bool $isAdmin = false): bool {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    // re-initialize object data
    $this->initValues();

    $row = $this->storageFind($contact, $user_id, $isAdmin);
    if ($row === null) {
      $this->setError("Contact '{$contact}' not found.");
      return FALSE;
    }

    // 'status' is the only serialized column here; it carries no change bit,
    // being set by the registry rather than by a caller
    $this->storageHydrate($row, ['status']);
    $this->clearChanges();
    return TRUE;
  }

  /**
   * update contact stored in DB
   *
   * @param string $contact contact to update
   * @param int $user_id user ACL
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @return bool status
   */
  public function updateDB(?string $contact = null, int $user_id = 1, bool $isAdmin = false): bool {
    if ($contact === null) {
      $contact = $this->handle;
    }
    if ($contact == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }
    if ( ! $this->hasChanges()) {
      $this->setError("Handle did not change!");
      return FALSE;
    }

    $data = array(
      'status'  => serialize($this->status),
      'user_id' => $user_id,
    );
    foreach (self::FIELDS as $field) {
      if ($this->changed($field)) {
        $data[$field] = $this->$field;
      }
    }

    if ( ! $this->storageUpdate($contact, $data, $user_id, $isAdmin)) {
      return FALSE;
    }

    Changelog::record('contacts', $this->storageId($contact), 'update', $data, $user_id);
    return TRUE;
  }

  /**
   * list contacts stored in DB
   *
   * @param int $user_id user ACL (optional), defaults to 1
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @param bool $activeOnly list only active contacts (TRUE = yes / FALSE = no)
   * @return array list of contacts
   */
  public function listContacts(int $user_id = 1, bool $isAdmin = false, bool $activeOnly = TRUE): array {
    $where = ['1 = 1'];
    $params = [];
    if ( ! $isAdmin) {
      $where[] = 'user_id = :user_id';
      $params[':user_id'] = $user_id;
    }
    if ($activeOnly) {
      $where[] = 'active = 1';
    }
    return R::getAll("SELECT handle, org, name, user_id FROM contacts WHERE " . implode(' AND ', $where) . " ORDER BY org, name ASC", $params);
  }

  /**
   * deactivate a contact stored in DB (soft delete)
   *
   * @param string $contact contact name / handle
   * @param int $user_id user ACL (optional), defaults to 1
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @return bool status
   */
  public function deleteContactDB(string $contact, int $user_id = 1, bool $isAdmin = false): bool {
    // refuses while the contact is still some active domain's registrant --
    // the foreign key would reject it anyway, and this says so first
    return $this->storageSetActive(
      $contact, 0, $user_id, $isAdmin, 'delete', ['handle' => $contact],
      ' AND (SELECT COUNT(1) FROM domains WHERE registrant = :registrant AND active = 1) = 0',
      [':registrant' => $contact]
    );
  }

  /**
   * reactivate a contact stored in DB (undo a soft delete)
   *
   * @param string $contact contact name / handle
   * @param int $user_id user ACL (optional), defaults to 1
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @return bool status
   */
  public function restoreContactDB(string $contact, int $user_id = 1, bool $isAdmin = false): bool {
    // logged as 'update': the changelog action enum has no 'restore'
    return $this->storageSetActive(
      $contact, 1, $user_id, $isAdmin, 'update', ['handle' => $contact, 'active' => 1]
    );
  }

  /**
   * create a brand-new EPP contact copying this contact's data (already
   * fetch()ed), under a new local owner
   *
   * @param Client $nic a live client (used to construct the new Contact object)
   * @param int $newOwnerId the new contact's local owner (users.id)
   * @return string|false the new contact's handle, or false on failure
   */
  public function duplicate(Client $nic, int $newOwnerId): string|false {
    $fields = [
      'name', 'org', 'street', 'street2', 'street3', 'city', 'province',
      'postalcode', 'countrycode', 'voice', 'fax', 'email',
      'nationalitycode', 'entitytype', 'regcode', 'schoolcode',
    ];

    $new = new self($nic);
    $new->set('handle', $new->generateHandle());
    foreach ($fields as $field) {
      $value = $this->get($field);
      if ($value === '' || $value === null) {
        continue;
      }
      // get() returns the already-escaped value set() stored -- undo one
      // layer before re-escaping it, or it double-encodes on every copy
      $new->set($field, html_entity_decode((string) $value, ENT_COMPAT, 'UTF-8'));
    }
    $new->set('authinfo', $new->authinfo());

    if ( ! $new->create()) {
      return false;
    }
    $new->storeDB($newOwnerId);
    return $new->get('handle');
  }
}

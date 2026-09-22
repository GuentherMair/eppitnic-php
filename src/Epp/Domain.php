<?php

namespace Eppitnic\Epp;

use Algo26\IdnaConvert\ToIdn;
use Eppitnic\Persistence\History;

use Eppitnic\Persistence\ChangeTracking;
use Eppitnic\Persistence\LocalStorage;
use Eppitnic\Persistence\SerializedColumn;
use RedBeanPHP\R;

/**
 * This class handles domain objects.
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
 * @package     Eppitnic\Epp\Domain
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Domain extends AbstractObject
{
  use ChangeTracking;
  use LocalStorage;

  protected static function storageTable(): string { return 'domains'; }
  protected static function storageKeyColumn(): string { return 'domain'; }
  protected static function storageNoun(): string { return 'domain'; }

  /**
   * The domain's own fields, and whether each is stored serialized. One list
   * where the set was spelled out in the properties, set(), updateDB() and
   * storeDB() alike.
   */
  public const FIELDS = array(
    'ns'         => true,
    'registrant' => false,
    'admin'      => false,
    'tech'       => true,
    'authinfo'   => false,
    'dnssec'     => true,
  );

  /**
   * Fields set() must not mark dirty itself: a collection is changed through
   * addNS()/addTECH()/addDNSSEC(), which know whether anything moved.
   */
  private const FIELDS_WITH_ADDERS = array('ns', 'tech', 'dnssec');

  /** the client-side states a domain accepts, for updateStatus() */
  private const CLIENT_STATES = array(
    'clientDeleteProhibited', 'clientUpdateProhibited', 'clientTransferProhibited',
    'clientHold', 'clientLock',
  );

  protected $user_id;           // use just in case of an updateRegistrant + change of agent
  protected $status;            // domain states (ok, clientDeleteProhibited, clientUpdateProhibited, clientTransferProhibited, clientHold, clientLock + server-side states)
  protected $domain;            // -

  protected $ns;
  protected $registrant;
  protected $admin;
  protected $tech;
  protected $authinfo;
  protected $dnssec;

  // domain lifecycle
  protected $crDate;
  protected $exDate;

  // these are for internal use only (ie. update)
  protected $ns_initial;
  protected $admin_initial;
  protected $tech_initial;
  protected $dnssec_initial;

  // domain transfer information
  protected $trStatus;
  protected $reID;
  protected $acID;

  // infContacts
  protected $infcontacts;

  // IDN <=> punycode converter class
  protected $idn;

  // DNSSEC status (enabled or not)
  protected $dnssec_status;

  /**
   * Class constructor
   *
   * (initializes authinfo)
   *
   * @param Client $client client class
   */
  function __construct(Client $client) {
    parent::__construct($client);

    $this->initValues();
    $this->idn = new ToIdn();
    $this->dnssec_status = @isset($this->client->EPPCfg->dnssec->active) ? (int)$this->client->EPPCfg->dnssec->active : 0;
  }

  /**
   * initialize values
   */
  protected function initValues(): void {
    $this->user_id           = 1;
    $this->status            = array();
    $this->domain            = "";
    $this->registrant        = "";
    $this->admin             = "";
    $this->admin_initial     = "";
    $this->tech              = array();
    $this->tech_initial      = array();
    $this->ns                = array();
    $this->ns_initial        = array();
    $this->authinfo          = $this->authinfo();
    $this->dnssec            = array();
    $this->dnssec_initial    = array();
    $this->clearChanges();
    $this->crDate            = date("Y-m-d");
    $this->exDate            = date("Y-m-d", strtotime("+1 year"));
    $this->trStatus          = "";
    $this->reID              = "";
    $this->acID              = "";
    $this->infcontacts       = array();
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

    // 'ns' and 'tech' are collections: dispatch before the cast below, which
    // would TypeError on an array. Passing one is a caller error either way,
    // but it should return FALSE rather than fatal
    if ($var == "ns" || $var == "tech") {
      if (is_array($val)) {
        $this->setError("set('{$var}', ...) takes a single value; use add" . strtoupper($var) . "() per entry.");
        return FALSE;
      }
      $val = (string)$val;
      return ($var == "ns") ? $this->addNS($val) : $this->addTECH($val);
    }

    // stored as given -- see the note in Contact::set()
    $val = (string)$val;

    if (isset($this->$var)) {
      if ($this->$var == $val) {
        return FALSE; // value didn't change!
      } else {
        $this->$var = $val;
      }
    } else {
      return FALSE; // value doesn't exist or cannot be set using set($var, $val)!
    }

    if (isset(self::FIELDS[$var]) && ! in_array($var, self::FIELDS_WITH_ADDERS, true)) {
      $this->markChanged($var);
    }
    return $this->$var;
  }

  /**
   * Take the current values as the new baseline, wherever this object has just
   * agreed with the registry or the database. One method because it was four,
   * and update()'s had drifted -- missing dnssec_initial, so it re-sent DNSSEC.
   */
  private function resetChangeTracking(): void {
    $this->clearChanges();
    $this->ns_initial     = $this->ns;
    $this->admin_initial  = $this->admin;
    $this->tech_initial   = $this->tech;
    $this->dnssec_initial = $this->dnssec;
  }

  /**
   * One <extdom:infContactsData> entry -- registrant or admin/tech contact --
   * flattened into the shape infcontacts holds. As two copies, a field added
   * to one was simply absent from the other half of the answer.
   *
   * @param \SimpleXMLElement $entry the <registrant> or <contact> element
   * @param string $type 'registrant', or the <contact type="..."> attribute
   * @param array<string, string> $ns the response's namespace map
   * @return array<string, string>
   */
  private static function linkedContact(\SimpleXMLElement $entry, string $type, array $ns): array {
    $infContact = $entry->infContact->children($ns['contact']);
    $extInfo    = $entry->extInfo->children($ns['extcon']);

    return array(
      'type'                 => $type,
      'id'                   => (string)$infContact->id,
      'name'                 => (string)$infContact->postalInfo->name,
      'org'                  => (string)$infContact->postalInfo->org,
      'street'               => (string)$infContact->postalInfo->addr->street[0],
      'street2'              => (string)$infContact->postalInfo->addr->street[1],
      'street3'              => (string)$infContact->postalInfo->addr->street[2],
      'city'                 => (string)$infContact->postalInfo->addr->city,
      'province'             => (string)$infContact->postalInfo->addr->sp,
      'postalcode'           => (string)$infContact->postalInfo->addr->pc,
      'countrycode'          => (string)$infContact->postalInfo->addr->cc,
      'voice'                => (string)$infContact->voice,
      'fax'                  => (string)$infContact->fax,
      'email'                => (string)$infContact->email,
      'consentforpublishing' => (string)$extInfo->consentForPublishing,
      'nationalitycode'      => (string)$extInfo->registrant->nationalityCode,
      'entitytype'           => (string)$extInfo->registrant->entityType,
      'regcode'              => (string)$extInfo->registrant->regCode,
    );
  }

  /**
   * remove a DNSSEC data set
   *
   * @param string $digest the digest value identifying which DNSSEC record to
   *               remove
   * @return string|false the digest on success, false on failure
   */
  public function remDNSSEC(string $digest): string|false {
    if (isset($this->dnssec_initial[$digest])) {
      $this->markChanged('dnssec');
      unset($this->dnssec[$digest]);
      return $digest;
    } else {
      $this->setError("The keytag you provided is not currently associated to this domain object.");
      return FALSE;
    }
  }

  /**
   * add a DNSSEC data set
   *
   * @param string $keytag keytag
   * @param string $algorithm algorithm
   * @param string $digesttype digesttype
   * @param string $digest digest
   * @return string|false the digest on success, false on failure
   */
  public function addDNSSEC(string $keytag, string $algorithm, string $digesttype, string $digest): string|false {
    // don't allow empty values
    if (empty($keytag) || empty($algorithm) || empty($digesttype) || empty($digest)) {
      $this->setError("All values (keytag, algorithm, digesttype, digest) must be given and must NOT be empty.");
      return FALSE;
    }

    // what to do if the keytag exists...
    if (isset($this->dnssec[$digest])) {
      // ... and all values are the same? Then stop here!
      if ($this->dnssec[$digest]['algorithm']  == $algorithm &&
          $this->dnssec[$digest]['digesttype'] == $digesttype &&
          $this->dnssec[$digest]['keytag']     == $keytag) {
        $this->setError("The provided DNSSEC information already exists.");
        return FALSE;
      }
    } else if (count($this->dnssec) >= 2) {
      $this->setError("Only two DNSSEC sets may be provided. Please remove one of those first.");
      return FALSE;
    }

    $this->markChanged('dnssec');
    $this->dnssec[$digest] = array(
      'algorithm'  => $algorithm,
      'digesttype' => $digesttype,
      'keytag'     => $keytag,
    );

    return $digest;
  }

  /**
   * get a single variable/setting from class
   *
   * 'tech' always comes back as an array (handle => handle), even for one: as a
   * bare string, array_keys((array) ...) gave [0] and reported a tech of "0".
   *
   * @param string $var variable name
   * @return mixed value of variable
   */
  public function get(string $var): mixed {
    return $this->$var;
  }

  /**
   * remove a technical contact
   *
   * @param string $name tech contact name
   * @return string|false value removed or FALSE if variable name does not exist
   */
  public function remTECH(string $name): string|false {
    if (isset($this->tech[$name])) {
      unset($this->tech[$name]);
      $this->markChanged('tech');
      return $name;
    } else {
      return FALSE;
    }
  }

  /**
   * add a technical contact
   *
   * @param string $name tech contact name
   * @return string|false value set or FALSE if there was an error
   */
  public function addTECH(string $name): string|false {
    if (empty($name)) {
      return FALSE;
    }

    // assign technical contact
    if ( ! isset($this->tech[$name])) {
      $this->tech[$name] = $name;
      $this->markChanged('tech');
    }
    return $name;
  }

  /**
   * remove a nameserver
   *
   * @param string $name NS name
   * @return string|false value set or FALSE if variable name does not exist
   */
  public function remNS(string $name): string|false {
    // DNS names must be in punycode format (if below an IDN domain)
    $name = $this->idn->convert($name);
    if (isset($this->ns[$name])) {
      unset($this->ns[$name]);
      $this->markChanged('ns');
      return $name;
    } else {
      return FALSE;
    }
  }

  /**
   * add a nameserver
   *
   * @param string $name NS name
   * @param mixed $addr ip addresses to set (an array of two, one or a string)
   * @return string|false value set or FALSE on error
   */
  public function addNS(string $name, array|string|null $addr = null): string|false {
    $dns1 = "";
    $dns2 = "";
    $ip_changed = FALSE;

    // don't allow empty values
    if (empty($name)) {
      return FALSE;
    }

    // DNS names must be in punycode format (if below an IDN domain)
    $name = $this->idn->convert($name);

    // handle IP addresses (if set)
    if (is_array($addr)) {
      switch (count($addr)) {
        case 2:
          $dns1 = strtolower($addr[0]);
          $dns2 = strtolower($addr[1]);
          break;
        case 1:
          $dns1 = strtolower($addr[0]);
          break;
        case 0:
          break;
        default:
          $this->setError("The address must be an array of one or two elements.");
          return FALSE;
          break;
      }
    } else if ( ! empty($addr)) {
      $dns1 = $addr;
    }

    // if a nameserver by this name was already set and IPs didn't change stop
    // here
    if (isset($this->ns[$name])) {
      // every address on this NS record. 'ip' is absent for a glueless
      // nameserver -- the ordinary case -- so re-adding one warned twice and
      // passed null to foreach()
      $ip_list = array();
      foreach ($this->ns[$name]['ip'] ?? array() as $ip) {
        $ip_list[] = $ip['address'];
      }

      // verify if a new IP was added to this NS record
      if ( ! empty($dns1) && ! in_array($dns1, $ip_list)) {
        $ip_changed = TRUE;
      }
      if ( ! empty($dns2) && ! in_array($dns2, $ip_list)) {
        $ip_changed = TRUE;
      }

      // if any new IP was added, remove the NS record first, then procede else
      // there was no change and we bail out
      if ($ip_changed) {
        $this->remNS($name);
      } else {
        return $name;
      }
    }

    // assign NS name
    $this->ns[$name]['name'] = $name;

    // assign IP address 1 (if set)
    if ( ! empty($dns1)) {
      if (@gethostbyaddr($dns1) == "") {
        $this->setError("Address '".$dns1."' is not a valid IPv4 or IPv6 address.");
        return FALSE;
      } else {
        $type = strpos($dns1, '.') ? 'v4' : 'v6';
        $this->ns[$name]['ip'][] = array('type' => $type, 'address' => $dns1);
      }
    }

    // assign IP address 2 (if set)
    if ( ! empty($dns2)) {
      if (@gethostbyaddr($dns2) == "") {
        $this->setError("Address '".$dns2."' is not a valid IPv4 or IPv6 address.");
        return FALSE;
      } else {
        $type = strpos($dns2, '.') ? 'v4' : 'v6';
        $this->ns[$name]['ip'][] = array('type' => $type, 'address' => $dns2);
      }
    }

    // if we get to this point, something has changed
    $this->markChanged('ns');
    return $name;
  }

  /**
   * check whether domains are available for registration
   *
   * @param array|string|null $domain one name, several, or null for the one set
   * @return CheckResult the registry's answer, or a failure -- see CheckResult
   */
  public function check(array|string|null $domain = null): CheckResult {
    $result = $this->checkAvailability(
      $domain,
      $this->domain,
      "Operation not allowed, set a domain name first!",
      'domain',
      'name',
      fn(string $clTRID, array $names) => XmlBuilder::domainCheck($clTRID, $names)
    );

    // The one thing Contact's check does not do, so it stays here rather than
    // in the shared method: kept for callers reading svMsg after a single-name
    // check.
    $availability = $result->all();
    if (count($availability) === 1) {
      $only = array_values($availability)[0];
      if ( ! $only['available']) {
        $this->svMsg = $only['reason'];
      }
    }

    return $result;
  }

  /**
   * create domain
   *
   * @return bool status
   */
  public function create(): bool {
    $this->xmlQuery = XmlBuilder::domainCreate(
      $this->client->set_clTRID(),
      $this->domain,
      $this->ns,
      $this->registrant,
      $this->admin,
      $this->tech,
      $this->authinfo,
      ($this->dnssec_status == 1) ? $this->dnssec : array()
    );

    // query server and return answer (no handling of special return values)
    if ($this->ExecuteQuery("domain-create", $this->domain)) {
      $this->resetChangeTracking();
      $this->status = array('ok');
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * fetch domain through EPP
   *
   * @param string $domain domain to load
   * @param string $authinfo authinfo string (domain sponsored by other
   *               registrar)
   * @param string $infContacts restrict linked-contact info to this type
   *               ('all', 'registrant', 'admin', 'tech', or blank for none)
   * @return bool status
   */
  public function fetch(?string $domain = null, ?string $authinfo = null, string $infContacts = ''): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }

    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }

    $infContacts = strtolower($infContacts);
    if ( ! in_array($infContacts, array("all", "registrant", "admin", "tech"))) {
      $infContacts = '';
    }

    // if authinfo was not given as an argument, but has been set
    if (($authinfo === null) && $this->changed('authinfo')) {
      $authinfo = $this->authinfo;
    }

    $this->xmlQuery = XmlBuilder::domainInfo(
      $this->client->set_clTRID(),
      $domain,
      empty($authinfo) ? '' : $authinfo,
      $infContacts
    );

    // re-initialize object data
    $this->initValues();

    // query server
    if ($this->ExecuteQuery("domain-info", $domain)) {
      $tmp = $this->responseData('domain');
      if ($tmp === null || ! isset($tmp->infData)) {
        $this->setError("The registry accepted the query but returned no domain data.");
        return FALSE;
      }
      $ns = $this->xmlResult->getNamespaces(TRUE);

      $this->domain = $domain;
      $this->status = array();

      $this->registrant = (string)$tmp->infData->registrant;
      $this->authinfo = (string)$tmp->infData->authInfo->pw;
      $this->crDate = (string)$tmp->infData->crDate;
      $this->exDate = (string)$tmp->infData->exDate;
      foreach ($tmp->infData->status as $singleState) {
        $this->status[] = (string)$singleState->attributes()->s;
      }
      foreach ($tmp->infData->contact as $contact) {
        $type = $contact->attributes()->type;
        if ($type == "tech") {
          $this->addTECH((string)$contact);
        } else if ($type == "registrant" or $type == "admin") {
          $this->$type = (string)$contact;
        } else {
          // undefined
        }
      }

      // if the NS were not properly configured EPP will not report them yet!
      if (@is_object($tmp->infData->ns->hostAttr[0])) {
        foreach ($tmp->infData->ns->hostAttr as $hostAttr) {
          $addr = array();
          foreach ($hostAttr->hostAddr as $ip) {
            $addr[] = strtolower((string)$ip);
          }
          $this->addNS((string)$hostAttr->hostName, $addr);
        }
      }

      // if extsecDNS and secDNS are set
      $secDNS = $this->responseExtension('secDNS');
      if ($secDNS !== null) {
        foreach ($secDNS->infData->dsData as $dsData) {
          $this->addDNSSEC((int)$dsData->keyTag, (int)$dsData->alg, (int)$dsData->digestType, (string)$dsData->digest);
        }
      }

      // if infContactsData is set
      $tmp = $this->responseExtension('extdom');
      if ($tmp !== null) {

        // verify extended states
        if (@is_object($tmp->infData->ownStatus)) {
          foreach ($tmp->infData->ownStatus as $singleState) {
            $this->status[] = (string)$singleState->attributes()->s;
          }
        }

        // fetch contact information
        $this->infcontacts = array();
        if (@is_object($tmp->infContactsData->registrant)) {
          $this->infcontacts[] = self::linkedContact($tmp->infContactsData->registrant, 'registrant', $ns);
        }
        if (@is_object($tmp->infContactsData->contact[0])) {
          foreach ($tmp->infContactsData->contact as $contact) {
            $this->infcontacts[] = self::linkedContact($contact, (string)$contact->attributes()->type, $ns);
          }
        }
      }

      // reset changes at the bottom
      $this->resetChangeTracking();
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * print domain status - the states will be set after a call to fetch()
   *
   * @return array|false server side state (array of status strings, or FALSE)
   */
  public function state(): array|false {
    return ($this->status === null) ? FALSE : $this->status;
  }

  /**
   * delete domain
   *
   * @param string $domain domain name to delete
   * @return bool status
   */
  public function delete(?string $domain = null): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainDelete($this->client->set_clTRID(), $domain);

    // query server
    return $this->ExecuteQuery("domain-delete", $domain);
  }

  /**
   * update domain
   *
   * @return bool status
   */
  public function update(): bool {
    if ($this->domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }
    if ( ! $this->hasChanges()) {
      $this->setError("Domain did not change!");
      return FALSE;
    }
    if ($this->changed('registrant')) {
      $this->setError("Update the registrant through updateRegistrant()!");
      return FALSE;
    }

    $add = array();
    $remove = array();

    if ($this->changed('ns')) {
      // the registry accepts at most six
      $this->ns = array_slice($this->ns, 0, 6);

      // Compare on name+addresses, not name alone: re-pointing a glued
      // nameserver at a new address is a removal and an addition, and
      // comparing names would see no change at all.
      $signature = function (array $set): array {
        $out = array();
        foreach ($set as $name => $values) {
          $key = $name;
          foreach ($values['ip'] ?? array() as $addr) {
            $key .= ";" . $addr['address'];
          }
          $out[$key] = $name;
        }
        return $out;
      };

      $now = $signature($this->ns);
      $before = $signature($this->ns_initial);

      foreach (array_diff_key($now, $before) as $name) {
        $add['ns'][$name] = $this->ns[$name];
      }
      foreach (array_diff_key($before, $now) as $name) {
        $remove['ns'][$name] = $this->ns_initial[$name];
      }
    }

    if ($this->changed('admin')) {
      $add['admin'] = $this->admin;
      $remove['admin'] = $this->admin_initial;
    }

    if ($this->changed('tech')) {
      // at most six technical contacts, as with the nameservers
      $this->tech = array_slice($this->tech, 0, 6);
      $add['tech'] = array_diff($this->tech, $this->tech_initial);
      $remove['tech'] = array_diff($this->tech_initial, $this->tech);
    }

    $dnssecAdd = array();
    $dnssecRemove = array();
    if ($this->changed('dnssec')) {
      // the registry accepts at most two DS records
      $this->dnssec = array_slice($this->dnssec, 0, 2, true);
      $dnssecAdd = array_diff_key($this->dnssec, $this->dnssec_initial);
      $dnssecRemove = array_diff_key($this->dnssec_initial, $this->dnssec);
    }

    $this->xmlQuery = XmlBuilder::domainUpdate(
      $this->client->set_clTRID(),
      $this->domain,
      $add,
      $remove,
      array('authinfo' => $this->changed('authinfo') ? $this->authinfo : ''),
      $dnssecAdd,
      $dnssecRemove
    );

    // query server
    if ($this->ExecuteQuery("domain-update", $this->domain)) {
      $this->resetChangeTracking();
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * update domain registrant
   *
   * @return bool status
   */
  public function updateRegistrant(): bool {
    if ($this->domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }
    if ( ! $this->changed('registrant') || ! $this->changed('authinfo')) {
      $this->setError("You MUST update the registrant and authinfo variables!");
      return FALSE;
    }

    // updateRegistrant() carries only the registrant, its authinfo and the
    // admin contact; nameserver and technical-contact changes are ignored by
    // the registry on this command and belong to update()
    $add = array();
    $remove = array();
    if ($this->changed('admin')) {
      $add['admin'] = $this->admin;
      $remove['admin'] = $this->admin_initial;
    }

    $this->xmlQuery = XmlBuilder::domainUpdate(
      $this->client->set_clTRID(),
      $this->domain,
      $add,
      $remove,
      array('registrant' => $this->registrant, 'authinfo' => $this->authinfo)
    );

    // query server
    return $this->ExecuteQuery("domain-update", $this->domain);
  }

  /**
   * update domain status
   *
   * @param string $state clientDeleteProhibited, clientUpdateProhibited,
   *               clientTransferProhibited, clientHold, clientLock
   * @param string $adddel add, rem (optional, defaults to add)
   * @return bool status
   */
  public function updateStatus(string $state, string $adddel = "add"): bool {
    if ($this->domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }

    if ( ! $this->applyStatusChange(self::CLIENT_STATES, $state, $adddel)) {
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainStatus($this->client->set_clTRID(), $this->domain, $adddel, $state);

    // query server
    return $this->ExecuteQuery("domain-status", $this->domain);
  }

  /**
   * restore domain
   *
   * @param string $domain domain name to restore
   * @return bool status
   */
  public function restore(?string $domain = null): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainRestore($this->client->set_clTRID(), $domain);

    // query server
    return $this->ExecuteQuery("domain-restore", $domain);
  }

  /**
   * store domain to DB
   *
   * @param int $user_id user ACL
   * @param bool $notifyDNS fire the DNS-sync 'create' event (default yes; a
   *                     requested-but-not-yet-completed transfer-in passes
   *                     false here, since we don't operate the zone yet)
   * @return bool status
   */
  public function storeDB(int $user_id = 1, bool $notifyDNS = true): bool {
    $data = [
      'status' => serialize($this->status),
      'domain' => $this->domain,
    ];
    foreach (self::FIELDS as $field => $serialized) {
      $data[$field] = $serialized ? serialize($this->$field) : $this->$field;
    }
    $data['cr_date'] = $this->crDate;
    $data['ex_date'] = $this->exDate;

    // replaced rather than updated (re-transfer-in / re-register / re-import),
    // preserving last_invoice and the current owner
    $row = R::getRow("SELECT last_invoice, user_id FROM domains WHERE domain = ?", [$this->domain]);
    if ( ! empty($row)) {
      $data['last_invoice'] = $row['last_invoice'];
      $user_id = $row['user_id'];
      R::exec("DELETE FROM domains WHERE domain = ?", [$this->domain]);
    }

    $data['user_id'] = $user_id;
    if ( ! $this->storageInsert($data, $this->domain)) {
      return FALSE;
    }

    History::record('domains', $this->storageId($this->domain), 'create', ['domain' => $this->domain], $user_id);

    if ($notifyDNS) {
      // DNS-sync queue: `eppitnic pdns sync` picks this up to (re)create the
      // zone. The SELECT is the gate -- a row is only written when
      // pdnsutil_path is actually configured, so nothing queues up for a
      // sync job nobody is going to schedule.
      R::exec("
        INSERT INTO tasks (domain, date, notice, object, action)
        SELECT ?, CURRENT_DATE, ?, 'pdns', 'create' FROM settings
        WHERE `key` = 'pdnsutil_path' AND value NOT IN ('null', '\"\"', '')
      ", [$this->domain, 'domain created']);
    }

    return TRUE;
  }

  /**
   * load domain from DB
   *
   * @param string $domain domain to load
   * @param int $user_id user ACL
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @return bool status
   */
  public function loadDB(?string $domain = null, int $user_id = 1, bool $isAdmin = false): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name!");
      return FALSE;
    }

    // re-initialize object data
    $this->initValues();

    $row = $this->storageFind($domain, $user_id, $isAdmin);
    if ($row === null) {
      $this->setError("Domain '{$domain}' not found.");
      return FALSE;
    }

    // 'status' carries no change bit -- it is set by the registry rather than
    // by a caller -- but is stored serialized like the rest
    $serialized = array_keys(array_filter(self::FIELDS));
    $serialized[] = 'status';
    $this->storageHydrate($row, $serialized);

    // initialize data
    $this->resetChangeTracking();
    return TRUE;
  }

  /**
   * update domain stored in DB
   *
   * @param string $domain domain to update
   * @param int $user_id user ACL
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @param array|null $changes the fields to persist (defaults to whatever is
   *                     currently changed). Pass it explicitly when update()
   *                     has already run: it clears the set once the registry
   *                     has accepted the change, before updateDB() can read it.
   * @return bool status
   */
  public function updateDB(?string $domain = null, int $user_id = 1, bool $isAdmin = false, ?array $changes = null): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }

    if ($domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }

    if ($changes === null) {
      $changes = $this->changedFields();
    }

    if ($changes === array()) {
      $this->setError("Domain did not change!");
      return FALSE;
    }

    $data = array(
      'status'  => serialize($this->status),
      'user_id' => $user_id,
    );
    foreach (self::FIELDS as $field => $serialized) {
      if (in_array($field, $changes, true)) {
        $data[$field] = $serialized ? serialize($this->$field) : $this->$field;
      }
    }

    if (in_array('registrant', $changes, true)) {
      // a registrant change moves the domain to that contact's owner. It is
      // the caller's job to have checked they may use it -- see
      // canUseAsRegistrant() in src/Api/Routes/domain.php
      $tmp = new Contact($this->client);
      $tmp->loadDB($this->registrant, $user_id, true);
      $data['user_id'] = $tmp->get('user_id');
    }

    $data['cr_date'] = $this->crDate;
    $data['ex_date'] = $this->exDate;

    if ( ! $this->storageUpdate($domain, $data, $user_id, $isAdmin)) {
      return FALSE;
    }

    History::record('domains', $this->storageId($domain), 'update', $data, $user_id);

    // DNS-sync queue: only nameserver changes require a pdnsutil update, and
    // only when pdnsutil_path is actually configured -- see storeDB()
    if (in_array('ns', $changes, true)) {
      R::exec("
        INSERT INTO tasks (domain, date, notice, object, action)
        SELECT ?, CURRENT_DATE, ?, 'pdns', 'update' FROM settings
        WHERE `key` = 'pdnsutil_path' AND value NOT IN ('null', '\"\"', '')
      ", [$domain, 'nameservers changed']);
    }

    return TRUE;
  }

  /**
   * transfer status
   *
   * @param string $domain domain to transfer
   * @param string $authinfo domain authinfo code
   * @return bool status
   */
  public function transferStatus(?string $domain, ?string $authinfo = ""): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }
    // if authinfo was not given as an argument, but has been set
    if (($authinfo === null) && $this->changed('authinfo')) {
      $authinfo = $this->authinfo;
    }

    $this->xmlQuery = XmlBuilder::domainTransferQuery(
      $this->client->set_clTRID(),
      $domain,
      empty($authinfo) ? '' : $authinfo
    );

    // query server
    if ($this->ExecuteQuery("domain-transfer-query", $domain)) {
      $tmp = $this->responseData('domain');
      if ($tmp === null || ! isset($tmp->trnData->trStatus)) {
        $this->setError("The registry accepted the query but returned no transfer data.");
        return FALSE;
      }

      $this->trStatus = (string)$tmp->trnData->trStatus;
      $this->reID = (string)($tmp->trnData->reID ?? '');
      $this->acID = (string)($tmp->trnData->acID ?? '');
      return TRUE;
    } else {
      if (isset($this->xmlResult->response->result->extValue->reason)) {
        $this->svMsg = (string)$this->xmlResult->response->result->extValue->reason;
      }
      return FALSE;
    }
  }

  /**
   * transfer domain / transfer-trade domain
   *
   * @param string $domain domain to transfer
   * @param string $authinfo domain authinfo code
   * @param string $newregistrant new registrant (optional / trade)
   * @param string $newauthinfo new authinfo (optional)
   * @param string $operation transfer type (defaults to "request")
   * @return bool status
   */
  public function transfer(?string $domain, ?string $authinfo, string $newregistrant = "", string $newauthinfo = "", string $operation = "request"): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }

    if ($authinfo === null) {
      $authinfo = $this->authinfo;
    }
    if ($authinfo == "") {
      $this->setError("Operation not allowed, state the domain authinfo!");
      return FALSE;
    }

    $this->xmlQuery = XmlBuilder::domainTransfer(
      $this->client->set_clTRID(),
      $domain,
      $authinfo,
      $operation,
      $newregistrant,
      empty($newauthinfo) ? $this->authinfo() : $newauthinfo
    );

    // query server
    return $this->ExecuteQuery("domain-transfer-".$operation, $domain);
  }

  /**
   * approve domain transfer to another registrar
   *
   * @param string $domain domain to operate on
   * @param string $authinfo domain authinfo code
   * @return bool status
   */
  public function transferApprove(string $domain, string $authinfo): bool {
    return $this->transfer($domain, $authinfo, "", "", "approve");
  }

  /**
   * reject domain transfer to another registrar
   *
   * @param string $domain domain to operate on
   * @param string $authinfo domain authinfo code
   * @return bool status
   */
  public function transferReject(string $domain, string $authinfo): bool {
    return $this->transfer($domain, $authinfo, "", "", "reject");
  }

  /**
   * cancel domain transfer from another registrar
   *
   * @param string $domain domain to transfer
   * @param string $authinfo domain authinfo code
   * @return bool status
   */
  public function transferCancel(string $domain, string $authinfo): bool {
    return $this->transfer($domain, $authinfo, "", "", "cancel");
  }

  /**
   * list domains stored in DB (includes pending transfer-in domains)
   *
   * @param int $user_id user ACL (optional), defaults to 1
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @param string $registrant restrict search to this registrant (optional)
   * @param bool $activeOnly list only active domains (TRUE = yes / FALSE = no)
   * @param int $age restrict search to domains older then X months
   * @return array list of domains
   */
  public function listDomains(int $user_id = 1, bool $isAdmin = false, ?string $registrant = null, bool $activeOnly = TRUE, int $age = 0): array {
    $where = ['1 = 1'];
    $params = [];
    if ( ! $isAdmin) {
      $where[] = 'user_id = :user_id';
      $params[':user_id'] = $user_id;
    }
    if ($registrant !== null) {
      $where[] = 'registrant = :registrant';
      $params[':registrant'] = $registrant;
    }

    // pending transfer-in domains (active/age restrictions do not apply) --
    // not a domain EPP has confirmed exists locally yet, so no status of its own
    $domains = array_map(static function (array $row): array {
      $row['status'] = [];
      return $row;
    }, R::getAll("
      SELECT concat(domain, ' (transfer-in)') as domain, registrant, user_id
      FROM transfers WHERE " . implode(' AND ', $where) . "
      ORDER BY domain ASC", $params));

    if ($activeOnly) {
      $where[] = 'active = :active';
      $params[':active'] = 1;
    }
    if ($age > 0) {
      $where[] = 'ex_date < DATE_SUB(CURDATE(), INTERVAL :age MONTH)';
      $params[':age'] = (int)$age;
    }

    $active = R::getAll("
      SELECT domain, registrant, user_id, status
      FROM domains WHERE " . implode(' AND ', $where) . "
      ORDER BY domain ASC", $params);
    // status is a serialized column, in either of the two shapes the table holds
    $active = array_map(static function (array $row): array {
      $row['status'] = SerializedColumn::toArray($row['status'] ?? null);
      return $row;
    }, $active);

    return array_merge($domains, $active);
  }

  /**
   * deactivate a domain stored in DB (soft delete)
   *
   * @param string $domain domain name to delete
   * @param int $user_id user ACL (optional), defaults to 1
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @return bool status
   */
  public function deleteDomainDB(string $domain, int $user_id = 1, bool $isAdmin = false): bool {
    if ( ! $this->storageSetActive($domain, 0, $user_id, $isAdmin, 'delete', ['domain' => $domain])) {
      return FALSE;
    }

    // DNS-sync queue: `eppitnic pdns sync` tears the zone down (delay-gated),
    // and only when pdnsutil_path is actually configured -- see storeDB()
    R::exec("
      INSERT INTO tasks (domain, date, notice, object, action)
      SELECT ?, CURRENT_DATE, ?, 'pdns', 'delete' FROM settings
      WHERE `key` = 'pdnsutil_path' AND value NOT IN ('null', '\"\"', '')
    ", [$domain, 'domain deleted']);

    return TRUE;
  }

  /**
   * reactivate a domain stored in DB (undo a soft delete)
   *
   * @param string $domain domain name to restore
   * @param int $user_id user ACL (optional), defaults to 1
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @return bool status
   */
  public function restoreDomainDB(string $domain, int $user_id = 1, bool $isAdmin = false): bool {
    // logged as 'update': the history action enum has no 'restore'
    if ( ! $this->storageSetActive($domain, 1, $user_id, $isAdmin, 'update', ['domain' => $domain, 'active' => 1])) {
      return FALSE;
    }

    // DNS-sync queue: symmetric with deleteDomainDB() -- the zone needs to
    // come back, same pdnsutil_path gate
    R::exec("
      INSERT INTO tasks (domain, date, notice, object, action)
      SELECT ?, CURRENT_DATE, ?, 'pdns', 'create' FROM settings
      WHERE `key` = 'pdnsutil_path' AND value NOT IN ('null', '\"\"', '')
    ", [$domain, 'domain restored']);

    return TRUE;
  }
}

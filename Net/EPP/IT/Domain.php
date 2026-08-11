<?php

namespace Net\EPP\IT;

use Algo26\IdnaConvert\ToIdn;
use Algo26\IdnaConvert\ToUnicode;

use Net\EPP\AbstractObject;
use Net\EPP\Client;
use Net\EPP\Helpers;
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
 * @package     Net\EPP\IT\Domain
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Domain extends AbstractObject
{
  //         name               // change flag
  protected $user_id;           // use just in case of an updateRegistrant + change of agent
  protected $status;            // domain states (ok, clientDeleteProhibited, clientUpdateProhibited, clientTransferProhibited, clientHold, clientLock + server-side states)
  protected $domain;            // -
  protected $changes;           // sum

  protected $ns;                // 1
  protected $registrant;        // 2
  protected $admin;             // 4
  protected $tech;              // 8
  protected $authinfo;          // 16
  protected $dnssec;            // 32

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

  // max checks allowed
  protected $max_check;

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
  function __construct(Client &$client) {
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
    $this->changes           = 0;
    $this->max_check         = 5;
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

    // 'ns' and 'tech' are collections -- dispatch them before the string
    // escaping below, which would TypeError on an array. Passing an array here
    // is a caller error either way (addNS()/addTECH() each take one entry), but
    // it should come back as FALSE rather than a fatal.
    if ($var == "ns" || $var == "tech") {
      if (is_array($val)) {
        $this->setError("set('{$var}', ...) takes a single value; use add" . strtoupper($var) . "() per entry.");
        return FALSE;
      }
      $val = htmlspecialchars((string)$val, ENT_COMPAT, 'UTF-8', false);
      return ($var == "ns") ? $this->addNS($val) : $this->addTECH($val);
    }

    // in PHP 5.2.3 the 4th parameter "double_encode" was added
    $val = htmlspecialchars((string)$val, ENT_COMPAT, 'UTF-8', false);

    if (isset($this->$var)) {
      if ($this->$var == $val) {
        return FALSE; // value didn't change!
      } else {
        $this->$var = $val;
      }
    } else {
      return FALSE; // value doesn't exist or cannot be set using set($var, $val)!
    }

    switch ($var) {
      //case "ns":                $this->changes |= 1;   break; // to be handled by addNS
      case "registrant":        $this->changes |= 2;   break;
      case "admin":             $this->changes |= 4;   break;
      //case "tech":              $this->changes |= 8;   break; // to be handled by addTECH
      case "authinfo":          $this->changes |= 16;  break;
      //case "dnssec":            $this->changes |= 32;  break; // to be handled by addDNSSEC
    }
    return $this->$var;
  }

  /**
   * remove a DNSSEC data set
   *
   * @param string $digest the digest value identifying which DNSSEC record to remove
   * @return string|false the digest on success, false on failure
   */
  public function remDNSSEC(string $digest): string|false {
    if (isset($this->dnssec_initial[$digest])) {
      $this->changes |= 32;
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

    $this->changes |= 32;
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
   * Note that 'tech' always comes back as an array (keyed handle => handle),
   * even when the domain has exactly one technical contact. It used to be
   * returned as a bare string in that single-contact case -- the common case --
   * which silently broke every caller that handled the result uniformly:
   * array_keys((array) $domain->get('tech')) yielded [0] rather than the
   * handle, so single-tech domains reported a tech contact of "0" and update
   * diffs computed against it never removed the outgoing contact.
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
      $this->changes |= 8;
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
      $this->changes |= 8;
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
      $this->changes |= 1;
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

    // if a nameserver by this name was already set and IPs didn't change stop here
    if (isset($this->ns[$name])) {
      // create a list of all addresses associated to this NS record
      $ip_list = array();
      foreach ($this->ns[$name]['ip'] as $ip) {
        $ip_list[] = $ip['address'];
      }

      // verify if a new IP was added to this NS record
      if ( ! empty($dns1) && ! in_array($dns1, $ip_list)) {
        $ip_changed = TRUE;
      }
      if ( ! empty($dns2) && ! in_array($dns2, $ip_list)) {
        $ip_changed = TRUE;
      }

      // if any new IP was added, remove the NS record first, then procede else there was no change and we bail out
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
    $this->changes |= 1;
    return $name;
  }

  /**
   * check domain
   *
   * @param string $domain optional domain to check (set domain!)
   * @return bool status (TRUE = available, FALSE = unavailable, -1 on error)
   */
  public function check(array|string|null $domain = null): array|bool|int {
    if ($domain === null) {
      $domain = $this->domain;
    }
    if (!is_array($domain)) {
      $domain = array($domain);
    }
    // checked after the array cast, so it has to test the cast value: the old
    // `$domain == ""` compared an array against a string and was never true
    $domain = array_values(array_filter($domain, fn($d) => (string)$d !== ""));
    if (empty($domain)) {
      $this->setError("Operation not allowed, set a domain name first!");
      return -2;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domains', array_slice($domain, 0, $this->max_check));
    $this->xmlQuery = $this->client->fetch("domain-check");
    $this->client->clearAllAssign();

    // query server
    if ($this->ExecuteQuery("domain-check", implode(";", $domain), ($this->debug >= LOG_DEBUG))) {
      $ns = $this->xmlResult->getNamespaces(TRUE);
      $tmp = $this->xmlResult->response->resData->children($ns['domain']);
      if (count($tmp->chkData->cd) == 1) {
        if ($tmp->chkData->cd->name->attributes()->avail == "true") {
          return TRUE;
        } else {
          // override server message with reason
          $this->svMsg = $tmp->chkData->cd->reason;
          return FALSE;
        }
      } else {
        $responses = array();
        for ($i = 0; $i < count($tmp->chkData->cd); $i++) {
          if ($tmp->chkData->cd[$i]->name->attributes()->avail == "true") {
            $responses[(string)$tmp->chkData->cd[$i]->name]['available'] = TRUE;
            $responses[(string)$tmp->chkData->cd[$i]->name]['reason'] = 'OK';
          } else {
            $responses[(string)$tmp->chkData->cd[$i]->name]['available'] = FALSE;
            $responses[(string)$tmp->chkData->cd[$i]->name]['reason'] = (string)$tmp->chkData->cd[$i]->reason;
          }
        }
        return $responses;
      }
    } else {
      // distinguish between errors and boolean states...
      return -1;
    }
  }

  /**
   * create domain
   *
   * @return bool status
   */
  public function create(): bool {
    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $this->domain);
    $this->client->assign('nameservers', $this->ns);
    $this->client->assign('registrant', $this->registrant);
    $this->client->assign('admin', $this->admin);
    $this->client->assign('tech', $this->tech);
    $this->client->assign('authinfo', $this->authinfo);
    if ($this->dnssec_status == 1 && count($this->dnssec) > 0) {
      $this->client->assign('dnssec', $this->dnssec);
    }
    $this->xmlQuery = $this->client->fetch("domain-create");
    $this->client->clearAllAssign();

    // query server and return answer (no handling of special return values)
    if ($this->ExecuteQuery("domain-create", $this->domain, ($this->debug >= LOG_DEBUG))) {
      $this->changes = 0;
      $this->status = array('ok');
      $this->ns_initial = $this->ns;
      $this->admin_initial = $this->admin;
      $this->tech_initial = $this->tech;
      $this->dnssec_initial = $this->dnssec;
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * fetch domain through EPP
   *
   * @param string $domain domain to load
   * @param string $authinfo authinfo string (domain sponsored by other registrar)
   * @param string $infContacts restrict linked-contact info to this type ('all', 'registrant', 'admin', 'tech', or blank for none)
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
    if (($authinfo === null) && ($this->changes & 16)) {
      $authinfo = $this->authinfo;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $domain);
    $this->client->assign('infContacts', $infContacts);
    $this->client->assign('authinfo', empty($authinfo) ? '' : $authinfo);
    $this->xmlQuery = $this->client->fetch("domain-info");
    $this->client->clearAllAssign();

    // re-initialize object data
    $this->initValues();

    // query server
    if ($this->ExecuteQuery("domain-info", $domain, ($this->debug >= LOG_DEBUG))) {
      $ns = $this->xmlResult->getNamespaces(TRUE);
      $tmp = $this->xmlResult->response->resData->children($ns['domain']);

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
      if (isset($ns['secDNS'])) {
        $tmp = $this->xmlResult->response->extension->children($ns['secDNS']);
        foreach ($tmp->infData->dsData as $dsData) {
          $this->addDNSSEC((int)$dsData->keyTag, (int)$dsData->alg, (int)$dsData->digestType, (string)$dsData->digest);
        }
      }

      // if infContactsData is set
      if (isset($ns['extdom'])) {
        $tmp = $this->xmlResult->response->extension->children($ns['extdom']);

        // verify extended states
        if (@is_object($tmp->infData->ownStatus)) {
          foreach ($tmp->infData->ownStatus as $singleState) {
            $this->status[] = (string)$singleState->attributes()->s;
          }
        }

        // fetch contact information
        $this->infcontacts = array();
        if (@is_object($tmp->infContactsData->registrant)) {
          $infContact = $tmp->infContactsData->registrant->infContact->children($ns['contact']);
          $extInfo = $tmp->infContactsData->registrant->extInfo->children($ns['extcon']);
          $this->infcontacts[] = array(
            'type'                 => 'registrant',
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
        if (@is_object($tmp->infContactsData->contact[0])) {
          foreach ($tmp->infContactsData->contact as $contact) {
            $infContact = $contact->infContact->children($ns['contact']);
            $extInfo = $contact->extInfo->children($ns['extcon']);
            $this->infcontacts[] = array(
              'type'                 => (string)$contact->attributes()->type,
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
        }
      }

      // reset changes at the bottom
      $this->changes = 0;
      $this->ns_initial = $this->ns;
      $this->admin_initial = $this->admin;
      $this->tech_initial = $this->tech;
      $this->dnssec_initial = $this->dnssec;
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

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $domain);
    $this->xmlQuery = $this->client->fetch("domain-delete");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("domain-delete", $domain, ($this->debug >= LOG_DEBUG));
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
    if ($this->changes == 0) {
      $this->setError("Domain did not change!");
      return FALSE;
    }
    if (($this->changes & 2) > 0) {
      $this->setError("Update the registrant through updateRegistrant()!");
      return FALSE;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $this->domain);
    if (($this->changes & 1) > 0) {

      // limit to a maximum of 6 ns
      $this->ns = array_slice($this->ns, 0, 6);

      // strip everything down to a 1-dimensional array (names including ip's)
      $tmpA = array();
      $tmpB = array();
      foreach ($this->ns as $name => $values) {
        $tmp = $name;
        if (isset($this->ns[$name]['ip'])) {
          foreach ($this->ns[$name]['ip'] as $i => $addr) {
            $tmp .= ";" . $addr['address'];
          }
        }
        $tmpA[] = $tmp;
      }
      foreach ($this->ns_initial as $name => $values) {
        $tmp = $name;
        if (isset($this->ns_initial[$name]['ip'])) {
          foreach ($this->ns_initial[$name]['ip'] as $i => $addr) {
            $tmp .= ";" . $addr['address'];
          }
        }
        $tmpB[] = $tmp;
      }

      // which to add
      $diffAB = array_diff($tmpA, $tmpB);
      $tmp = array();
      foreach ($diffAB as $name) {
        $key = explode(';', $name);
        $tmp[$key[0]] = $this->ns[$key[0]];
      }
      $this->client->assign('nameservers_add_num', count($tmp));
      $this->client->assign('nameservers_add', $tmp);

      // which to remove
      $diffBA = array_diff($tmpB, $tmpA);
      $tmp = array();
      foreach ($diffBA as $name) {
        $key = explode(';', $name);
        $tmp[$key[0]] = $this->ns_initial[$key[0]];
      }
      $this->client->assign('nameservers_rem_num', count($tmp));
      $this->client->assign('nameservers_rem', $tmp);
    } else {
      $this->client->assign('nameservers_add_num', 0);
      $this->client->assign('nameservers_add', array());
      $this->client->assign('nameservers_rem_num', 0);
      $this->client->assign('nameservers_rem', array());
    }
    if (($this->changes & 4) > 0) {
      $this->client->assign('admin_add', $this->admin);
      $this->client->assign('admin_rem', $this->admin_initial);
    } else {
      $this->client->assign('admin_add', '');
      $this->client->assign('admin_rem', '');
    }
    if (($this->changes & 8) > 0) {
      // limit to a maximum of 6 techc's
      $this->tech = array_slice($this->tech, 0, 6);
      // which to add
      $tmp = array_diff($this->tech, $this->tech_initial);
      $this->client->assign('tech_add_num', count($tmp));
      $this->client->assign('tech_add', $tmp);
      // which to remove
      $tmp = array_diff($this->tech_initial, $this->tech);
      $this->client->assign('tech_rem_num', count($tmp));
      $this->client->assign('tech_rem', $tmp);
    } else {
      $this->client->assign('tech_add_num', 0);
      $this->client->assign('tech_add', array());
      $this->client->assign('tech_rem_num', 0);
      $this->client->assign('tech_rem', array());
    }
    if (($this->changes & 32) > 0) {
      // limit to a maximum of 2 dnssec records
      $this->dnssec = array_slice($this->dnssec, 0, 2, true);
      // which to add
      $tmp = array();
      foreach ($this->dnssec as $digest => $keyinfo) {
        if ( ! isset($this->dnssec_initial[$digest])) {
          $tmp[$digest] = $keyinfo;
        }
      }
      $this->client->assign('dnssec_add_num', count($tmp));
      $this->client->assign('dnssec_add', $tmp);
      // which to remove
      $tmp = array();
      foreach ($this->dnssec_initial as $digest => $keyinfo) {
        if ( ! isset($this->dnssec[$digest])) {
          $tmp[$digest] = $keyinfo;
        }
      }
      $this->client->assign('dnssec_rem_num', count($tmp));
      $this->client->assign('dnssec_rem', $tmp);
    } else {
      $this->client->assign('dnssec_add_num', 0);
      $this->client->assign('dnssec_add', array());
      $this->client->assign('dnssec_rem_num', 0);
      $this->client->assign('dnssec_rem', array());
    }
    $this->client->assign('registrant', '');
    $this->client->assign('authinfo', (($this->changes & 16) > 0) ? $this->authinfo : '');
    $this->xmlQuery = $this->client->fetch("domain-update");
    $this->client->clearAllAssign();

    // query server
    if ($this->ExecuteQuery("domain-update", $this->domain, ($this->debug >= LOG_DEBUG))) {
      $this->changes = 0;
      $this->ns_initial = $this->ns;
      $this->admin_initial = $this->admin;
      $this->tech_initial = $this->tech;
      // dnssec_initial belongs with the others: create(), fetch() and loadDB()
      // all maintain it, and without it a second update() on the same object
      // diffs DNSSEC against pre-first-update state and re-sends stale changes
      $this->dnssec_initial = $this->dnssec;
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
    if ((($this->changes & 2) == 0) || (($this->changes & 16) == 0)) {
      $this->setError("You MUST update the registrant and authinfo variables!");
      return FALSE;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $this->domain);
    $this->client->assign('registrant', $this->registrant);
    $this->client->assign('authinfo', $this->authinfo);
    if (($this->changes & 4) > 0) {
      $this->client->assign('admin_add', $this->admin);
      $this->client->assign('admin_rem', $this->admin_initial);
    } else {
      $this->client->assign('admin_add', '');
      $this->client->assign('admin_rem', '');
    }

    $this->client->assign('tech_add_num', 0);
    $this->client->assign('tech_add', array());
    $this->client->assign('tech_rem_num', 0);
    $this->client->assign('tech_rem', array());

    $this->client->assign('nameservers_add_num', 0);
    $this->client->assign('nameservers_add', array());
    $this->client->assign('nameservers_rem_num', 0);
    $this->client->assign('nameservers_rem', array());

    $this->xmlQuery = $this->client->fetch("domain-update");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("domain-update", $this->domain, ($this->debug >= LOG_DEBUG));
  }

  /**
   * update domain status
   *
   * @param string $state clientDeleteProhibited, clientUpdateProhibited, clientTransferProhibited, clientHold, clientLock
   * @param string $adddel add, rem (optional, defaults to add)
   * @return bool status
   */
  public function updateStatus(string $state, string $adddel = "add"): bool {
    if ($this->domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }

    switch ($state) {
      case "clientDeleteProhibited":
      case "clientUpdateProhibited":
      case "clientTransferProhibited":
      case "clientHold":
      case "clientLock":
        break;
      default:
        $this->setError("State '".$state."' not allowed, expecting one of 'clientDeleteProhibited', 'clientUpdateProhibited', 'clientTransferProhibited', 'clientHold', 'clientLock'.");
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

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $this->domain);
    $this->client->assign('adddel', $adddel);
    $this->client->assign('state', $state);
    $this->xmlQuery = $this->client->fetch("domain-status");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("domain-status", $this->domain, ($this->debug >= LOG_DEBUG));
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

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $domain);
    $this->xmlQuery = $this->client->fetch("domain-restore");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("domain-restore", $domain, ($this->debug >= LOG_DEBUG));
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
      'status'     => serialize($this->status),
      'domain'     => $this->domain,
      'ns'         => serialize($this->ns),
      'registrant' => $this->registrant,
      'admin'      => $this->admin,
      'tech'       => serialize($this->tech),
      'authinfo'   => $this->authinfo,
      'cr_date'    => $this->crDate,
      'ex_date'    => $this->exDate,
      'dnssec'     => serialize($this->dnssec),
    ];

    try {
      // remove existing domain row when storing (re-transfer-in / re-register / re-import),
      // preserving last_invoice and the current owner
      $row = R::getRow("SELECT last_invoice, user_id FROM domains WHERE domain = ?", [$this->domain]);
      if ( ! empty($row)) {
        $data['last_invoice'] = $row['last_invoice'];
        $user_id = $row['user_id'];
        R::exec("DELETE FROM domains WHERE domain = ?", [$this->domain]);
      }

      $data['user_id'] = $user_id;
      $set = [];
      $params = [];
      foreach ($data as $k => $v) {
        $set[] = $k;
        $params[":{$k}"] = $v;
      }
      R::exec("INSERT INTO domains (" . implode(', ', $set) . ") VALUES (" . implode(', ', array_keys($params)) . ")", $params);
    } catch (\RedBeanPHP\RedException\SQL $e) {
      $this->setError("unable to store domain '{$this->domain}': " . $e->getMessage());
      return FALSE;
    }

    $id = (int)R::getCell("SELECT id FROM domains WHERE domain = ?", [$this->domain]);
    Helpers::logChanges('domains', $id, 'create', ['domain' => $this->domain], $user_id);

    if ($notifyDNS) {
      // DNS-sync queue: pdnsutil_updates.php picks this up to (re)create the zone
      R::exec("INSERT INTO reminder (domain, date, notice, action) VALUES (?, CURDATE(), ?, 'create')", [$this->domain, 'domain created']);
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

    $sql = "SELECT * FROM domains WHERE domain = :domain";
    $params = [':domain' => $domain];
    if ( ! $isAdmin) {
      $sql .= " AND user_id = :user_id";
      $params[':user_id'] = $user_id;
    }

    $tmp = R::getRow($sql, $params);
    if (empty($tmp)) {
      $this->setError("Domain '{$domain}' not found.");
      return FALSE;
    }

    foreach ($tmp as $key => $value) {
      $key = strtolower($key);
      // only accept columns that map to a declared property (skips DB-only
      // bookkeeping columns like 'id', 'active' and 'last_invoice')
      if (in_array($key, ['status', 'ns', 'tech', 'dnssec'])) {
        $this->$key = empty($value) ? array() : unserialize($value);
      } else if (property_exists($this, $key)) {
        $this->$key = $value;
      }
    }

    // initialize data
    $this->changes = 0;
    $this->ns_initial = $this->ns;
    $this->admin_initial = $this->admin;
    $this->tech_initial = $this->tech;
    $this->dnssec_initial = $this->dnssec;
    return TRUE;
  }

  /**
   * update domain stored in DB
   *
   * @param string $domain domain to update
   * @param int $user_id user ACL
   * @param bool $isAdmin admin (unrestricted by user_id)
   * @param int $changes changes bitmask to persist (optional, defaults to
   *                     $this->changes). Pass this explicitly when update()
   *                     was already called: it resets $this->changes to 0 on
   *                     success, before updateDB() ever gets a chance to read it.
   * @return bool status
   */
  public function updateDB(?string $domain = null, int $user_id = 1, bool $isAdmin = false, ?int $changes = null): bool {
    if ($domain === null) {
      $domain = $this->domain;
    }

    if ($domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }

    if ($changes === null) {
      $changes = $this->changes;
    }

    if ($changes == 0) {
      $this->setError("Domain did not change!");
      return FALSE;
    }

    $data['status'] = serialize($this->status);
    $data['user_id'] = $user_id;
    if (($changes & 1) > 0) $data['ns'] = serialize($this->ns);
    if (($changes & 2) > 0) {
      $data['registrant'] = $this->registrant;
      // get the new registrant's user_id (agent ID)
      // btw. it should not be possible to assign a registrant not owned by the current user
      // (the caller needs to take care of that!)
      $tmp = new Contact($this->client);
      $tmp->loadDB($this->registrant, $user_id, true);
      $data['user_id'] = $tmp->get('user_id');
    }
    if (($changes & 4) > 0) $data['admin'] = $this->admin;
    if (($changes & 8) > 0) $data['tech'] = serialize($this->tech);
    if (($changes & 16) > 0) $data['authinfo'] = $this->authinfo;
    if (($changes & 32) > 0) $data['dnssec'] = serialize($this->dnssec);
    $data['cr_date'] = $this->crDate;
    $data['ex_date'] = $this->exDate;

    $set = [];
    $params = [':domain' => $domain];
    foreach ($data as $k => $v) {
      $set[] = "{$k} = :{$k}";
      $params[":{$k}"] = $v;
    }
    $sql = "UPDATE domains SET " . implode(', ', $set) . " WHERE domain = :domain";
    if ( ! $isAdmin) {
      $sql .= " AND user_id = :acl_user_id";
      $params[':acl_user_id'] = $user_id;
    }

    try {
      R::exec($sql, $params);
    } catch (\RedBeanPHP\RedException\SQL $e) {
      $this->setError("unable to update domain '{$domain}': " . $e->getMessage());
      return FALSE;
    }

    $id = (int)R::getCell("SELECT id FROM domains WHERE domain = ?", [$domain]);
    Helpers::logChanges('domains', $id, 'update', $data, $user_id);

    // DNS-sync queue: only nameserver changes require a pdnsutil update
    if (($changes & 1) > 0) {
      R::exec("INSERT INTO reminder (domain, date, notice, action) VALUES (?, CURDATE(), ?, 'update')", [$domain, 'nameservers changed']);
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
    if (($authinfo === null) && ($this->changes & 16)) {
      $authinfo = $this->authinfo;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $domain);
    if ( ! empty($authinfo)) {
      $this->client->assign('authinfo', $authinfo);
    }
    $this->xmlQuery = $this->client->fetch("domain-transfer-query");
    $this->client->clearAllAssign();

    // query server
    if ($this->ExecuteQuery("domain-transfer-query", $domain, ($this->debug >= LOG_DEBUG))) {
      $ns = $this->xmlResult->getNamespaces(TRUE);
      $tmp = $this->xmlResult->response->resData->children($ns['domain']);
      if (@is_object($tmp->trnData->trStatus[0])) {
        $this->trStatus = $tmp->trnData->trStatus[0];
        $this->reID = @$tmp->trnData->reID;
        $this->acID = @$tmp->trnData->acID;
      }
      return TRUE;
    } else {
      if (@is_object($this->xmlResult->response->result->extValue->reason[0]))
        $this->svMsg = $this->xmlResult->response->result->extValue->reason[0];
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

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('operation', $operation);
    $this->client->assign('domain', $domain);
    $this->client->assign('authinfo', $authinfo);
    if ( ! empty($newregistrant)) {
      $this->client->assign('newregistrant', $newregistrant);
    }
    if (empty($newauthinfo)) {
      $this->client->assign('newauthinfo', $this->authinfo());
    } else {
      $this->client->assign('newauthinfo', $newauthinfo);
    }
    $this->xmlQuery = $this->client->fetch("domain-transfer");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("domain-transfer-".$operation, $domain, ($this->debug >= LOG_DEBUG));
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

    // pending transfer-in domains (active/age restrictions do not apply)
    $domains = R::getAll("
      SELECT concat(domain, ' (transfer-in)') as domain, registrant, user_id
      FROM transfers WHERE " . implode(' AND ', $where) . "
      ORDER BY domain ASC", $params);

    if ($activeOnly) {
      $where[] = 'active = :active';
      $params[':active'] = 1;
    }
    if ($age > 0) {
      $where[] = 'ex_date < DATE_SUB(CURDATE(), INTERVAL :age MONTH)';
      $params[':age'] = (int)$age;
    }

    $active = R::getAll("
      SELECT domain, registrant, user_id
      FROM domains WHERE " . implode(' AND ', $where) . "
      ORDER BY domain ASC", $params);

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
    $sql = "UPDATE domains SET active = 0 WHERE domain = :domain";
    $params = [':domain' => $domain];
    if ( ! $isAdmin) {
      $sql .= " AND user_id = :user_id";
      $params[':user_id'] = $user_id;
    }

    try {
      R::exec($sql, $params);
    } catch (\RedBeanPHP\RedException\SQL $e) {
      $this->setError("unable to deactivate domain '{$domain}': " . $e->getMessage());
      return FALSE;
    }

    $id = (int)R::getCell("SELECT id FROM domains WHERE domain = ?", [$domain]);
    Helpers::logChanges('domains', $id, 'delete', ['domain' => $domain], $user_id);

    // DNS-sync queue: pdnsutil_updates.php tears the zone down (delay-gated)
    R::exec("INSERT INTO reminder (domain, date, notice, action) VALUES (?, CURDATE(), ?, 'delete')", [$domain, 'domain deleted']);

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
    $sql = "UPDATE domains SET active = 1 WHERE domain = :domain";
    $params = [':domain' => $domain];
    if ( ! $isAdmin) {
      $sql .= " AND user_id = :user_id";
      $params[':user_id'] = $user_id;
    }

    try {
      R::exec($sql, $params);
    } catch (\RedBeanPHP\RedException\SQL $e) {
      $this->setError("unable to activate domain '{$domain}': " . $e->getMessage());
      return FALSE;
    }

    // a restore logs as 'update' -- the changelog.action enum has no 'restore' value
    $id = (int)R::getCell("SELECT id FROM domains WHERE domain = ?", [$domain]);
    Helpers::logChanges('domains', $id, 'update', ['domain' => $domain, 'active' => 1], $user_id);

    // DNS-sync queue: symmetric with deleteDomainDB() -- the zone needs to come back
    R::exec("INSERT INTO reminder (domain, date, notice, action) VALUES (?, CURDATE(), ?, 'create')", [$domain, 'domain restored']);

    return TRUE;
  }
}

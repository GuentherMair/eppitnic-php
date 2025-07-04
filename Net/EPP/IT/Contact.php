<?php

require_once 'Net/EPP/AbstractObject.php';

/**
 * This class handles contacts and supports the following operations on them:
 *
 *  - check contact (single and bulk operations supported)
 *  - create contact (EPP create command)
 *  - fetch contact (EPP info command)
 *  - update contact
 *  - update contact status
 *  - update contact registrant fields
 *  - delete contact
 *
 *  - storeDB store contact to DB
 *  - loadDB load contact from DB
 *  - updateDB update contact stored in DB
 *
 * PHP version 5.3
 *
 * LICENSE:
 *
 * Copyright (c) 2009-2017, Günther Mair <info@inet-services.it>
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
 * @package     Net_EPP_IT_Contact
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: Contact.php 501 2017-05-03 15:17:53Z gunny $
 */

class Net_EPP_IT_Contact extends Net_EPP_AbstractObject
{
  //         name                  // change flag
  protected $userid;               // use just in case of an updateRegistrant + change of agent
  protected $status;               // contact states (ok, linked, clientDeleteProhibited, clientUpdateProhibited)
  protected $handle;               // -
  protected $changes;              // sum

  protected $name;                 // 1
  protected $org;                  // 2
  protected $street;               // 4
  protected $street2;              // 8
  protected $street3;              // 16
  protected $city;                 // 32
  protected $province;             // 64
  protected $postalcode;           // 128
  protected $countrycode;          // 256
  protected $voice;                // 512
  protected $fax;                  // 1024
  protected $email;                // 2048
  protected $authinfo;             // 4096
  protected $consentforpublishing; // 8192
  protected $nationalitycode;      // 16384
  protected $entitytype;           // 32768
  protected $regcode;              // 65536

  protected $max_check;

  /**
   * Class constructor
   *
   * (initializes authinfo)
   *
   * @access   public
   * @param    Net_EPP_IT_Client         client class
   * @param    Net_EPP_StorageInterface  storage class
   */
  function __construct(&$client, &$storage) {
    parent::__construct($client, $storage);

    $this->authinfo = $this->authinfo();
    $this->initValues();
  }

  /**
   * initialize values
   *
   * @access   protected
   */
  protected function initValues() {
    $this->userid               = 1;
    $this->status               = array();
    $this->handle               = "";
    $this->changes              = 0;
    $this->name                 = "";
    $this->org                  = "";
    $this->street               = "";
    $this->street2              = "";
    $this->street3              = "";
    $this->city                 = "";
    $this->province             = "";
    $this->postalcode           = "";
    $this->countrycode          = "";
    $this->voice                = "";
    $this->fax                  = "";
    $this->email                = "";
    $this->authinfo             = "";
    $this->consentforpublishing = 0;
    $this->nationalitycode      = "";
    $this->entitytype           = 0;
    $this->regcode              = "";
    $this->max_check            = 5;
  }

  /**
   * check for possible values of TRUE
   */
  private function isTrue($val) {
    if ($val === TRUE)
      return TRUE;
    if ((string)$val == "1")
      return TRUE;
    if (strtoupper($val) === "TRUE")
      return TRUE;
    return FALSE;
  }

  /**
   * restrict access to variables, so we can keep track of changes to them
   *
   * @access   public
   * @param    string  variable name
   * @param    mix     value to set
   * @return   mix     value set or FALSE if variable name does not exist
   */
  public function set($var, $val) {
    // convert to lower-case
    $var = strtolower($var);

    // in PHP 5.2.3 the 4th parameter "double_encode" was added
    $val = htmlspecialchars($val, ENT_COMPAT, 'UTF-8', false);

    if ($var == "entitytype")
      return $this->setEntityType($val);
    else if ($var == "consentforpublishing" && $this->isTrue($val))
      return $this->setConsent();
    else if ($var == "consentforpublishing" && ! $this->isTrue($val))
      return $this->unsetConsent();
    else if (isset($this->$var))
      if ($this->$var == $val)
        return FALSE; // value didn't change!
      else
        $this->$var = $val;
    else
      return FALSE; // value doesn't exist!

    switch ($var) {
      case "name":                 $this->changes |= 1;     break;
      case "org":                  $this->changes |= 2;     break;
      case "street":               $this->changes |= 4;     break;
      case "street2":              $this->changes |= 8;     break;
      case "street3":              $this->changes |= 16;    break;
      case "city":                 $this->changes |= 32;    break;
      case "province":             $this->changes |= 64;    break;
      case "postalcode":           $this->changes |= 128;   break;
      case "countrycode":          $this->changes |= 256;   break;
      case "voice":                $this->changes |= 512;   break;
      case "fax":                  $this->changes |= 1024;  break;
      case "email":                $this->changes |= 2048;  break;
      case "authinfo":             $this->changes |= 4096;  break;
      // case "consentforpublishing": $this->changes |= 8192;  break; // handled by setConsent() / unsetConsent()
      case "nationalitycode":      $this->changes |= 16384; break;
      // case "entitytype":           $this->changes |= 32768; break; // handled by setEntityType()
      case "regcode":              $this->changes |= 65536; break;
    }
    return $this->$var;
  }

  /**
   * get a single variable/setting from class
   *
   * @access   public
   * @param    string  variable name
   * @return   mix     value of variable
   */
  public function get($var) {
    $var = strtolower($var);
    return $this->$var;
  }

  /**
   * set the entity type which may be one of
   *
   * 0 - NON REGISTRANT CONTACT (admin-c/tech-c)
   * 1 - persone fisiche
   * 2 - società/imprese individuali
   * 3 - liberi professionisti
   * 4 - enti no-profit
   * 5 - enti pubblici
   * 6 - altri soggetti
   * 7 - soggetti stranieri equiparati ai precedenti escluso persone fisiche
   *
   * @access   protected
   * @param    int       entity type
   * @return   boolean   status
   */
  protected function setEntityType($type) {
    $tmp = (int)$type;
    if (($tmp < 1) && ($tmp > 7))
      $tmp = 0; // failback to the default value

    if ($this->entitytype == $tmp)
      return FALSE;

    $this->changes |= 32768;
    return $this->entitytype = $tmp;
  }

  /**
   * set consent for publishing
   *
   * @access   public
   * @return   string "true"
   */
  public function setConsent() {
    if ($this->consentforpublishing == 1)
      return FALSE;

    $this->changes |= 8192;
    return $this->consentforpublishing = 1;
  }

  /**
   * unset consent for publishing
   *
   * @access   public
   * @return   string "false"
   */
  public function unsetConsent() {
    if ($this->consentforpublishing == 0)
      return FALSE;

    $this->changes |= 8192;
    return $this->consentforpublishing = 0;
  }

  /**
   * do sanity checks before sending changes to NIC
   *
   * @access   protected
   * @return   boolean   status
   */
  protected function sanity_checks() {
    $error = 0;

    /*
     * the name rules:
     *
     * 1) remove hyphens
     * 2) the rest must be alphanumeric
     */
    if ( ! ctype_alnum(implode("", explode("-", $this->handle))))
      $error |= 1;

    /*
     * the voice rules:
     *
     * 1) must begin with "+"
     * 2) must have an int. prefix separated by "." from the national part
     * 3) E.164 requests the country code to be of max. 3 digits
     * 4) E.164 specifies the full number to be max. 15 digits
     * 5) may not contain anything else (don't use int typecasts!)
     */
    $tmp = explode(".", substr($this->voice, 1));
    $tmp[0] = ctype_digit(isset($tmp[0]) ? $tmp[0] : "") ? $tmp[0] : "";
    $tmp[1] = ctype_digit(isset($tmp[1]) ? $tmp[1] : "") ? $tmp[1] : "";
    if ((substr($this->voice, 0, 1) != "+") ||
        (count($tmp) <> 2) ||
        (strlen($tmp[0]) > 3 || strlen($tmp[0]) < 1) ||
        (strlen($tmp[1]) > (15-strlen($tmp[0])) || strlen($tmp[1]) < 1) ||
        ("+" . implode(".", array($tmp[0], $tmp[1])) != $this->voice))
      $error |= 2;

    /*
     * the email rules:
     * this could become somewhat complex, so...
     *
     * 1) make sure there is a MX record for the domain part
     * 2) make sure the first element has at least one character
     */
    $tmp = explode("@", $this->email);
    if ( ! getmxrr($tmp[count($tmp)-1], $tmp2) || (strlen($tmp[0]) < 1))
      $error |= 4;

    /*
     * the country code
     */
    if ( ! $this->is_iso3166_1($this->countrycode))
      $error |= 8;

    /*
     * the province code
     */
    if (($this->countrycode == "IT") && ( ! $this->is_iso3166_2it($this->province)))
      $error |= 16;

    /*
     * relation entitytype <=> countrycode
     */
    if (($this->entitytype > 1) && ( ! $this->is_iso3166_1eu($this->countrycode)))
      $error |= 32;

    /*
     * relation entitytype 1 <=> countrycode or nationalitycode
     */
    if (($this->entitytype == 1) &&
        ( ! $this->is_iso3166_1eu($this->countrycode)) &&
        ( ! $this->is_iso3166_1eu($this->nationalitycode)))
      $error |= 64;

    /*
     * entitytype 1: name => org
     */
    if (($this->entitytype == 1))
      $this->org = $this->name;
    if (empty($this->org))
      $this->org = $this->name;

    /*
     * relation entitytype <=> regcode
     *
     * These checks are a rough guess at some points.
     */
    switch ($this->entitytype) {
      case 1: // persone fisiche italiane e straniere
        if ($this->nationalitycode == "IT") {
          if ( ! ((strlen($this->regcode) == 16) &&
                 ctype_alnum($this->regcode) &&
                 ctype_digit(substr($this->regcode, 6, 2)) &&
                 ctype_digit(substr($this->regcode, 9, 2)) &&
                 ctype_digit(substr($this->regcode, 12, 3))) &&
               ($this->regcode <> "n.a."))
            $error |= 128;
        } else {
          // content of regcode is not defined for this case
        }
        break;
      case 4: // enti no-profit
        if ( ! ctype_digit($this->regcode) && ! ($this->regcode == "n.a."))
          $error |= 512;
        break;
      case 2: // società/imprese individuali
      case 3: // liberi professionisti/ordini professionali
      case 5: // enti pubblici
      case 6: // altri soggetti
        if ( ! ctype_digit($this->regcode) || strlen($this->regcode) <> 11)
          $error |= 256;
        break;
      case 0: // don't set any output related to entity types (role contacts)
      case 7: // soggetti stranieri equiparati ai precedenti escluso le persone fisiche
        break;
    }

    /*
     * basic data must be filled in
     */
    if (($this->handle == "") ||
        ($this->name == "") ||
        ($this->street == "") ||
        ($this->city == "") ||
        ($this->province == "") ||
        ($this->postalcode == "") ||
        ($this->countrycode == "") ||
        ($this->voice == "") ||
        ($this->email == ""))
      $error |= 1024;

    return $error;
  }

  /**
   * check contact
   *
   * @access   public
   * @param    string  optional contact to check (set handle!)
   * @return   boolean status (TRUE = available, FALSE = unavailable, -1 on error)
   */
  public function check($contact = null) {
    if ($contact === null)
      $contact = $this->handle;
    if ( ! is_array($contact))
      $contact = array($contact);
    if (empty($contact)) {
      $this->setError("Operation not allowed, set a handle!");
      return -2;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('ids', array_slice($contact, 0, $this->max_check));
    $this->xmlQuery = $this->client->fetch("contact-check");
    $this->client->clearAllAssign();

    // query server
    if ($this->ExecuteQuery("contact-check", implode(";", $contact), ($this->debug >= LOG_DEBUG))) {
      $ns = $this->xmlResult->getNamespaces(TRUE);
      $tmp = $this->xmlResult->response->resData->children($ns['contact']);
      if (count($tmp->chkData->cd) == 1) {
        return ($tmp->chkData->cd->id->attributes()->avail == "true") ? TRUE : FALSE;
      } else {
        $responses = array();
        for ($i = 0; $i < count($tmp->chkData->cd); $i++) {
          $responses[(string)$tmp->chkData->cd[$i]->id] = ($tmp->chkData->cd[$i]->id->attributes()->avail == "true") ? TRUE : FALSE;
        }
        return $responses;
      }
    } else {
      // distinguish between errors and boolean states...
      return -1;
    }
  }

  /**
   * create contact
   *
   * @access   public
   * @param    boolean execute internal sanity checks
   * @return   boolean status
   */
  public function create($exec_checks = FALSE) {
    if ($exec_checks) {
      $sanity = $this->sanity_checks();
      if ($sanity <> 0) {
        $this->setError("Sanity checks failed with code '".$sanity."'!");
        return FALSE;
      }
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('id', $this->handle);
    $this->client->assign('name', $this->name);
    $this->client->assign('org', $this->org);
    $this->client->assign('street', $this->street);
    $this->client->assign('street2', $this->street2);
    $this->client->assign('street3', $this->street3);
    $this->client->assign('city', $this->city);
    $this->client->assign('sp', $this->province);
    $this->client->assign('pc', $this->postalcode);
    $this->client->assign('cc', $this->countrycode);
    $this->client->assign('voice', $this->voice);
    $this->client->assign('fax', $this->fax);
    $this->client->assign('email', $this->email);
    $this->client->assign('authinfo', $this->authinfo);
    $this->client->assign('consentForPublishing', $this->consentforpublishing);
    $this->client->assign('nationalityCode', $this->nationalitycode);
    $this->client->assign('entityType', $this->entitytype);
    $this->client->assign('regCode', $this->regcode);
    $this->xmlQuery = $this->client->fetch("contact-create");
    $this->client->clearAllAssign();

    // query server and return answer (no handling of special return values)
    $response = $this->ExecuteQuery("contact-create", $this->handle, ($this->debug >= LOG_DEBUG));
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
   * @access   public
   * @param    string  contact to load
   * @return   boolean status
   */
  public function fetch($contact = null) {
    if ($contact === null)
      $contact = $this->handle;
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('id', $contact);
    $this->xmlQuery = $this->client->fetch("contact-info");
    $this->client->clearAllAssign();

    // re-initialize object data
    $this->initValues();

    // query server
    if ($this->ExecuteQuery("contact-info", $contact, ($this->debug >= LOG_DEBUG))) {
      $this->changes = 0;
      $this->status = array();
      $this->handle = $contact;

      $ns = $this->xmlResult->getNamespaces(TRUE);
      $tmp = $this->xmlResult->response->resData->children($ns['contact']);

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
      foreach ($tmp->infData->status as $singleState)
        $this->status[] =  (string)$singleState->attributes()->s;

      $tmp = $this->xmlResult->response->extension->children($ns['extcon']);

      $this->set('consentforpublishing', (string)$tmp->infData->consentForPublishing);
      $this->nationalitycode = (string)$tmp->infData->registrant->nationalityCode;
      $this->entitytype =         (int)$tmp->infData->registrant->entityType;
      $this->regcode =         (string)$tmp->infData->registrant->regCode;

      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * delete contact
   *
   * @access   public
   * @return   boolean status
   */
  public function delete($contact = null) {
    if ($contact === null)
      $contact = $this->handle;
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('id', $contact);
    $this->xmlQuery = $this->client->fetch("contact-delete");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("contact-delete", $contact, ($this->debug >= LOG_DEBUG));
  }

  /**
   * update contact
   *
   * @access   public
   * @param    boolean execute internal sanity checks
   * @return   boolean status
   */
  public function update($exec_checks = FALSE) {
    if ($this->handle == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }
    if ($this->changes == 0) {
      $this->setError("Handle did not change!");
      return FALSE;
    }
    if ($exec_checks) {
      $sanity = $this->sanity_checks();
      if ($sanity <> 0) {
        $this->setError("Sanity checks failed with code '".$sanity."'!");
        return FALSE;
      }
    }

    // postalinfo
    $postalinfo = array();
    if (($this->changes & 1) > 0)
      $postalinfo[] = array('name' => 'name', 'value' => $this->name);
    if (($this->changes & 2) > 0)
      $postalinfo[] = array('name' => 'org', 'value' => $this->org);

    // address
    $addr = array();
    if (($this->changes & 508) > 0) {
      // 4 & 8 & 16 & 32 & 64 & 128 & 256
      $addr[] = array('name' => 'street', 'value' => $this->street);
      $addr[] = array('name' => 'street', 'value' => $this->street2);
      $addr[] = array('name' => 'street', 'value' => $this->street3);
      $addr[] = array('name' => 'city', 'value' => $this->city);
      $addr[] = array('name' => 'sp', 'value' => $this->province);
      $addr[] = array('name' => 'pc', 'value' => $this->postalcode);
      $addr[] = array('name' => 'cc', 'value' => $this->countrycode);
    }

    // contact information
    $contact = array();
    $contact[] = array('name' => 'voice', 'value' => (($this->changes & 512) > 0) ? $this->voice : '');
    $contact[] = array('name' => 'fax', 'value' => (($this->changes & 1024) > 0) ? $this->fax : '');
    $contact[] = array('name' => 'email', 'value' => (($this->changes & 2048) > 0) ? $this->email : '');

    // registrant information
    $registrant = array();
    if (($this->changes & 16384) > 0)
      $registrant['nationalityCode'] = $this->nationalitycode;
    if (($this->changes & 32768) > 0)
      $registrant['entityType'] = $this->entitytype;
    if (($this->changes & 65536) > 0)
      $registrant['regCode'] = $this->regcode;

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('id', $this->handle);

    $this->client->assign('postalinfo', empty($postalinfo) ? array() : $postalinfo);
    $this->client->assign('addr', empty($addr) ? '' : $addr);
    $this->client->assign('contact', empty($contact) ? '' : $contact);
    $this->client->assign('registrant', empty($registrant) ? '' : $registrant);
    $this->client->assign('authinfo', (($this->changes & 4096) > 0) ? $this->authinfo : '');
    $this->client->assign('consentForPublishing', (($this->changes & 8192) > 0) ? $this->consentforpublishing : '');

    $this->xmlQuery = $this->client->fetch("contact-update");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("contact-update", $this->handle, ($this->debug >= LOG_DEBUG));
  }

  /**
   * update contact status
   *
   * @access   public
   * @param    string  clientDeleteProhibited, clientUpdateProhibited
   * @param    string  add, rem (optional, defaults to add)
   * @return   boolean status
   */
  public function updateStatus($state, $adddel = "add") {
    if ($this->handle == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }

    switch ($state) {
      case "clientDeleteProhibited":
      case "clientUpdateProhibited":
        break;
      default;
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

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('id', $this->handle);
    $this->client->assign('adddel', $adddel);
    $this->client->assign('state', $state);
    $this->xmlQuery = $this->client->fetch("contact-status");
    $this->client->clearAllAssign();

    // query server
    $result = $this->ExecuteQuery("contact-status", $this->handle, ($this->debug >= LOG_DEBUG));
    if ($result)
      $this->changes = 0;
    return $result;
  }

  /**
   * store contact to DB
   *
   * @access   public
   * @param    string  user ACL
   * @return   boolean status
   */
  public function storeDB($userid = 1) {
    $contact['status'] = $this->status;
    $contact['handle'] = $this->handle;
    $contact['name'] = $this->name;
    $contact['org'] = $this->org;
    $contact['street'] = $this->street;
    $contact['street2'] = $this->street2;
    $contact['street3'] = $this->street3;
    $contact['city'] = $this->city;
    $contact['province'] = $this->province;
    $contact['postalcode'] = $this->postalcode;
    $contact['countrycode'] = $this->countrycode;
    $contact['voice'] = $this->voice;
    $contact['fax'] = $this->fax;
    $contact['email'] = $this->email;
    $contact['authinfo'] = $this->authinfo;
    $contact['consentforpublishing'] = $this->consentforpublishing;
    $contact['nationalitycode'] = $this->nationalitycode;
    $contact['entitytype'] = $this->entitytype;
    $contact['regcode'] = $this->regcode;

    if ($this->storage->storeContact($contact, $userid)) {
      return TRUE;
    } else {
      $this->setError($this->storage->getError());
      return FALSE;
    }
  }

  /**
   * load contact from DB
   *
   * @access   public
   * @param    string  contact to load
   * @param    string  user ACL
   * @return   boolean status
   */
  public function loadDB($contact = null, $userid = 1) {
    if ($contact === null)
      $contact = $this->handle;
    if ($contact == "") {
      $this->setError("Operation not allowed, set a handle!");
      return FALSE;
    }

    // re-initialize object data
    $this->initValues();

    $tmp = $this->storage->retrieveContact($contact, $userid);
    if ($tmp === FALSE) {
      $this->setError($this->storage->getError());
      return FALSE;
    } else {
      $this->changes = 0;
      foreach ($tmp as $key => $value) {
        $key = strtolower($key);
        $this->$key = $value;
      }
      return TRUE;
    }
  }

  /**
   * update contact stored in DB
   *
   * @access   public
   * @param    string  contact to update
   * @param    string  user ACL
   * @return   boolean status
   */
  public function updateDB($contact = null, $userid = 1) {
    if ($contact === null)
      $contact = $this->handle;
    if ($contact == "") {
      $this->setError("Operation not allowed, fetch a handle first!");
      return FALSE;
    }
    if ($this->changes == 0) {
      $this->setError("Handle did not change!");
      return FALSE;
    }

    $data['status'] = $this->status;
    $data['userid'] = isset($_SESSION['id']) ? $_SESSION['id'] : $this->userid;
    if (($this->changes & 1) > 0) $data['name'] = $this->name;
    if (($this->changes & 2) > 0) $data['org'] = $this->org;
    if (($this->changes & 4) > 0) $data['street'] = $this->street;
    if (($this->changes & 8) > 0) $data['street2'] = $this->street2;
    if (($this->changes & 16) > 0) $data['street3'] = $this->street3;
    if (($this->changes & 32) > 0) $data['city'] = $this->city;
    if (($this->changes & 64) > 0) $data['province'] = $this->province;
    if (($this->changes & 128) > 0) $data['postalcode'] = $this->postalcode;
    if (($this->changes & 256) > 0) $data['countrycode'] = $this->countrycode;
    if (($this->changes & 512) > 0) $data['voice'] = $this->voice;
    if (($this->changes & 1024) > 0) $data['fax'] = $this->fax;
    if (($this->changes & 2048) > 0) $data['email'] = $this->email;
    if (($this->changes & 4096) > 0) $data['authinfo'] = $this->authinfo;
    if (($this->changes & 8192) > 0) $data['consentforpublishing'] = $this->consentforpublishing;
    if (($this->changes & 16384) > 0) $data['nationalitycode'] = $this->nationalitycode;
    if (($this->changes & 32768) > 0) $data['entitytype'] = $this->entitytype;
    if (($this->changes & 65536) > 0) $data['regcode'] = $this->regcode;

    if ($this->storage->updateContact($data, $contact, $userid)) {
      return TRUE;
    } else {
      $this->setError($this->storage->getError());
      return FALSE;
    }
  }

  /**
   * listContacts wrapper (storage function provided by WI storage class!)
   *
   * @access   public
   * @param    int      user ACL (optional), defaults to 1 (all contacts)
   * @param    boolean  list only active contacts (TRUE = yes / FALSE = no)
   * @return   array    list of contacts
   */
  public function listContacts($userid = 1, $activeOnly = TRUE) {
    return $this->storage->listContacts($userid, $activeOnly);
  }

  /**
   * deleteContact wrapper (storage function provided by WI storage class!)
   *
   * @access   public
   * @param    string   contact name / handle
   * @param    int      user ACL (optional), defaults to 1 (all contacts)
   * @return   boolean  status
   */
  public function deleteContactDB($contact, $userid = 1) {
    return $this->storage->deleteContact($contact, $userid);
  }

  /**
   * restoreContact wrapper (storage function provided by WI storage class!)
   *
   * @access   public
   * @param    string   contact name / handle
   * @param    int      user ACL (optional), defaults to 1 (all contacts)
   * @return   boolean  status
   */
  public function restoreContactDB($contact, $userid = 1) {
    return $this->storage->restoreContact($contact, $userid);
  }
}

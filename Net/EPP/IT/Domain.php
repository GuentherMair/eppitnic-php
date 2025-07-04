<?php

require_once 'Net/EPP/IT/AbstractObject.php';
require_once 'Net/EPP/IT/Contact.php';

/*
 * idna_convert class (for punycode generation)
*/
if ( ! class_exists('idna_convert') )
  require_once 'libs/idna_convert/idna_convert.class.php';

/**
 * This class handles domains and supports the following operations on them:
 *
 *  - check domain (single and bulk operations supported)
 *  - create domain (EPP create command)
 *  - fetch domain (EPP info command)
 *  - update domain
 *  - update domain registrant
 *  - update domain status
 *  - restore domain
 *  - delete domain
 *
 *  - transferStatus (query) domain
 *  - transfer/transfer-trade domain
 *  - transferApprove domain
 *  - transferReject domain
 *  - transferCancel domain
 *
 *  - storeDB store domain to DB
 *  - loadDB load domain from DB
 *  - updateDB update domain stored in DB
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
 * @package     Net_EPP_IT_Domain
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: Domain.php 347 2011-05-15 22:29:18Z gunny $
 */
class Net_EPP_IT_Domain extends Net_EPP_IT_AbstractObject
{
  //         name              // change flag
  protected $userid;           // use just in case of an updateRegistrant + change of agent
  protected $status;           // domain states (ok, clientDeleteProhibited, clientUpdateProhibited, clientTransferProhibited, clientHold, clientLock + server-side states)
  protected $domain;           // -
  protected $changes;          // sum

  protected $ns;               // 1
  protected $registrant;       // 2
  protected $admin;            // 4
  protected $tech;             // 8
  protected $authinfo;         // 16

  // domain lifecycle
  protected $crDate;
  protected $exDate;

  // these are for internal use only (ie. update)
  protected $ns_initial;
  protected $admin_initial;
  protected $tech_initial;

  // domain transfer information
  protected $trStatus;
  protected $reID;
  protected $acID;

  // max checks allowed
  protected $max_check;

  // IDN <=> punycode converter class
  protected $idn;

  /*
   * Class constructor
   *
   * (initializes authinfo)
   *
   * @access   public
   * @param    Net_EPP_IT_Client            client class
   * @param    Net_EPP_IT_StorageInterface  storage class
   */
  function __construct(&$client, &$storage) {
    parent::__construct($client, $storage);

    $this->authinfo = $this->authinfo();
    $this->initValues();
    $this->idn = new idna_convert(array('encode_german_sz' => true));
  }

  /**
   * initialize values
   *
   * @access   protected
   */
  protected function initValues() {
    $this->status        = array();
    $this->domain        = "";
    $this->registrant    = "";
    $this->admin         = "";
    $this->admin_initial = "";
    $this->tech          = array();
    $this->tech_initial  = array();
    $this->ns            = array();
    $this->ns_initial    = array();
    $this->authinfo      = "";
    $this->changes       = 0;
    $this->max_check     = 5;
    $this->crDate        = "";
    $this->exDate        = "";
    $this->trStatus      = "";
    $this->reID          = "";
    $this->acID          = "";
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

    if ( $var == "ns" )
      return $this->addNS($val);
    else if ( $var == "tech" )
      return $this->addTECH($val);
    else if ( isset($this->$var) )
      if ( $this->$var == $val )
        return FALSE; // value didn't change!
      else
        $this->$var = $val;
    else
      return FALSE; // value doesn't exist!

    switch ($var) {
      //case "ns":           $this->changes |= 1;  break; // to be handled by addNS
      case "registrant":   $this->changes |= 2;  break;
      case "admin":        $this->changes |= 4;  break;
      //case "tech":         $this->changes |= 8;  break; // to be handled by addTECH
      case "authinfo":     $this->changes |= 16; break;
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
    // if tech only holds 1 value (as in most cases) return a string and not an array
    if ( ($var == "tech") && (count($this->tech) == 1) ) {
      return current($this->tech);
    } else {
      return $this->$var;
    }
  }

  /**
   * remove a technical contact
   *
   * @access   public
   * @param    string  tech contact name
   * @return   mix     value removed or FALSE if variable name does not exist
   */
  public function remTECH($name) {
    if ( isset($this->tech[$name]) ) {
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
   * @access   public
   * @param    string  tech contact name
   * @return   mix     value set or FALSE if there was an error
   */
  public function addTECH($name) {
    if ( empty($name) )
      return FALSE;

    // if a technical contact by this name was already set stop here
    if ( ! isset($this->tech[$name]) ) {

      // check that we are not exceeding the maximum number of allowed technical contacts
      if ( count($this->tech) >= 6 ) {
        $this->setError("You are not allowed to assign more than 6 tech contacts to a domain.");
        return FALSE;
      }

      // assign technical contact
      $this->tech[$name] = $name;
      $this->changes |= 8;
    }
    return $name;
  }

  /**
   * remove a nameserver
   *
   * @access   public
   * @param    string   NS name
   * @return   mix      value set or FALSE if variable name does not exist
   */
  public function remNS($name) {
    // DNS names must be in punycode format (if below an IDN domain)
    $name = $this->idn->encode($name);
    if ( isset($this->ns[$name]) ) {
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
   * @access   public
   * @param    string  NS name
   * @param    mix     ip addresses to set (an array of two, one or a string)
   * @return   mix     value set or FALSE on error
   */
  public function addNS($name, $addr = null) {
    $dns1 = "";
    $dns2 = "";

    // don't allow empty values
    if ( empty($name) )
      return FALSE;

    // DNS names must be in punycode format (if below an IDN domain)
    $name = $this->idn->encode($name);

    // if a nameserver by this name was already set stop here
    if ( ! isset($this->ns[$name]) ) {

      // check that we are not exceeding the maximum number of allowed NS records
      if ( count($this->ns) >= 6 ) {
        $this->setError("You are not allowed to assign more than 6 NS to a domain.");
        return FALSE;
      }

      // assign NS name
      $this->ns[$name]['name'] = $name;

      // handle IP addresses (if set)
      if ( is_array($addr) ) {
        switch ( count($addr) ) {
          case 2:
            $dns1 = $addr[0];
            $dns2 = $addr[1];
            break;
          case 1:
            $dns1 = $addr[0];
            break;
          case 0:
            break;
          default:
            $this->setError("The address must be an array of one or two elements.");
            return FALSE;
            break;
        }
      } else if ( ! empty($addr) ) {
        $dns1 = $addr;
      }

      // assign IP address 1 (if set)
      if ( ! empty($dns1) ) {
        if ( @gethostbyaddr($dns1) == "" ) {
          $this->setError("Address '".$dns1."' is not a valid IPv4 or IPv6 address.");
          return FALSE;
        } else {
          $type = strpos($dns1, '.') ? 'v4' : 'v6';
          $this->ns[$name]['ip'][] = array('type' => $type, 'address' => $dns1);
        }
      }

      // assign IP address 2 (if set)
      if ( ! empty($dns2) ) {
        if ( @gethostbyaddr($dns2) == "" ) {
          $this->setError("Address '".$dns2."' is not a valid IPv4 or IPv6 address.");
          return FALSE;
        } else {
          $type = strpos($dns2, '.') ? 'v4' : 'v6';
          $this->ns[$name]['ip'][] = array('type' => $type, 'address' => $dns2);
        }
      }

      // if we get to this point, something has changed
      $this->changes |= 1;
    }

    return $name;
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
    if ( !ctype_alnum(implode("", explode(".", implode("", explode("-", $this->domain))))) )
      $error |= 1;

    /*
     * empty values
     */
    if ( empty($this->domain) ||
         empty($this->registrant) ||
         empty($this->admin) ||
         empty($this->tech) ||
         empty($this->authinfo) )
      $error |= 2;

    /*
     * amount of NS records
     */
    if ( (count($this->ns) < 2) ||
         (count($this->ns) > 6) )
      $error |= 4;

    /*
     * length
     */
    if ( (strlen($this->domain) < 6) ||
         (strlen($this->domain) > 255) )
      $error |= 8;

    /*
     * pre-/postfix checks
     */
    $tmp = explode(".", $this->domain);
    if ( (substr($tmp[0], 0, 4) == "xn--") ||
         (substr($tmp[0], 0, 1) == "-") ||
         (substr($tmp[0], -1) == "-") )
      $error |= 16;

    /*
     * authinfo length
     */
    if ( (strlen($this->authinfo) < 8) ||
         (strlen($this->authinfo) > 32) )
      $error |= 32;

    /*
     * different contacts
     *
     * This check is temporarily disabled because it depends on the registrants
     * "EntityType" value as specified by the registry:
     *
     *   'Se il Registrante è una persona fisica (EntityType = 1) il
     *    Registrante ed il contatto am- ministrativo (admin) devono
     *    coincidere. Tali campi dovranno, pertanto, contenere lo
     *    stesso contact-ID associato ad un contatto, già registrato
     *    nel Database del Registro, completo dell’estensione relativa
     *    ai dati del Registrante.'
     *
     * To re-enable this check, we would need to execute a info-contact command
     * and then compare the return value for EntityType.
     *
     * Thanks to Mr. Fundinger for pointing this out!
     */
    //if ( ($this->registrant == $this->admin) ||
    //     ($this->registrant == $this->tech) ||
    //     ($this->admin == $this->tech) )
    //  $error |= 64;

    /*
     * glue records (this does not care about v4/v6)
     */
    foreach ($this->ns as $hostname => $values) {
      if ( (substr($hostname, strlen($this->domain)*-1) == $this->domain) &&
           ! isset($values['ip']) )
        $error |= 128;
    }

    return $error;
  }

  /**
   * check domain
   *
   * @access   public
   * @param    string  optional domain to check (set domain!)
   * @return   boolean status (TRUE = available, FALSE = unavailable, -1 on error)
   */
  public function check($domain = null) {
    if ($domain === null)
      $domain = $this->domain;
    if (!is_array($domain))
      $domain = array($domain);
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return -2;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domains', array_slice($domain, 0, $this->max_check));
    $this->xmlQuery = $this->client->fetch("check-domain");
    $this->client->clearAllAssign();

    // query server
    if ( $this->ExecuteQuery("check-domain", implode(";", $domain), ($this->debug >= LOG_DEBUG)) ) {
      $tmp = $this->xmlResult->response->resData->children('urn:ietf:params:xml:ns:domain-1.0');
      if ( count($tmp->chkData->cd) == 1 ) {
        if ( $tmp->chkData->cd->name->attributes()->avail == "true" ) {
          return TRUE;
        } else {
          // override server message with reason
          $this->svMsg = $tmp->chkData->cd->reason;
          return FALSE;
        }
      } else {
        $responses = array();
        for ( $i = 0; $i < count($tmp->chkData->cd); $i++ ) {
          if ( $tmp->chkData->cd[$i]->name->attributes()->avail == "true" ) {
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
   * @access   public
   * @param    boolean execute internal sanity checks
   * @return   boolean status
   */
  public function create($exec_checks = FALSE) {
    if ( $exec_checks ) {
      $sanity = $this->sanity_checks();
      if ($sanity <> 0) {
        $this->setError("Sanity checks failed with code '".$sanity."'!");
        return FALSE;
      }
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $this->domain);
    $this->client->assign('nameservers', $this->ns);
    $this->client->assign('registrant', $this->registrant);
    $this->client->assign('admin', $this->admin);
    $this->client->assign('tech', $this->tech);
    $this->client->assign('authinfo', $this->authinfo);
    $this->xmlQuery = $this->client->fetch("create-domain");
    $this->client->clearAllAssign();

    // query server and return answer (no handling of special return values)
    if ( $this->ExecuteQuery("create-domain", $this->domain, ($this->debug >= LOG_DEBUG)) ) {
      $this->changes = 0;
      $this->status = array('ok');
      $this->ns_initial = $this->ns;
      $this->admin_initial = $this->admin;
      $this->tech_initial = $this->tech;
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * fetch domain through EPP
   *
   * @access   public
   * @param    string  domain to load
   * @param    string  authinfo string (domain sponsored by other registrar)
   * @return   boolean status
   */
  public function fetch($domain = null, $authinfo = null) {
    if ($domain === null)
      $domain = $this->domain;

    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }

    // if authinfo was not given as an argument, but has been set
    if ( ($authinfo === null) && ($this->changes & 16) )
      $authinfo = $this->authinfo;

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $domain);
    if ( ! empty($authinfo) )
      $this->client->assign('authinfo', $authinfo);
    $this->xmlQuery = $this->client->fetch("info-domain");
    $this->client->clearAllAssign();

    // re-initialize object data
    $this->initValues();

    // query server
    if ( $this->ExecuteQuery("info-domain", $domain, ($this->debug >= LOG_DEBUG)) ) {
      $tmp = $this->xmlResult->response->resData->children('urn:ietf:params:xml:ns:domain-1.0');

      $this->domain = $domain;
      $this->status = array();

      $this->registrant = (string)$tmp->infData->registrant;
      $this->authinfo = (string)$tmp->infData->authInfo->pw;
      $this->crDate = (string)$tmp->infData->crDate;
      $this->exDate = (string)$tmp->infData->exDate;
      foreach ( $tmp->infData->status as $singleState )
        $this->status[] = (string)$singleState->attributes()->s;
      foreach ( $tmp->infData->contact as $contact ) {
        $type = $contact->attributes()->type;
        if ( $type == "tech" ) {
          $this->addTECH((string)$contact);
        } else {
          $this->$type = (string)$contact;
        }
      }

      // if the NS were not properly configured EPP will not report them yet!
      if ( @is_object($tmp->infData->ns->hostAttr[0]) )
        foreach ($tmp->infData->ns->hostAttr as $hostAttr) {
          $addr = array();
          foreach ($hostAttr->hostAddr as $ip) {
            $addr[] = (string)$ip;
          }
          $this->addNS((string)$hostAttr->hostName, $addr);
        }

      // reset changes at the bottom
      $this->changes = 0;
      $this->ns_initial = $this->ns;
      $this->admin_initial = $this->admin;
      $this->tech_initial = $this->tech;
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * print domain status - the states will be set after a call to fetch()
   *
   * @access   public
   * @return   mix     server side state (text-string or FALSE)
   */
  public function state() {
    if ($this->status !== null)
      return $this->status;
    else
      return FALSE;
  }

  /**
   * delete domain
   *
   * @access   public
   * @param    string  domain name to delete
   * @return   boolean status
   */
  public function delete($domain = null) {
    if ($domain === null)
      $domain = $this->domain;
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name!");
      return FALSE;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $domain);
    $this->xmlQuery = $this->client->fetch("delete-domain");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("delete-domain", $domain, ($this->debug >= LOG_DEBUG));
  }

  /**
   * update domain
   *
   * @access   public
   * @param    boolean execute internal sanity checks
   * @return   boolean status
   */
  public function update($exec_checks = FALSE) {
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
    if ( $exec_checks ) {
      $sanity = $this->sanity_checks();
      if ($sanity <> 0) {
        $this->setError("Sanity checks failed with code '".$sanity."'!");
        return FALSE;
      }
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $this->domain);
    if (($this->changes & 1) > 0) {

      // strip everything down to a 1-dimensional array (names including ip's)
      $tmpA = array();
      $tmpB = array();
      foreach ($this->ns as $name => $values) {
        $tmp = $name;
        if ( isset($this->ns[$name]['ip']) )
          foreach ($this->ns[$name]['ip'] as $i => $addr)
            $tmp .= ";" . $addr['address'];
        $tmpA[] = $tmp;
      }
      foreach ($this->ns_initial as $name => $values) {
        $tmp = $name;
        if ( isset($this->ns_initial[$name]['ip']) )
          foreach ($this->ns_initial[$name]['ip'] as $i => $addr)
            $tmp .= ";" . $addr['address'];
        $tmpB[] = $tmp;
      }

      // which to add
      $diffAB = array_diff($tmpA, $tmpB);
      $tmp = array();
      foreach ($diffAB as $name) {
        $key = split(';', $name);
        $tmp[$key[0]] = $this->ns[$key[0]];
      }
      $this->client->assign('nameservers_add_num', count($tmp));
      $this->client->assign('nameservers_add', $tmp);

      // which to remove
      $diffBA = array_diff($tmpB, $tmpA);
      $tmp = array();
      foreach ($diffBA as $name) {
        $key = split(';', $name);
        $tmp[$key[0]] = $this->ns_initial[$key[0]];
      }
      $this->client->assign('nameservers_rem_num', count($tmp));
      $this->client->assign('nameservers_rem', $tmp);
    }
    if (($this->changes & 4) > 0) {
      $this->client->assign('admin_add', $this->admin);
      $this->client->assign('admin_rem', $this->admin_initial);
    }
    if (($this->changes & 8) > 0) {
      // which to add
      $tmp = array_diff($this->tech, $this->tech_initial);
      $this->client->assign('tech_add_num', count($tmp));
      $this->client->assign('tech_add', $tmp);
      // which to remove
      $tmp = array_diff($this->tech_initial, $this->tech);
      $this->client->assign('tech_rem_num', count($tmp));
      $this->client->assign('tech_rem', $tmp);
    }
    if (($this->changes & 16) > 0)
      $this->client->assign('authinfo', $this->authinfo);
    $this->xmlQuery = $this->client->fetch("update-domain");
    $this->client->clearAllAssign();

    // query server
    if ( $this->ExecuteQuery("update-domain", $this->domain, ($this->debug >= LOG_DEBUG)) ) {
      $this->changes = 0;
      $this->ns_initial = $this->ns;
      $this->admin_initial = $this->admin;
      $this->tech_initial = $this->tech;
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * update domain registrant
   *
   * @access   public
   * @param    boolean execute internal sanity checks
   * @return   boolean status
   */
  public function updateRegistrant($exec_checks = FALSE) {
    if ($this->domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }
    if ( (($this->changes & 2) == 0) ||
         (($this->changes & 16) == 0) ) {
      $this->setError("You MUST update the registrant and authinfo variables!");
      return FALSE;
    }
    if ( $exec_checks ) {
      $sanity = $this->sanity_checks();
      if ($sanity <> 0) {
        $this->setError("Sanity checks failed with code '".$sanity."'!");
        return FALSE;
      }
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $this->domain);
    $this->client->assign('registrant', $this->registrant);
    $this->client->assign('authinfo', $this->authinfo);
    if (($this->changes & 4) > 0) {
      $this->client->assign('admin_add', $this->admin);
      $this->client->assign('admin_rem', $this->admin_initial);
    }
    $this->xmlQuery = $this->client->fetch("update-domain");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("update-domain", $this->domain, ($this->debug >= LOG_DEBUG));
  }

  /**
   * update domain status
   *
   * @access   public
   * @param    string  clientDeleteProhibited, clientUpdateProhibited, clientTransferProhibited, clientHold, clientLock
   * @param    string  add, rem (optional, defaults to add)
   * @return   boolean status
   */
  public function updateStatus($state, $adddel = "add") {
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
      default;
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
    $this->xmlQuery = $this->client->fetch("update-domain-status");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("update-domain-status", $this->domain, ($this->debug >= LOG_DEBUG));
  }

  /**
   * restore domain
   *
   * @access   public
   * @param    string  domain name to restore
   * @return   boolean status
   */
  public function restore($domain = null) {
    if ($domain === null)
      $domain = $this->domain;
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $domain);
    $this->xmlQuery = $this->client->fetch("update-domain-restore");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("update-domain-restore", $domain, ($this->debug >= LOG_DEBUG));
  }

  /**
   * store domain to DB
   *
   * @access   public
   * @param    string  user ACL
   * @return   boolean status
   */
  public function storeDB($userid = 1) {
    $domain['status'] = $this->status;
    $domain['domain'] = $this->domain;
    $domain['ns'] = $this->ns;
    $domain['registrant'] = $this->registrant;
    $domain['admin'] = $this->admin;
    $domain['tech'] = $this->tech;
    $domain['authinfo'] = $this->authinfo;
    $domain['crDate'] = $this->crDate;
    $domain['exDate'] = $this->exDate;

    // remove existing domain objects when storing (re-transfer-in / re-register / re-import)
    $result = $this->storage->dbConnect->Execute("SELECT lastInvoice, userid FROM tbl_domains WHERE domain='".$this->domain."'");
    if ( $result !== FALSE )
      if ($result->RecordCount() > 0) {
        // save the lastInvoice value!
        $domain['lastInvoice'] = $result->Fields('lastInvoice');
        // keep the current userid
        $userid = $result->Fields('userid');
        $this->storage->dbConnect->Execute("DELETE FROM tbl_domains WHERE domain='".$this->domain."'");
      }

    if ( $this->storage->storeDomain($domain, $userid) ) {
      return TRUE;
    } else {
      $this->setError($this->storage->getError());
      return FALSE;
    }
  }

  /**
   * load domain from DB
   *
   * @access   public
   * @param    string  domain to load
   * @param    string  user ACL
   * @return   boolean status
   */
  public function loadDB($domain = null, $userid = 1) {
    if ($domain === null)
      $domain = $this->domain;
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name!");
      return FALSE;
    }

    // re-initialize object data
    $this->initValues();

    $tmp = $this->storage->retrieveDomain($domain, $userid);
    if ( $tmp === FALSE ) {
      $this->setError($this->storage->getError());
      return FALSE;
    } else {
      // initialize data
      foreach ($tmp as $key => $value) {
        $key = strtolower($key);
        $this->$key = $value;
      }

      // convert these into arrays (even empty ones)
      if ( ! is_array($this->ns) ) {
        $tmp = (string)$this->ns;
        $this->ns = array();
        $this->addNS($tmp);
      } else if ( empty($this->ns) ) {
        $this->ns = array();
      }
      if ( ! is_array($this->tech) ) {
        $tmp = (string)$this->tech;
        $this->tech = array();
        $this->addTECH($tmp);
      } else if ( empty($this->tech) ) {
        $this->tech = array();
      }
      
      // initialize data
      $this->changes = 0;
      $this->ns_initial = $this->ns;
      $this->admin_initial = $this->admin;
      $this->tech_initial = $this->tech;
      return TRUE;
    }
  }

  /**
   * update domain stored in DB
   *
   * @access   public
   * @param    string  domain to update
   * @param    string  user ACL
   * @return   boolean status
   */
  public function updateDB($domain = null, $userid = 1) {
    if ($domain === null)
      $domain = $this->domain;

    if ($domain == "") {
      $this->setError("Operation not allowed, fetch a domain first!");
      return FALSE;
    }

    if ($this->changes == 0) {
      $this->setError("Domain did not change!");
      return FALSE;
    }

    $data['status'] = $this->status;
    if (($this->changes & 1) > 0) $data['ns'] = $this->ns;
    if (($this->changes & 2) > 0) {
      $data['registrant'] = $this->registrant;
      // get the new reginstrants' userid (agent ID)
      // btw. it should not be possible to assign a registrant not owned by the current user
      // (the user interface needs to take care of that!)
      $tmp = new Net_EPP_IT_Contact($this->client, $this->storage);
      $tmp->loadDB($this->registrant);
      $data['userid'] = $tmp->get('userid');
    }
    if (($this->changes & 4) > 0) $data['admin'] = $this->admin;
    if (($this->changes & 8) > 0) $data['tech'] = $this->tech;
    if (($this->changes & 16) > 0) $data['authinfo'] = $this->authinfo;
    $data['crDate'] = $this->crDate;
    $data['exDate'] = $this->exDate;

    if ( $this->storage->updateDomain($data, $domain, $userid) ) {
      return TRUE;
    } else {
      $this->setError($this->storage->getError());
      return FALSE;
    }
  }

  /**
   * transfer status
   *
   * @access   public
   * @param    string  domain to transfer
   * @param    string  domain authinfo code
   * @return   boolean status
   */
  public function transferStatus($domain, $authinfo = "") {
    if ($domain === null)
      $domain = $this->domain;
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }
    // if authinfo was not given as an argument, but has been set
    if ( ($authinfo === null) && ($this->changes & 16) )
      $authinfo = $this->authinfo;

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('domain', $domain);
    if ( ! empty($authinfo) )
      $this->client->assign('authinfo', $authinfo);
    $this->xmlQuery = $this->client->fetch("transfer-query");
    $this->client->clearAllAssign();

    // query server
    if ( $this->ExecuteQuery("transfer-query", $domain, ($this->debug >= LOG_DEBUG)) ) {
      $tmp = $this->xmlResult->response->resData->children('urn:ietf:params:xml:ns:domain-1.0');
      if ( @is_object($tmp->trnData->trStatus[0]) ) {
        $this->trStatus = $tmp->trnData->trStatus[0];
        $this->reID = @$tmp->trnData->reID;
        $this->acID = @$tmp->trnData->acID;
      }
      return TRUE;
    } else {
      if ( @is_object($this->xmlResult->response->result->extValue->reason[0]) )
        $this->svMsg = $this->xmlResult->response->result->extValue->reason[0];
      return FALSE;
    }
  }

  /**
   * transfer domain / transfer-trade domain
   *
   * @access   public
   * @param    string  domain to transfer
   * @param    string  domain authinfo code
   * @param    string  new registrant (optional / trade)
   * @param    string  new authinfo (optional)
   * @param    string  transfer type (defaults to "request")
   * @return   boolean status
   */
  public function transfer($domain, $authinfo, $newregistrant = "", $newauthinfo = "", $operation = "request") {
    if ($domain === null)
      $domain = $this->domain;
    if ($domain == "") {
      $this->setError("Operation not allowed, set a domain name first!");
      return FALSE;
    }
    if ($authinfo === null)
      $authinfo = $this->authinfo;
    if ($authinfo == "") {
      $this->setError("Operation not allowed, state the domain authinfo!");
      return FALSE;
    }

    // fill xml template
    $this->client->assign('clTRID', $this->client->set_clTRID());
    $this->client->assign('operation', $operation);
    $this->client->assign('domain', $domain);
    $this->client->assign('authinfo', $authinfo);
    if ( ! empty($newregistrant) )
      $this->client->assign('newregistrant', $newregistrant);
    if ( empty($newauthinfo) )
      $this->client->assign('newauthinfo', $this->authinfo());
    else
      $this->client->assign('newauthinfo', $newauthinfo);
    $this->xmlQuery = $this->client->fetch("transfer-domain");
    $this->client->clearAllAssign();

    // query server
    return $this->ExecuteQuery("transfer-domain-".$operation, $domain, ($this->debug >= LOG_DEBUG));
  }

  /**
   * approve domain transfer to another registrar
   *
   * @access   protected
   * @param    string     domain to operate on
   * @param    string     domain authinfo code
   * @return   boolean    status
   */
  public function transferApprove($domain, $authinfo) {
    return $this->transfer($domain, $authinfo, "", "", "approve");
  }

  /**
   * reject domain transfer to another registrar
   *
   * @access   protected
   * @param    string     domain to operate on
   * @param    string     domain authinfo code
   * @return   boolean    status
   */
  public function transferReject($domain, $authinfo) {
    return $this->transfer($domain, $authinfo, "", "", "reject");
  }

  /**
   * cancel domain transfer from another registrar
   *
   * @access   public
   * @param    string  domain to transfer
   * @param    string  domain authinfo code
   * @return   boolean status
   */
  public function transferCancel($domain, $authinfo) {
    return $this->transfer($domain, $authinfo, "", "", "cancel");
  }

  /**
   * listDomains wrapper (storage function provided by WI storage class!)
   *
   * @access   public
   * @param    int      user ACL (optional), defaults to 1 (all domains)
   * @param    string   contact ACL (optional), defaults to null (all domains)
   * @param    boolean  list only active domains (TRUE = yes / FALSE = no)
   * @param    integer   restrict search to domains older then X months
   * @return   array    list of domains
   */
  public function listDomains($userid = 1, $handle = null, $activeOnly = TRUE, $age = 0) {
    return $this->storage->listDomains($userid, $handle, $activeOnly, $age);
  }

  /**
   * deleteDomain wrapper (storage function provided by WI storage class!)
   *
   * @access   public
   * @param    string   domain name to delete
   * @param    int      user ACL (optional), defaults to 1 (all domains)
   * @return   boolean  status
   */
  public function deleteDomainDB($domain, $userid = 1) {
    return $this->storage->deleteDomain($domain, $userid);
  }

  /**
   * restoreDomain wrapper (storage function provided by WI storage class!)
   *
   * @access   public
   * @param    string   domain name to restore
   * @param    int      user ACL (optional), defaults to 1 (all domains)
   * @return   boolean  status
   */
  public function restoreDomainDB($domain, $userid = 1) {
    return $this->storage->restoreDomain($domain, $userid);
  }

  /**
   * invoiceableDomains wrapper (storage function provided by WI storage class!)
   *
   * @access   public
   * @return   array    list of domains
   */
  public function invoiceableDomains() {
    return $this->storage->invoiceableDomains();
  }

  /**
   * renewDomains wrapper (storage function provided by WI storage class!)
   *
   * @access   public
   * @return   boolean  status
   */
  public function renewDomains() {
    return $this->storage->renewDomains();
  }

}


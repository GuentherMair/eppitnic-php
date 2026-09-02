<?php

namespace Eppitnic\Epp;

use Eppitnic\Support\PasswordGenerator;
use RedBeanPHP\R;

/**
 * An abstract class for other EPP objects (session, contact, domain).
 *
 * It provides:
 *  - public variables available inside all objects
 *  - a generic constructor and protected variables for Client and Storage
 *  - a generic ExecuteQuery method
 *  - generic error code handlers (getter and setter)
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
 * @package     Eppitnic\Epp\AbstractObject
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

abstract class AbstractObject
{
  protected $client;

  /**
   * Diagnostics for this object: include the full EPP request and response in
   * getError(), and persist every command to `transactions`/`responses`.
   *
   * Off by default. Note what it costs when on: a row per command, holding the
   * raw XML -- registrant names, addresses and authinfo codes included.
   *
   * Set per user by the `users`.`debug` column, carried here from the Client
   * the object was constructed with (see Client::$debug).
   */
  public bool $debug = false;

  public    $xmlQuery;  // xml query string
  public    ?HttpResponse $result = null;   // the last exchange with the registry
  public    $xmlResult; // parsed reponse (SimpleXMLElement may be incomplete)

  public    $svCode;
  public    $svMsg;
  public    $svTRID;
  public    $extValueReasonCode;
  public    $extValueReason;

  /**
   * How many names one <check> may carry, per nic.it's technical guidelines.
   * Here rather than in each subclass, which set the same 5: checkAvailability()
   * is what reads it, and it is the registry's limit, not a per-object one.
   */
  protected int $max_check = 5;

  /**
   * Class constructor
   *
   * @param Client $client client class
   */
  public function __construct(Client $client) {
    $this->client  = $client;
    $this->debug   = $client->debug;
  }

  /**
   * Class as a string
   *
   * @return string class settings
   */
  public function __toString(): string {
    $class = get_class($this);
    $text = "[{$class}] variables:\n";

    try {
      $rc = new \ReflectionClass($this);

      $props = $rc->getProperties(
        \ReflectionProperty::IS_PUBLIC |
        \ReflectionProperty::IS_PROTECTED);

      foreach ($props as $prop) {
        $prop->setAccessible(true);
        $name = $prop->getName();
        $value = $prop->getValue($this);

        // don't dump parent class elements
        if ($prop->getDeclaringClass()->getName() != $class) {
          continue;
        }

        // don't dump objects
        if (is_object($value)) {
          continue;
        }

        // don't dump "initial" variables
        if (substr($name, -8) == '_initial') {
          continue;
        }

        // don't dump other special variables
        if (in_array($name, array('changes', 'max_check', 'user_id'))) {
          continue;
        }

        // these are the one's to show
        if ($value === 0) {
          $realvalue = 0;
        } else if (empty($value)) {
          $realvalue = "[empty]";
        } else if (is_array($value)) {
          $names = array();
          foreach ($value as $key => $element) {
            if (is_array($element)) {
              $subnames = array();
              foreach ($element as $subkey => $subelement) {
                $subnames[] = (string)$subkey . ": " . (string)$subelement;
              }
              $names[] = (string)$key . ": [" . implode(", ", $subnames) . "]";
            } else {
              $names[] = (string)$key . ": " . (string)$element;
            }
          }
          $realvalue = implode(", ", $names);
        } else {
          $realvalue = $value;
        }

        // add information
        $text .= " - {$name}: {$realvalue}\n";
      }
    } catch (\Exception $e) {
      $text .= $e->getMessage();
    }
    return $text;
  }

  /**
   * authinfo generator
   *
   * An authinfo code is the credential that authorises a domain transfer away
   * from this registrar, so it needs to be unguessable -- and it is the one
   * credential here that a person copies off a screen and reads to somebody
   * else, so it also needs to survive that.
   *
   * PasswordGenerator answers both: 16 characters drawn from a set with no
   * l/I or O/0 to confuse and nothing a shell would eat. Those 16 characters
   * carry about 95 bits, where the hex this used to return spent the same 16
   * on 64.
   *
   * 16 by choice, not by rule. An authinfo is `eppcom:pwAuthInfoType`, an
   * unrestricted normalizedString -- the min-6/max-16 `pwType` limit applies
   * to the <login> password, not to this. Matching that ceiling anyway keeps
   * one length for every credential the registry is sent.
   *
   * @return string 16-character random authinfo code
   */
  public function authinfo(): string {
    return PasswordGenerator::forAuthinfo();
  }

  /**
   * set error code and message
   *
   * @param string $msg error message
   * @param string $code 4-digit error code
   */
  protected function setError(string $msg, string $code = "0000"): void {
    $this->svMsg = $msg;
    $this->svCode = $code;
  }

  /**
   * get error message
   *
   * @return string error message
   */
  public function getError(): string {
    $msg = "";

    // only try to set a message text if we got a EPP error message
    if ( ! empty($this->svCode)) {
      $msg = " EPP code '".$this->svCode."': ".$this->svMsg;
      if ( ! empty($this->extValueReason)) {
        $msg .= " / extended reason '".$this->extValueReasonCode."': ".$this->extValueReason;
      }
    }

    if ($this->debug) {
      // $result/$xmlQuery are only populated once ExecuteQuery() has run --
      // getError() is reachable before that (any setError() on a missing
      // precondition), so neither can be dereferenced unguarded here
      $msg = "Generic error (if set):\n".
             "-----------------------\n".
             $msg."\n".
             "\n".
             "Query sent to server:\n".
             "---------------------\n".
             ($this->xmlQuery ?? "[no query was sent]")."\n".
             "\n".
             "Response received from server:\n".
             "------------------------------\n".
             ($this->result?->body ?: "[no response was received]")."\n";
    }

    return $msg;
  }

  /**
   * The object-specific part of a response, if the answer actually carries one.
   *
   * Every parser needs the same three things to be true before it can read a
   * <resData>: the document parsed, it has a <resData>, and the namespace the
   * caller is asking about is declared in it. Chaining into it without
   * checking is what produced six separate faults in this codebase, because
   * SimpleXML answers each missing step with an empty element rather than
   * null, so the failure only surfaces as warnings much further down.
   *
   * @param string $prefix the namespace prefix wanted, e.g. 'domain'
   * @return \SimpleXMLElement|null the children in that namespace, or null
   */
  protected function responseData(string $prefix): ?\SimpleXMLElement {
    if ( ! $this->xmlResult instanceof \SimpleXMLElement || ! isset($this->xmlResult->response->resData)) {
      return null;
    }

    $ns = $this->xmlResult->getNamespaces(TRUE);
    if ( ! isset($ns[$prefix])) {
      return null;
    }

    $children = $this->xmlResult->response->resData->children($ns[$prefix]);
    return (count($children) > 0) ? $children : null;
  }

  /**
   * The same, for <extension> rather than <resData>.
   *
   * @param string $prefix the namespace prefix wanted, e.g. 'extcon'
   * @return \SimpleXMLElement|null the children in that namespace, or null
   */
  protected function responseExtension(string $prefix): ?\SimpleXMLElement {
    if ( ! $this->xmlResult instanceof \SimpleXMLElement || ! isset($this->xmlResult->response->extension)) {
      return null;
    }

    $ns = $this->xmlResult->getNamespaces(TRUE);
    if ( ! isset($ns[$prefix])) {
      return null;
    }

    $children = $this->xmlResult->response->extension->children($ns[$prefix]);
    return (count($children) > 0) ? $children : null;
  }

  /**
   * Ask the registry which of $names are available.
   *
   * Contact and Domain ran the same thirty-odd lines for this: normalise the
   * argument to a non-empty list, cap it at max_check, send the check, and
   * read <chkData><cd> back into handle/name => [available, reason]. Only six
   * values ever differed, and they are the parameters below. Keeping the two
   * apart cost more than the parameters do -- the same class of bug had to be
   * found and fixed twice, once in each (an argument cast to array before the
   * emptiness test, so the test could never fire).
   *
   * @param array|string|null $names what to check; null falls back to $fallback
   * @param string $fallback this object's own identity, for the no-argument call
   * @param string $emptyError what to say when nothing checkable was given
   * @param string $prefix the object's namespace prefix, 'contact' or 'domain'
   *                       -- also the first half of the clTRType
   * @param string $idElement the <cd> child carrying the identifier: contacts
   *                          answer with <id>, domains with <name>
   * @param callable $buildXml function(string $clTRID, array $names): string
   * @return CheckResult availability per name, or a failure carrying getError()
   */
  protected function checkAvailability(
    array|string|null $names,
    string $fallback,
    string $emptyError,
    string $prefix,
    string $idElement,
    callable $buildXml
  ): CheckResult {
    if ($names === null) {
      $names = $fallback;
    }
    if ( ! is_array($names)) {
      $names = array($names);
    }

    // filtered after the cast, and the emptiness tested on the result: array(null)
    // and array("") are one-element arrays, so a check against the argument as
    // given never fired for the case it was meant to catch
    $names = array_values(array_filter($names, fn($n) => (string)$n !== ""));
    if (empty($names)) {
      $this->setError($emptyError);
      return CheckResult::failure($this->getError());
    }

    $this->xmlQuery = $buildXml(
      $this->client->set_clTRID(),
      array_slice($names, 0, $this->max_check)
    );

    if ( ! $this->ExecuteQuery("{$prefix}-check", implode(";", $names))) {
      return CheckResult::failure($this->getError());
    }

    $tmp = $this->responseData($prefix);
    if ($tmp === null || ! isset($tmp->chkData->cd)) {
      $this->setError("The registry accepted the check but returned no availability data.");
      return CheckResult::failure($this->getError());
    }

    $availability = [];
    foreach ($tmp->chkData->cd as $cd) {
      $available = (string)$cd->$idElement->attributes()->avail === "true";
      $availability[(string)$cd->$idElement] = [
        'available' => $available,
        'reason'    => $available ? 'OK' : (string)$cd->reason,
      ];
    }

    return CheckResult::of($availability);
  }

  /**
   * Validate a status change and apply it to $this->status.
   *
   * The half of updateStatus() that never differed between Contact and Domain:
   * only the set of states each accepts does, and that is the parameter. The
   * object's own identity check and the XML it then sends stay with the
   * caller, which is where they actually differ.
   *
   * @param string[] $allowed the states this object type accepts
   * @param string $state the state to add or remove
   * @param string $adddel 'add' or 'rem'
   * @return bool false with the error already set, so the caller can return it
   */
  protected function applyStatusChange(array $allowed, string $state, string $adddel): bool {
    if ( ! in_array($state, $allowed, true)) {
      $this->setError(
        "State '".$state."' not allowed, expecting one of '" . implode("', '", $allowed) . "'."
      );
      return FALSE;
    }

    switch ($adddel) {
      case "add":
        $this->status = array_merge($this->status, array($state));
        return TRUE;
      case "rem":
        $this->status = array_diff($this->status, array($state));
        return TRUE;
      default:
        $this->setError("Function '".$adddel."' not allowed, expecting either 'add' or 'rem'.");
        return FALSE;
    }
  }

  /**
   * execute ever returning queries to the server
   *
   * @param string $clTRType client transaction type
   * @param string $clTRObject client transaction object
   * @return bool status
   */
  protected function ExecuteQuery(string $clTRType, string $clTRObject): bool {
    // store request -- only under $debug; see the property's own note on what
    // ends up in these tables
    if ($this->debug) {
      R::exec("
        INSERT INTO transactions (cl_trid, cl_trtype, cl_trobject, cl_trdata)
        VALUES (:cl_trid, :cl_trtype, :cl_trobject, :cl_trdata)
      ", [
        ':cl_trid'     => $this->client->get_clTRID(),
        ':cl_trtype'   => $clTRType,
        ':cl_trobject' => $clTRObject,
        ':cl_trdata'   => $this->xmlQuery,
      ]);
    }

    // send request + parse response
    $this->result = $this->client->sendRequest($this->xmlQuery);
    $this->xmlResult = $this->client->parseResponse($this->result->body);

    // An unparseable answer must not reach the object parsers. SimpleXML
    // answers a missing child with an empty element, so without this every
    // one of them walks a chain of nothing and reports warnings and nulls
    // instead of a failure.
    if ( ! $this->xmlResult instanceof \SimpleXMLElement) {
      $this->setError("The registry's answer could not be parsed as XML.");
      $this->storeResponse();
      return FALSE;
    }

    // look for a server response code
    if (isset($this->xmlResult->response->result)) {
      // look for a server message
      $this->svMsg = isset($this->xmlResult->response->result->msg) ? (string)$this->xmlResult->response->result->msg : "";

      // look for a server message code
      $this->svCode = (string)$this->xmlResult->response->result['code'];
      switch (substr($this->svCode, 0, 1)) {
        case "1":
          $return_code = TRUE;
          break;
        case "2":
        default:
          $return_code = FALSE;
          break;
      }

      // look for an extended server error message and code
      $ns = $this->xmlResult->getNamespaces(TRUE);
      if (isset($this->xmlResult->response->result->extValue->reason, $ns['extepp'])) {
        $tmp = $this->xmlResult->response->result->extValue->value->children($ns['extepp']);
        $this->extValueReasonCode = (string)$tmp->reasonCode;
        $this->extValueReason = (string)$this->xmlResult->response->result->extValue->reason;
      } else {
        $this->extValueReasonCode = '';
        $this->extValueReason = '';
      }
    } else {
      $this->setError("Unexpected result (no xml response code).");
      $return_code = FALSE;
    }

    // look for a server transaction ID
    $this->svTRID = isset($this->xmlResult->response->trID->svTRID) ? (string)$this->xmlResult->response->trID->svTRID : "";

    // store response
    $this->storeResponse();

    return $return_code;
  }

  /**
   * Record the answer, under $debug. Its own method because an unparseable
   * answer returns early and must still be recorded -- that is exactly the
   * response somebody turning debug on wants to look at.
   */
  private function storeResponse(): void {
    if ( ! $this->debug) {
      return;
    }

    R::exec("
      INSERT INTO responses (cl_trid, sv_trid, sv_code, status, sv_httpcode, sv_httpheaders, sv_httpdata, extvaluereasoncode, extvaluereason)
      VALUES (:cl_trid, :sv_trid, :sv_code, :status, :sv_httpcode, :sv_httpheaders, :sv_httpdata, :extvaluereasoncode, :extvaluereason)
    ", [
      ':cl_trid'            => $this->client->get_clTRID(),
      ':sv_trid'            => $this->svTRID,
      ':sv_code'            => $this->svCode,
      ':status'             => 0,
      ':sv_httpcode'        => $this->result?->code,
      ':sv_httpheaders'     => $this->result?->headers,
      ':sv_httpdata'        => $this->result?->body,
      ':extvaluereasoncode' => $this->extValueReasonCode,
      ':extvaluereason'     => $this->extValueReason,
    ]);
  }
}

<?php

namespace Net\EPP;

require_once dirname(__FILE__).'/../../config/constants.php';

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
 * @package     Net\EPP\AbstractObject
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
  public    $result;    // HTTP response string
  public    $xmlResult; // parsed reponse (SimpleXMLElement may be incomplete)

  public    $svCode;
  public    $svMsg;
  public    $svTRID;
  public    $extValueReasonCode;
  public    $extValueReason;

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
   * from this registrar, so it needs to be unguessable. The previous
   * implementation -- substr(md5(rand()), 0, 16) -- was not: rand() is seeded
   * from a small state and is not cryptographically secure, so the md5 hash of
   * it carries at most the ~31 bits of entropy rand() had to give, no matter
   * how many hex characters are kept. random_bytes() is the CSPRNG, and eight
   * of its bytes hex-encode to exactly the 16 characters this returns, with a
   * full 64 bits behind them. Matches what Contact::generateHandle() and the
   * registry-password rotation in Helpers already use.
   *
   * @return string 16-character random authinfo code
   */
  public function authinfo(): string {
    return bin2hex(random_bytes(8));
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
             ($this->result['body'] ?? "[no response was received]")."\n";
    }

    return $msg;
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
    $this->xmlResult = $this->client->parseResponse($this->result['body']);

    // look for a server response code
    if (is_object($this->xmlResult->response->result)) {
      // look for a server message
      $this->svMsg = (is_object($this->xmlResult->response->result->msg)) ? (string)$this->xmlResult->response->result->msg : "";

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
      if (is_object($this->xmlResult->response->result->extValue->reason)) {
        $ns = $this->xmlResult->getNamespaces(TRUE);
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
    $this->svTRID = (isset($this->xmlResult->response->trID->svTRID) && is_object($this->xmlResult->response->trID->svTRID)) ? (string)$this->xmlResult->response->trID->svTRID : "";

    // store response
    if ($this->debug) {
      R::exec("
        INSERT INTO responses (cl_trid, sv_trid, sv_code, status, sv_httpcode, sv_httpheaders, sv_httpdata, extvaluereasoncode, extvaluereason)
        VALUES (:cl_trid, :sv_trid, :sv_code, :status, :sv_httpcode, :sv_httpheaders, :sv_httpdata, :extvaluereasoncode, :extvaluereason)
      ", [
        ':cl_trid'            => $this->client->get_clTRID(),
        ':sv_trid'            => $this->svTRID,
        ':sv_code'            => $this->svCode,
        ':status'             => 0,
        ':sv_httpcode'        => $this->result['code'],
        ':sv_httpheaders'     => $this->result['headers'],
        ':sv_httpdata'        => $this->result['body'],
        ':extvaluereasoncode' => $this->extValueReasonCode,
        ':extvaluereason'     => $this->extValueReason,
      ]);
    }

    return $return_code;
  }
}

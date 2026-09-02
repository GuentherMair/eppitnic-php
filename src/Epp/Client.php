<?php

namespace Eppitnic\Epp;

use Eppitnic\Config;
use Eppitnic\Epp\Transport\Curl;
use Eppitnic\Epp\Transport\Transport;
use Eppitnic\Support\PasswordGenerator;

/**
 * A simple class handling the EPP communication through cURL.
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
 * @package     Eppitnic\Epp\Client
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Client
{
  public $EPPCfg;

  /**
   * Diagnostics for every object built from this client: AbstractObject copies
   * it at construction, so setting it once here covers the Session, Domain and
   * Contact a request creates. See AbstractObject::$debug for what it turns on.
   */
  public bool $debug = false;

  private $clTRID;
  private $headers = array('content-type' => 'text/xml; charset=UTF-8');

  protected $httpClient;
  protected $curl_cookie_dir;

  /**
   * Class constructor
   *
   *  - read configuration from the `settings` DB table (via Config::get())
   *  - initialize the HTTP client
   *
   * @param string $serverOverride optional server URL to use instead of epp.server (eg.
   *                    nic.it's "-deleted" endpoint for restoring domains)
   */
  public function __construct(?string $serverOverride = null) {
    $epp = Config::get('epp');
    $region = Config::get('region');
    $this->EPPCfg = (object)[
      'timezone'        => $region['timezone'],
      'server'          => $serverOverride ?: $epp['server'],
      'port'            => $epp['port'],
      'interface'       => $epp['interface'],
      'username'        => $epp['username'],
      'password'        => $epp['password'],
      'lang'            => $epp['lang'],
      'cl_trid_prefix'  => $epp['cl_trid_prefix'],
      'certificatefile' => Config::get('certificatefile'),
      'debugfile'       => Config::get('debugfile'),
      'cookie_dir'      => Config::get('cookie_dir'),
      'dnssec'          => (object)Config::get('dnssec'),
    ];

    // setup default time zone
    date_default_timezone_set($this->EPPCfg->timezone ?: "Europe/Rome");

    // configure temporary folder for storing curl's cookies
    $this->curl_cookie_dir = (@empty($this->EPPCfg->cookie_dir)) ? '/tmp' : $this->EPPCfg->cookie_dir;

    // initialize httpClient
    $this->httpClient = new Curl($this->EPPCfg->server, '', '', $this->curl_cookie_dir);
    $this->httpClient->setHeaders($this->headers);

    // set server port
    if ( ! @empty($this->EPPCfg->port)) {
      $this->httpClient->setPort((int)$this->EPPCfg->port);
    }

    // set debug filename
    if ( ! @empty($this->EPPCfg->debugfile)) {
      $this->httpClient->setDebugFile($this->EPPCfg->debugfile);
    }

    // setup client certificate
    if ( ! @empty($this->EPPCfg->certificatefile)) {
      if (is_readable($this->EPPCfg->certificatefile)) {
        $this->httpClient->setClientCert($this->EPPCfg->certificatefile);
      } else if (is_readable(realpath(EPPITNIC_ROOT.'/'.$this->EPPCfg->certificatefile))) {
        $this->httpClient->setClientCert(realpath(EPPITNIC_ROOT.'/'.$this->EPPCfg->certificatefile));
      }
    }

    // setup leaving interface
    if ( ! @empty($this->EPPCfg->interface)) {
      $this->httpClient->setInterface($this->EPPCfg->interface);
    }

    // set client transaction ID
    $this->set_clTRID();
  }

  /**
   * Replace the constructor's HTTP transport. Test suite only, to exercise
   * request generation and parsing without a registry; production already has
   * a fully configured Curl instance.
   *
   * @param Transport $transport the transport to send subsequent requests through
   */
  public function setTransport(Transport $transport): void {
    $this->httpClient = $transport;
  }

  /**
   * reset curl connection by removing the curl cookie file
   *
   * @return bool
   */
  public function resetHttpClientCookie(): bool {
    return unlink($this->httpClient->getCookieFileLocation());
  }

  /**
   * initialize the client transaction ID
   *
   * @return string a random transaction ID, also stored to $clTRID
   */
  public function set_clTRID(): string {
    // The random tail only separates two transactions in the same second, and
    // is hex because a clTRID is an identifier, not a secret -- a database key,
    // quoted back by the registry, and what someone greps a log for
    $this->clTRID = $this->EPPCfg->cl_trid_prefix."-".time()."-".substr(PasswordGenerator::token(3), 0, 5);
    if (strlen($this->clTRID) > 32) {
      $this->clTRID = substr($this->clTRID, -32);
    }
    return $this->clTRID;
  }

  /**
   * retrieve current transaction ID
   *
   * @return string the current transaction ID stored in $clTRID
   */
  public function get_clTRID(): string {
    return $this->clTRID;
  }

  /**
   * send a request to the EPP server
   *
   * @return HttpResponse the exchange -- body, status, headers, transport error
   */
  public function sendRequest(string $data): HttpResponse {
    return new HttpResponse(
      $this->httpClient->query($data),
      $this->httpClient->getHttpStatus(),
      $this->httpClient->getHttpHeaders(),
      $this->httpClient->getHttpError()
    );
  }

  /**
   * convert an xml response to an object
   *
   * @param string $xml the xml string to parse
   * @return \SimpleXMLElement|false the parsed document, or false if it wasn't well-formed
   */
  public function parseResponse(string $xml): \SimpleXMLElement|false {
    return @simplexml_load_string($xml);
  }
}

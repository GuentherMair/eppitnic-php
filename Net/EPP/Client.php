<?php

/**
 * A simple class handling the EPP communication through cURL.
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
 * @package     Net_EPP_Client
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: Client.php 463 2017-02-07 18:55:25Z gunny $
 */

/**
 * define the PHP_VERSION_ID (predefined as of 5.2.7)
 */
if ( ! defined('PHP_VERSION_ID')) {
  $version = explode('.', PHP_VERSION);
  define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));
}

/**
 * using lots of PHP5 functions like __construct, __destruct,
 * simplexml_load_string, simplexml_load_file, this won't work
 * with PHP < 5
 */
if (PHP_VERSION_ID < 50300) {
  $major = (int)floor(PHP_VERSION_ID / 10000);
  $minor = (int)floor((PHP_VERSION_ID % 10000) / 100);
  $rev = (int)(PHP_VERSION_ID % 100);
  echo "This class (" . __FILE__ . ") requires at least PHP 5.3 (Smarty 3 and other limitations). You are running PHP ${major}.${minor}.${rev}!\n";
  exit;
}

/**
 * This is an unmodified Smarty set. You may get the newest
 * release from http://www.smarty.net, just bear in mind to
 * link "framework" to the "libs" subfolder!
 */
if ( ! class_exists('Smarty'))
  require_once 'libs/smarty3/libs/Smarty.class.php';

/**
 * Include curl class handler
 */
if ( ! class_exists('Net_EPP_Curl'))
  require_once 'Net/EPP/Curl.php';

/**
 * This class extends Smarty (a templating system) so we
 * can easily use variable-assignments directly with this
 * derived class, ie.
 *
 *   $nic = new Net_EPP_Client("config.xml");
 *   $nic->assign('username', $nic->EPPCfg->username);
 *
 */
class Net_EPP_Client extends Smarty
{
  public $EPPCfg;

  private $clTRID;
  private $headers = array('content-type' => 'text/xml; charset=UTF-8');

  protected $cURLresponse;
  protected $httpClient;
  protected $curl_cookie_dir;

  /**
   * Class constructor
   *
   *  - read configuration file
   *  - initialize smarty parent class and settings
   *  - initialize HTTP Client
   *
   * @access   public
   * @param    string  configuration file or XML configuration string
   */
  public function __construct($cfg = null) {
    if ($cfg === null)
      $cfg = realpath(dirname(__FILE__).'/../../config.xml');

    if (is_readable($cfg)) {
      $this->EPPCfg = @simplexml_load_file($cfg);
    } else {
      $this->EPPCfg = @simplexml_load_string($cfg);
      if ($this->EPPCfg === FALSE)
        exit("FATAL ERROR: config file '".$cfg."' not readable or not a XML string\n");
    }

    // setup default time zone
    if (@isset($this->EPPCfg->timezone))
      date_default_timezone_set($this->EPPCfg->timezone);
    else
      date_default_timezone_set("Europe/Rome");

    // call Smarty class constructor
    parent::__construct();

    // configure smarty
    $this->use_sub_dirs = (@empty($this->EPPCfg->smarty->use_sub_dirs)) ? FALSE                                                : $this->EPPCfg->smarty->use_sub_dirs; // safe-mode restriction
    $this->template_dir = (@empty($this->EPPCfg->smarty->template_dir)) ? realpath(dirname(__FILE__).'/../../templates/')      : $this->EPPCfg->smarty->template_dir;
    $this->config_dir   = (@empty($this->EPPCfg->smarty->config_dir))   ? realpath(dirname(__FILE__).'/../../smarty/config/')  : $this->EPPCfg->smarty->config_dir;
    $this->compile_dir  = (@empty($this->EPPCfg->smarty->compile_dir))  ? realpath(dirname(__FILE__).'/../../smarty/compile/') : $this->EPPCfg->smarty->compile_dir;
    $this->cache_dir    = (@empty($this->EPPCfg->smarty->cache_dir))    ? realpath(dirname(__FILE__).'/../../smarty/cache/')   : $this->EPPCfg->smarty->cache_dir;

    // configure temporary folder for storing curl's cookies
    $this->curl_cookie_dir = (@empty($this->EPPCfg->cookie_dir)) ? '/tmp' : $this->EPPCfg->cookie_dir;

    // smarty minimum precaution (otherwise we could easily run into a hard to debug dead end)
    if ( ! is_writeable($this->compile_dir))
      if (is_writeable('/tmp')) {
        // I'm not using "umask" because of the notice here: http://www.php.net/umask
        trigger_error("The folder '".$this->compile_dir."' was not writable and a failback to '/tmp' is currently active. Grant write permissions to the correct folder!", E_USER_NOTICE);
        $this->_file_perms = 0600;
        $this->_dir_perms = 0700;
        $this->compile_dir = '/tmp';
      } else {
        exit("[".__FILE__." @ ".__LINE__."] Smarty compile folder '".$this->compile_dir."' is not writeable. Solve problem before trying to continue.\n");
      }

    // initialize httpClient
    $this->httpClient = new Net_EPP_Curl($this->EPPCfg->server, '', '', $this->curl_cookie_dir);
    $this->httpClient->setHeaders($this->headers);

    // set server port
    if ( ! @empty($this->EPPCfg->port))
      $this->httpClient->setPort((int)$this->EPPCfg->port);

    // set debug filename
    if ( ! @empty($this->EPPCfg->debugfile))
      $this->httpClient->setDebugFile($this->EPPCfg->debugfile);

    // setup client certificate
    if ( ! @empty($this->EPPCfg->certificatefile)) {
      if (is_readable($this->EPPCfg->certificatefile))
        $this->httpClient->setClientCert($this->EPPCfg->certificatefile);
      else if (is_readable(realpath(dirname(__FILE__).'/../../'.$this->EPPCfg->certificatefile)))
        $this->httpClient->setClientCert(realpath(dirname(__FILE__).'/../../'.$this->EPPCfg->certificatefile));
    }

    // setup leaving interface
    if ( ! @empty($this->EPPCfg->interface))
      $this->httpClient->setInterface($this->EPPCfg->interface);

    // set client transaction ID
    $this->set_clTRID();
  }

  /**
   * smarty version wrapper
   *
   * @access   public
   */
  public function clearAllAssign() {
    if (PHP_VERSION_ID < 50300)
      return parent::clear_all_assign(); // Smarty 2
    else
      return parent::clearAllAssign();   // Smarty 3
  }

  /**
   * reset curl connection by removing the curl cookie file
   *
   * @access   public
   * @return   boolean
   */
  public function resetHttpClientCookie() {
    return unlink($this->httpClient->getCookieFileLocation());
  }

  /**
   * initialize the client transaction ID
   *
   * @access   public
   * @return   string  a random transaction ID, also stored to $clTRID
   */
  public function set_clTRID() {
    $this->clTRID = $this->EPPCfg->clTRIDprefix."-".time()."-".substr(md5(rand()), 0, 5);
    if (strlen($this->clTRID) > 32)
      $this->clTRID = substr($this->clTRID, -32);
    return $this->clTRID;
  }

  /**
   * retrieve current transaction ID
   *
   * @access   public
   * @return   string  the current transaction ID stored in $clTRID
   */
  public function get_clTRID() {
    return $this->clTRID;
  }

  /**
   * send a request to the EPP server
   *
   * @access   public
   * @return   array   the  response: (int) code, (array) headers, (string) body
   */
  public function sendRequest($data) {
    $this->cURLresponse['body'] = $this->httpClient->query($data);
    $this->cURLresponse['code'] = $this->httpClient->getHttpStatus();
    $this->cURLresponse['headers'] = $this->httpClient->getHttpHeaders();
    $this->cURLresponse['error'] = $this->httpClient->getHttpError();

    return $this->fetchResponse();
  }

  /**
   * fetch the latest response from the EPP server
   *
   * @access   public
   * @return   array   the latest response: (int) code, (array) headers, (string) body
   */
  public function fetchResponse() {
    return $this->cURLresponse;
  }

  /**
   * convert an xml response to an object
   *
   * @access   public
   * @param    string  option xml string to be parsed
   * @return   object  xml class structure
   */
  public function parseResponse($xml = null) {
    if ($xml == null) {
      $response = $this->fetchResponse();
      return @simplexml_load_string($response[body]);
    } else {
      return @simplexml_load_string($xml);
    }
  }

  /**
   * class destructor
   *
   * @access   public
   */
  public function __destruct() {
  }
}

<?php

use Smarty\Smarty;

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
 * @package     Net_EPP_Client
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

/**
 * Smarty and other third-party dependencies are managed through Composer.
 */
require_once dirname(__FILE__).'/../../vendor/autoload.php';

/**
 * Include curl class handler
 */
if ( ! class_exists('Net_EPP_Curl')) {
  require_once dirname(__FILE__).'/Curl.php';
}

/**
 * generic script exit codes (1-9), for use by CLI scripts / examples
 */
if ( ! defined('SYNTAX_ERROR'))      define('SYNTAX_ERROR', 1);       // wrong/missing CLI arguments
if ( ! defined('FILE_NOT_READABLE')) define('FILE_NOT_READABLE', 2);  // input file/CSV unreadable
if ( ! defined('INVALID_INPUT'))     define('INVALID_INPUT', 3);      // eg. no valid .it domain given
if ( ! defined('CONFIG_ERROR'))      define('CONFIG_ERROR', 4);       // config/config.json missing/not writable
if ( ! defined('OUTPUT_ERROR'))      define('OUTPUT_ERROR', 5);       // unable to write an output file

/**
 * This class extends Smarty (a templating system) so we
 * can easily use variable-assignments directly with this
 * derived class, ie.
 *
 *   $nic = new Net_EPP_Client();
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
   *  - read configuration from config/config.json (via helpers/config.php)
   *  - initialize smarty parent class and settings
   *  - initialize HTTP Client
   *
   * @access   public
   * @param    string  optional server URL to use instead of epp.server (eg.
   *                    nic.it's "-deleted" endpoint for restoring domains)
   */
  public function __construct($serverOverride = null) {
    require_once dirname(__FILE__).'/../../helpers/config.php';

    $epp = getConfig('epp');
    $region = getConfig('region');
    $this->EPPCfg = (object)[
      'timezone'        => $region['timezone'],
      'server'          => $serverOverride ?: $epp['server'],
      'port'            => $epp['port'],
      'interface'       => $epp['interface'],
      'username'        => $epp['username'],
      'password'        => $epp['password'],
      'lang'            => $epp['lang'],
      'cl_trid_prefix'  => $epp['cl_trid_prefix'],
      'certificatefile' => getConfig('certificatefile'),
      'debugfile'       => getConfig('debugfile'),
      'cookie_dir'      => getConfig('cookie_dir'),
      'dnssec'          => (object)getConfig('dnssec'),
      'smarty'          => (object)getConfig('smarty'),
    ];

    // setup default time zone
    date_default_timezone_set($this->EPPCfg->timezone ?: "Europe/Rome");

    // call Smarty class constructor
    parent::__construct();

    // resolve smarty settings from config (or conventional defaults)
    $use_sub_dirs = (@empty($this->EPPCfg->smarty->use_sub_dirs)) ? FALSE                                                : $this->EPPCfg->smarty->use_sub_dirs; // safe-mode restriction
    $template_dir = (@empty($this->EPPCfg->smarty->template_dir)) ? realpath(dirname(__FILE__).'/../../templates/')      : $this->EPPCfg->smarty->template_dir;
    $config_dir   = (@empty($this->EPPCfg->smarty->config_dir))   ? realpath(dirname(__FILE__).'/../../smarty/config/')  : $this->EPPCfg->smarty->config_dir;
    $compile_dir  = (@empty($this->EPPCfg->smarty->compile_dir))  ? realpath(dirname(__FILE__).'/../../smarty/compile/') : $this->EPPCfg->smarty->compile_dir;
    $cache_dir    = (@empty($this->EPPCfg->smarty->cache_dir))    ? realpath(dirname(__FILE__).'/../../smarty/cache/')   : $this->EPPCfg->smarty->cache_dir;

    // configure temporary folder for storing curl's cookies
    $this->curl_cookie_dir = (@empty($this->EPPCfg->cookie_dir)) ? '/tmp' : $this->EPPCfg->cookie_dir;

    // smarty minimum precaution: verify write access *before* handing the
    // folders to smarty (otherwise we could easily run into a hard to debug
    // dead end). template_dir/config_dir are read-only for our usage, so only
    // the folders smarty actually writes into need this.
    $compile_dir = $this->_ensureWritableDir($compile_dir);
    $cache_dir   = $this->_ensureWritableDir($cache_dir);

    $this->setUseSubDirs($use_sub_dirs);
    $this->setTemplateDir($template_dir);
    $this->setConfigDir($config_dir);
    $this->setCompileDir($compile_dir);
    $this->setCacheDir($cache_dir);

    // initialize httpClient
    $this->httpClient = new Net_EPP_Curl($this->EPPCfg->server, '', '', $this->curl_cookie_dir);
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
      } else if (is_readable(realpath(dirname(__FILE__).'/../../'.$this->EPPCfg->certificatefile))) {
        $this->httpClient->setClientCert(realpath(dirname(__FILE__).'/../../'.$this->EPPCfg->certificatefile));
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
   * smarty version wrapper
   *
   * @access   public
   */
  public function clearAllAssign() {
    return parent::clearAllAssign();
  }

  /**
   * make sure a directory is writable, falling back to the system temp
   * folder if it is not
   *
   * @access   private
   * @param    string  directory to verify
   * @return   string  the given directory, or a writable fallback
   */
  private function _ensureWritableDir($dir) {
    if (is_writeable($dir)) {
      return $dir;
    }

    $fallback = sys_get_temp_dir();
    if ( ! is_writeable($fallback)) {
      exit("[".__FILE__." @ ".__LINE__."] Neither '".$dir."' nor the system temp folder '".$fallback."' are writeable. Solve problem before trying to continue.\n");
    }

    trigger_error("The folder '".$dir."' was not writable and a failback to '".$fallback."' is currently active. Grant write permissions to the correct folder!", E_USER_NOTICE);
    return $fallback;
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
    $this->clTRID = $this->EPPCfg->cl_trid_prefix."-".time()."-".substr(md5(rand()), 0, 5);
    if (strlen($this->clTRID) > 32) {
      $this->clTRID = substr($this->clTRID, -32);
    }
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
      return @simplexml_load_string($response['body']);
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

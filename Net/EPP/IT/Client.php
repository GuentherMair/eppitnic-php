<?php

/**
 * A simple class handling the EPP communication with IT-NIC.
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
 * @package     Net_EPP_IT_Client
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: Client.php 17 2009-05-23 21:00:39Z gunny $
 */

/*
 * using lots of PHP5 functions like __construct, __destruct,
 * simplexml_load_string, simplexml_load_file, this won't work
 * with PHP < 5
 */
if ( (int)substr(phpversion(),0,strpos(phpversion(), '.')) < 5 ) {
  echo "This class (" . __FILE__ . ") requires PHP5 at least!\n";
  exit;
}

/**
 * HTTP/Client.php is a PEAR base class and if this throws an
 * error simply typing "pear install HTTP_Client" should solve
 * the problem for you on most systems (pear comes packaged
 * alongside php)
 */
require_once 'HTTP/Client.php';

/**
 * This is an unmodified Smarty set. You may get the newest
 * release from http://www.smarty.net, just bear in mind to
 * link "framework" to the "libs" subfolder!
 */
require_once 'libs/smarty/libs/Smarty.class.php';

/**
 * This class extends Smarty (a templating system) so we
 * can easily use variable-assignments directly with this
 * derived class, ie.
 *
 *   $nic = new Net_EPP_IT_Client("config.xml");
 *   $nic->assign('username', $nic->EPPCfg->username);
 *
 */
class Net_EPP_IT_Client extends Smarty
{
  var $EPPCfg;
  var $EPPClient;

  private $clTRID;
  private $DEFAULT_HEADERS = array('content-type' => 'text/xml; charset=UTF-8');

  /**
   * Class constructor
   *
   *  - read configuration file
   *  - initialize smarty parent class and settings
   *  - initialize HTTP Client
   *
   * @access   public
   * @param    string  configuration file
   */
  function __construct($configfile = "config.xml") {
    if ( ! is_readable($configfile) )
      exit("FATAL ERROR: config file '".$configfile."' not readable\n");

    $this->EPPCfg = @simplexml_load_file($configfile);

    parent::__construct();
    $this->use_sub_dirs = $this->EPPCfg->smarty->use_sub_dirs; // safe-mode restriction
    $this->template_dir = $this->EPPCfg->smarty->template_dir;
    $this->config_dir   = $this->EPPCfg->smarty->config_dir;
    $this->compile_dir  = $this->EPPCfg->smarty->compile_dir;
    $this->cache_dir    = $this->EPPCfg->smarty->cache_dir;

    $this->EPPClient = new HTTP_Client(null, $this->DEFAULT_HEADERS);
    $this->set_clTRID();
  }

  /**
   * initialize the client transaction ID
   *
   * @access   public
   * @return   string  a random transaction ID, also stored to $clTRID
   */
  function set_clTRID() {
    $this->clTRID = $this->EPPCfg->username."-".mktime()."-".substr(md5(rand()), 0, 5);
    if ( strlen($this->clTRID) > 32 )
      $this->clTRID = substr($this->clTRID, -32);
    return $this->clTRID;
  }

  /**
   * retrieve current transaction ID
   *
   * @access   public
   * @return   string  the current transaction ID stored in $clTRID
   */
  function get_clTRID() {
    return $this->clTRID;
  }

  /**
   * send a request to the EPP server
   *
   * @access   public
   * @return   array   the HTTP_Client response: (int) code, (array) headers, (string) body
   */
  function sendRequest($data) {
    if ( $this->EPPClient->valid() ) $this->EPPClient->next();
    $this->EPPClient->post($this->EPPCfg->server, utf8_encode($data), true);
    return $this->fetchResponse();
  }

  /**
   * fetch the latest response from the EPP server
   *
   * @access   public
   * @return   array   the latest HTTP_Client response: (int) code, (array) headers, (string) body
   */
  function fetchResponse() {
    return $this->EPPClient->current();
  }

  /**
   * convert an xml response to an object
   *
   * @access   public
   * @param    string  option xml string to be parsed
   * @return   object  xml class structure
   */
  function parseResponse($xml = null) {
    if ( $xml == null ) {
      $response = $this->fetchResponse();
      return @simplexml_load_string($response[body]);
    } else {
      return @simplexml_load_string($xml);
    }
  }

  /**
   * class destructor
   * resets all HTTP_Client data (cookies, responses, default headers)
   *
   * @access   public
   */
  function __destruct() {
    if ( is_object($this->EPPClient) ) $this->EPPClient->reset();
  }

}

?>

<?php

/**
 * A simple class handling HTTP sessions through cURL.
 *
 * Available methods:
 *  - setClientCert
 *  - setInterface
 *  - setMaxRedirects
 *  - setTimeout
 *  - setReferer
 *  - setBinaryTransfer
 *  - setCookieFileLocation
 *  - getCookieFileLocation
 *  - setPost
 *  - setUrl
 *  - setUserAgent
 *  - setHeaders
 *  - setDebugFile
 *  - query
 *  - getHttpStatus
 *  - getHttpHeaders
 *  - getHttpBody
 *  - getHttpError
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
 * @package     Net_EPP_Curl
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id$
 */
class Net_EPP_Curl
{
  protected $_useragent = 'PHP Net_EPP_Curl 1.1';
  protected $_url;
  protected $_port;
  protected $_certFile;
  protected $_authName;
  protected $_authPass;
  protected $_cookieFileLocation;

  protected $_referer;
  protected $_postHeaders = array('Expect:');
  protected $_post = false;
  protected $_followLocation = true;
  protected $_timeout = 30;
  protected $_maxRedirects = 4;
  protected $_interface = "";
  protected $_binaryTransfer = false;
  protected $_debugFile = false;

  protected $_status;
  protected $_headers;
  protected $_body;
  protected $_error;

  public function __construct($url, $authName = '', $authPass = '', $cookie_dir = '/tmp') {
    $this->_url = $url;
    $this->_cookieFileLocation = $cookie_dir.'/url_'.md5($url).'-uid_'.posix_getuid().'-cookie.txt';
    $this->_authName = $authName;
    $this->_authPass = $authPass;

    if (file_exists($this->_cookieFileLocation))
      if ( ! is_writeable($this->_cookieFileLocation))
        exit("FATAL ERROR: cookie file '".$this->_cookieFileLocation."' exists and is NOT writeable\n");
    else
      if ( ! is_writeable(dirname($this->_cookieFileLocation)))
        exit("FATAL ERROR: cookie file FOLDER '".dirname($this->_cookieFileLocation)."' is NOT writeable\n");
  }

  public function __destruct() {
    if ($this->_debugFile !== false)
      fclose($this->_debugFile);
  }

  public function setClientCert($certFile) {
    $this->_certFile = $certFile;
  }

  public function setInterface($interface) {
    $this->_interface = $interface;
  }

  public function setDebugFile($file) {
    if (is_writeable((file_exists($file) ? $file : dirname($file))))
      $this->_debugFile = fopen($file, 'a+');
    else
      exit("FATAL ERROR: debug file '".$file."' is NOT writeable\n");
  }

  public function setMaxRedirects($maxRedirects) {
    $this->_maxRedirects = (int)$maxRedirects;
  }

  public function setTimeout($timeout) {
    $this->_timeout = (int)$timeout;
  }

  public function setReferer($referer) {
    $this->_referer = $referer;
  }

  public function setCookieFileLocation($path) {
    $this->_cookieFileLocation = $path;
  }

  public function getCookieFileLocation() {
    return $this->_cookieFileLocation;
  }

  public function setBinaryTransfer($binaryTransfer) {
    $this->_binaryTransfer = $binaryTransfer ? true : false;
  }

  public function setFollowLocation($followLocation) {
    $this->_post = $followLocation ? true : false;
  }

  public function setPost($post) {
    $this->_post = $post ? true : false;
  }

  public function setUrl($url) {
    $this->_url = $url;
  }

  public function setPort($port) {
    $this->_port = $port;
  }

  public function setUserAgent($userAgent) {
    $this->_useragent = $userAgent;
  }

  public function setHeaders($headers) {
    $this->_postHeaders = array_merge($this->_postHeaders, (array)$headers);
  }

  public function query($postFields = null) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $this->_url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $this->_postHeaders);
    curl_setopt($ch, CURLOPT_TIMEOUT, $this->_timeout);
    curl_setopt($ch, CURLOPT_MAXREDIRS, $this->_maxRedirects);
    if ( ! ini_get('safe_mode') && ! ini_get('open_basedir'))
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $this->_followLocation);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $this->_cookieFileLocation);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $this->_cookieFileLocation);
    curl_setopt($ch, CURLOPT_USERAGENT, $this->_useragent); 
    curl_setopt($ch, CURLOPT_POST, $this->_post);

    if ($postFields != null)
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

    if ( ! empty($this->_port))
      curl_setopt($ch, CURLOPT_PORT, $this->_port);

    if ( ! empty($this->_interface))
      curl_setopt($ch, CURLOPT_INTERFACE, $this->_interface);

    if ( ! empty($this->_referer))
      curl_setopt($ch, CURLOPT_REFERER, $this->_referer);

    if ($this->_binaryTransfer)
      curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);

    if ( ! empty($this->_authName))
      curl_setopt($ch, CURLOPT_USERPWD, $this->_authName.':'.$this->_authPass);

    if ( ! empty($this->_certFile)) {
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_SSLCERT, $this->_certFile);
    }

    if ($this->_debugFile !== false) {
      curl_setopt($ch, CURLINFO_HEADER_OUT, 1);
      curl_setopt($ch, CURLOPT_VERBOSE, true);
      curl_setopt($ch, CURLOPT_STDERR, $this->_debugFile);
    }

    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $this->_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $this->_headers = substr($response, 0, $header_size);
    $this->_body = substr($response, $header_size);
    $this->_error = ($response === false) ? curl_error($ch) : "";
    $header_out = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    curl_close($ch);

    // write debug information
    if ($this->_debugFile)
      fwrite($this->_debugFile,
        __FILE__ . " @ " . __LINE__ . " -- " . date("c") . "\n" .
        "==== START OUTPUT ====\n" .
        $header_out . $postFields .
        "==== END OUTPUT ====\n" .
        "==== START INPUT ====\n" .
        $response .
        "==== END INPUT ====\n\n");

    return $this->_body;
  }

  public function getHttpStatus() {
    return $this->_status;
  }

  public function getHttpHeaders() {
    return $this->_headers;
  }

  public function getHttpBody() {
    return $this->_body;
  }

  public function getHttpError() {
    return $this->_error;
  }

  public function __tostring() {
    return $this->_body;
  }
}

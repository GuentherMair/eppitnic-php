<?php

/**
 * This file is part of the WSDL interface to the EPP library.
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
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: account.poll.php 463 2017-02-07 18:55:25Z gunny $
 */

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'Poll',
  // INPUT
  array(
    'msgID'             => 'xsd:int',
    'store'             => 'xsd:string',
    'type'              => 'xsd:string',
  ),
  // OUTPUT
  array(
    'status'            => 'xsd:int',
    'statusDescription' => 'xsd:string',
    'msgID'             => 'xsd:int',
    'msgTitle'          => 'xsd:string',
    'xmlResponse'       => 'xsd:string',
  ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#Poll',
  // STYLE (rpc)
  $wsdl_style,
  // USE (encoded)
  $wsdl_use,
  // DOCUMENTATION
  $wsdl_documentation
);       

/*
 * now implement the SOAP method in PHP
 */
function Poll($msgID = null,
              $store = "true",
              $type = "ack") {

  // prepare parameters
  $store = (strtolower($store) == "false") ? FALSE : TRUE;
  $type = (strtolower($type) == "ack") ? "ack" : "req";

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect
  if ($c->connect()) {
    // retrieve queue length
    if ($c->session->pollMessageCount() == 0) {
      $c->statusCode = 3001;
    } else {
      // check msgID
      if (empty($msgID))
        $msgID = $c->session->pollID();

      // poll queue
      if ($c->session->poll($store, $type, $msgID))
        $msgTitle = $c->session->msgTitle;
      else
        $c->createErrMsg($c->session, 2000);

      // for the moment deliver the XML response string
      $xmlResponse = base64_encode($c->session->result['body']);
    }
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array(
    'status'            => $c->statusCode,
    'statusDescription' => $c->statusDescription(),
    'msgID'             => $msgID,
    'msgTitle'          => $msgTitle,
    'xmlResponse'       => $xmlResponse,
  );
}

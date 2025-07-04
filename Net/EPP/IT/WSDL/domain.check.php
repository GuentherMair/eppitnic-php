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
 * $Id: domain.check.php 463 2017-02-07 18:55:25Z gunny $
 */

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainCheck',
  // INPUT
  array(
    'domain1'  => 'xsd:string',
    'domain2'  => 'xsd:string',
    'domain3'  => 'xsd:string',
    'domain4'  => 'xsd:string',
    'domain5'  => 'xsd:string',
  ),
  // OUTPUT
  array(
    'status'            => 'xsd:int',
    'statusDescription' => 'xsd:string',
    'DomainCheckArray'  => 'tns:DomainCheckArray',
  ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainCheck',
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
function DomainCheck($domain1,
                     $domain2,
                     $domain3,
                     $domain4,
                     $domain5) {

  // create object
  $c = new Net_EPP_IT_WSDL();
  $DomainCheckArray = array();
  $domains = array();
  $states = array();

  // verify that we have at least one domain
  if (empty($domain1))
    $c->statusCode = 2003;

  // connect and check domains
  if (($c->statusCode == 1000) && $c->connect()) {
    $domains[] = $domain1;
    $states[] = $c->domain->check($domain1) ? "available" : "unavailable";
    if ( ! empty($domain2)) {
      $domains[] = $domain2;
      $states[] = $c->domain->check($domain2) ? "available" : "unavailable";
    }
    if ( ! empty($domain3)) {
      $domains[] = $domain3;
      $states[] = $c->contact->check($domain3) ? "available" : "unavailable";
    }
    if ( ! empty($domain4)) {
      $domains[] = $domain4;
      $states[] = $c->contact->check($domain4) ? "available" : "unavailable";
    }
    if ( ! empty($domain5)) {
      $domains[] = $domain5;
      $states[] = $c->contact->check($domain5) ? "available" : "unavailable";
    }
  }

  $DomainCheckArray = array('domain' => $domains, 'status' => $states);

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array(
    'status'            => $c->statusCode,
    'statusDescription' => $c->statusDescription(),
    'DomainCheckArray'  => $DomainCheckArray,
  );
}

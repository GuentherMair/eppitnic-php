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
 * $Id: contact.info.php 463 2017-02-07 18:55:25Z gunny $
 */

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'ContactInfo',
  // INPUT
  array(
    'handle'               => 'xsd:string'
  ),
  // OUTPUT
  array(
    'status'               => 'xsd:int',
    'statusDescription'    => 'xsd:string',
    'handle'               => 'xsd:string',
    'name'                 => 'xsd:string',
    'org'                  => 'xsd:string',
    'street'               => 'xsd:string',
    'street2'              => 'xsd:string',
    'street3'              => 'xsd:string',
    'city'                 => 'xsd:string',
    'cc'                   => 'xsd:string',
    'sp'                   => 'xsd:string',
    'pc'                   => 'xsd:string',
    'entityType'           => 'xsd:int',
    'regCode'              => 'xsd:string',
    'voice'                => 'xsd:string',
    'fax'                  => 'xsd:string',
    'email'                => 'xsd:string',
    'nationalityCode'      => 'xsd:string',
    'authInfo'             => 'xsd:string',
    'consentForPublishing' => 'xsd:string',
  ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#ContactInfo',
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
function ContactInfo($handle) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and get data
  if ($c->connect())
    if ( ! $c->contact->fetch($handle))
      $c->createErrMsg($c->contact, 4001);

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array(
    'status'               => $c->statusCode,
    'statusDescription'    => $c->statusDescription(),
    'handle'               => $c->contact->get('handle'),
    'name'                 => $c->contact->get('name'),
    'org'                  => $c->contact->get('org'),
    'street'               => $c->contact->get('street'),
    'street2'              => $c->contact->get('street2'),
    'street3'              => $c->contact->get('street3'),
    'city'                 => $c->contact->get('city'),
    'cc'                   => $c->contact->get('countrycode'),
    'sp'                   => $c->contact->get('province'),
    'pc'                   => $c->contact->get('postalcode'),
    'entityType'           => $c->contact->get('entityType'),
    'regCode'              => $c->contact->get('regCode'),
    'voice'                => $c->contact->get('voice'),
    'fax'                  => $c->contact->get('fax'),
    'email'                => $c->contact->get('email'),
    'nationalityCode'      => $c->contact->get('nationalityCode'),
    'authInfo'             => $c->contact->get('authInfo'),
    'consentForPublishing' => (($c->contact->get('consentForPublishing') == 1) ? "true" : "false"),
  );
}

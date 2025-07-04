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
 * $Id: domain.info.php 463 2017-02-07 18:55:25Z gunny $
 */

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainInfo',
  // INPUT
  array(
    'domain'            => 'xsd:string'
  ),
  // OUTPUT
  array(
    'status'            => 'xsd:int',
    'statusDescription' => 'xsd:string',
    'domain'            => 'xsd:string',
    'authInfo'          => 'xsd:string',
    'registrant'        => 'xsd:string',
    'admin'             => 'xsd:string',
    'tech1'             => 'xsd:string',
    'tech2'             => 'xsd:string',
    'tech3'             => 'xsd:string',
    'tech4'             => 'xsd:string',
    'tech5'             => 'xsd:string',
    'tech6'             => 'xsd:string',
    'ns1'               => 'xsd:string',
    'ns1ip'             => 'xsd:string',
    'ns2'               => 'xsd:string',
    'ns2ip'             => 'xsd:string',
    'ns3'               => 'xsd:string',
    'ns3ip'             => 'xsd:string',
    'ns4'               => 'xsd:string',
    'ns4ip'             => 'xsd:string',
    'ns5'               => 'xsd:string',
    'ns5ip'             => 'xsd:string',
    'ns6'               => 'xsd:string',
    'ns6ip'             => 'xsd:string',
  ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainInfo',
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
function DomainInfo($domain) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and get data
  if ($c->connect())
    if ( ! $c->domain->fetch($domain))
      $c->createErrMsg($c->domain, 4001);

  // disconnect
  $c->disconnect();

  // build tech strings
  $tech = $c->domain->get('tech');
  $i = 1;
  if (is_array($tech)) {
    foreach ($tech as $key => $value) {
      $name = 'tech'.$i++;
      $$name = $key;
    }
  } else {
    $tech1 = $tech;
    $i = 2;
  }
  for (; $i <= 6; $i++) {
    $name = 'tech'.$i;
    $$name = '';
  }

  // build ns strings
  $ns = $c->domain->get('ns');
  $i = 1;
  foreach ($ns as $single_ns) {
    $name = 'ns'.$i;
    $nameIP = 'ns'.$i++.'ip';
    $$name = $single_ns['name'];
    if (isset($single_ns['ip'][0]['address']))
      $$nameIP = $single_ns['ip'][0]['address'];
    else
      $$nameIP = '';
  }
  for (; $i <= 6; $i++) {
    $name = 'ns'.$i;
    $nameIP = 'ns'.$i.'ip';
    $$name = '';
    $$nameIP = '';
  }

  // return values as defined by the SOAP interface description above
  return array(
    'status'            => $c->statusCode,
    'statusDescription' => $c->statusDescription(),
    'domain'            => $c->domain->get('domain'),
    'authInfo'          => $c->domain->get('authinfo'),
    'registrant'        => $c->domain->get('registrant'),
    'admin'             => $c->domain->get('admin'),
    'tech1'             => $tech1,
    'tech2'             => $tech2,
    'tech3'             => $tech3,
    'tech4'             => $tech4,
    'tech5'             => $tech5,
    'tech6'             => $tech6,
    'ns1'               => $ns1,
    'ns1ip'             => $ns1ip,
    'ns2'               => $ns2,
    'ns2ip'             => $ns2ip,
    'ns3'               => $ns3,
    'ns3ip'             => $ns3ip,
    'ns4'               => $ns4,
    'ns4ip'             => $ns4ip,
    'ns5'               => $ns5,
    'ns5ip'             => $ns5ip,
    'ns6'               => $ns6,
    'ns6ip'             => $ns6ip,
  );
}

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
 * $Id$
 */

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainUpdate',
  // INPUT
  array(
    'domain'            => 'xsd:string',
    'admin'             => 'xsd:string',
    'authInfo'          => 'xsd:string',
    'addtech'           => 'xsd:string', // separate by semicolon
    'remtech'           => 'xsd:string', // separate by semicolon
    'addns'             => 'xsd:string', // separate by semicolon
    'addnsIP'           => 'xsd:string', // separate by semicolon
    'remns'             => 'xsd:string', // separate by semicolon
  ),
  // OUTPUT
  array(
    'status'            => 'xsd:int',
    'statusDescription' => 'xsd:string',
    'domain'            => 'xsd:string',
    'authInfo'          => 'xsd:string',
  ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainUpdate',
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
function DomainUpdate($domain,
                      $admin = "",
                      $authInfo = "",
                      $addtech = "",
                      $remtech = "",
                      $addns = "",
                      $addnsIP = "",
                      $remns = "") {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // check if domain was set
  if (empty($domain))
    $c->statusCode = 2003;

  // connect
  if ($c->statusCode == 1000)
    $c->connect();

  // check domain
  if (($c->statusCode == 1000) && $c->domain->check($domain))
    $c->statusCode = 4002;

  // get domain
  if (($c->statusCode == 1000) && ! $c->domain->fetch($domain))
    $c->statusCode = 4003;

  // update domain
  if ($c->statusCode == 1000) {
    if ( ! empty($domain))     $c->domain->set('domain',     $domain);
    if ( ! empty($admin))      $c->domain->set('admin',      $admin);
    if ( ! empty($authInfo))   $c->domain->set('authinfo',   $authInfo);

    $tech_list = explode(";", $addtech);
    foreach ($tech_list as $tech)
      $c->domain->addTECH($tech);
    $tech_list = explode(";", $remtech);
    foreach ($tech_list as $tech)
      $c->domain->remTECH($tech);

    $ns_list = explode(";", $remns);
    foreach ($ns_list as $ns)
      $c->domain->remNS($ns);
    $ns_list = explode(";", $addns);
    $ip_list = explode(";", $addnsIP);
    for ($i = 0; $i < count($ns_list); $i++)
      $c->domain->addNS($ns_list[$i], $ip_list[$i]);

    if ( ! $c->domain->update())
      $c->createErrMsg($c->domain, 4001);
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array(
    'status'            => $c->statusCode,
    'statusDescription' => $c->statusDescription(),
    'domain'            => $domain,
    'authInfo'          => $authInfo,
  );
}

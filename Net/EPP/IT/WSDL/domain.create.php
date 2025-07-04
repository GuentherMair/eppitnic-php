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
 * $Id: domain.create.php 463 2017-02-07 18:55:25Z gunny $
 */

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainCreate',
  // INPUT
  array('domain'            => 'xsd:string',
        'registrant'        => 'xsd:string',
        'admin'             => 'xsd:string',
        'tech1'             => 'xsd:string',
        'ns1'               => 'xsd:string',
        'ns2'               => 'xsd:string',
        'authInfo'          => 'xsd:string',
        'tech2'             => 'xsd:string',
        'tech3'             => 'xsd:string',
        'tech4'             => 'xsd:string',
        'tech5'             => 'xsd:string',
        'tech6'             => 'xsd:string',
        'ns1ip'             => 'xsd:string',
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
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'domain'            => 'xsd:string',
        'authInfo'          => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainCreate',
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
function DomainCreate($domain,
                      $registrant,
                      $admin,
                      $tech1,
                      $ns1,
                      $ns2,
                      $authInfo = "",
                      $tech2 = "",
                      $tech3 = "",
                      $tech4 = "",
                      $tech5 = "",
                      $tech6 = "",
                      $ns1ip = "",
                      $ns2ip = "",
                      $ns3 = "",
                      $ns3ip = "",
                      $ns4 = "",
                      $ns4ip = "",
                      $ns5 = "",
                      $ns5ip = "",
                      $ns6 = "",
                      $ns6ip = "") {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // check for empty mandatory fields
  if ( empty($domain) ||
       empty($registrant) ||
       empty($admin) ||
       empty($tech1) ||
       empty($ns1) ||
       empty($ns2) )
    $c->statusCode = 2003;

  // connect
  if ( $c->statusCode == 1000 )
    $c->connect();

  // check domain
  if ( ($c->statusCode == 1000) && ! $c->domain->check($domain) )
    $c->statusCode = 4002;

  // create domain
  if ( $c->statusCode == 1000 ) {

    // if necessary generate a random authInfo key
    if ( empty($authInfo) ) $authInfo = md5(rand());

    // set mandatory fields
    $c->domain->set('domain',     $domain);
    $c->domain->set('registrant', $registrant);
    $c->domain->set('admin',      $admin);
    $c->domain->set('tech',       $tech1);
    $c->domain->addNS($ns1,       $ns1ip);
    $c->domain->addNS($ns2,       $ns2ip);
    $c->domain->set('authinfo',   $authInfo);

    // set optional fields
    if ( !empty($tech2) ) $c->domain->set('tech',  $tech2);
    if ( !empty($tech3) ) $c->domain->set('tech',  $tech3);
    if ( !empty($tech4) ) $c->domain->set('tech',  $tech4);
    if ( !empty($tech5) ) $c->domain->set('tech',  $tech5);
    if ( !empty($tech6) ) $c->domain->set('tech',  $tech6);
    if ( !empty($ns3) )   $c->domain->addNS($ns3,  $ns3ip);
    if ( !empty($ns4) )   $c->domain->addNS($ns4,  $ns4ip);
    if ( !empty($ns5) )   $c->domain->addNS($ns5,  $ns5ip);
    if ( !empty($ns6) )   $c->domain->addNS($ns6,  $ns6ip);

    if ( ! $c->domain->create() )
      $c->createErrMsg($c->domain, 4001);
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'domain'            => $domain,
               'authInfo'          => $authInfo,
               );
}


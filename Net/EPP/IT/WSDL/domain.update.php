<?php

/**
 * This file is part of the WSDL interface to the EPP library.
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
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: domain.update.php 162 2010-10-18 00:27:43Z gunny $
 */

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainUpdate',
  // INPUT
  array('domain'            => 'xsd:string',
        'registrant'        => 'xsd:string',
        'admin'             => 'xsd:string',
        'authInfo'          => 'xsd:string',
        'addtech1'          => 'xsd:string',
        'addtech2'          => 'xsd:string',
        'remtech1'          => 'xsd:string',
        'remtech2'          => 'xsd:string',
        'addns1'            => 'xsd:string',
        'addns1ip'          => 'xsd:string',
        'addns2'            => 'xsd:string',
        'addns2ip'          => 'xsd:string',
        'remns1'            => 'xsd:string',
        'remns2'            => 'xsd:string',
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
                      $registrant = "",
                      $admin = "",
                      $authInfo = "",
                      $addtech1 = "",
                      $addtech2 = "",
                      $remtech1 = "",
                      $remtech2 = "",
                      $addns1 = "",
                      $addns1ip = "",
                      $addns2 = "",
                      $addns2ip = "",
                      $remns1 = "",
                      $remns2 = "") {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // check if domain was set
  if ( empty($domain) )
    $c->statusCode = 2003;

  // connect
  if ( $c->statusCode == 1000 )
    $c->connect();

  // check domain
  if ( ($c->statusCode == 1000) && $c->domain->check($domain) )
    $c->statusCode = 4002;

  // get domain
  if ( ($c->statusCode == 1000) && ! $c->domain->fetch($domain) )
    $c->statusCode = 4003;

  // update domain
  if ( $c->statusCode == 1000 ) {

    if ( !empty($domain) )     $c->domain->set('domain',     $domain);
    if ( !empty($registrant) ) $c->domain->set('registrant', $registrant);
    if ( !empty($admin) )      $c->domain->set('admin',      $admin);
    if ( !empty($authInfo) )   $c->domain->set('authinfo',   $authInfo);

    if ( !empty($addtech1) )   $c->domain->addTECH($addtech1);
    if ( !empty($addtech2) )   $c->domain->addTECH($addtech2);
    if ( !empty($remtech1) )   $c->domain->remTECH($remtech1);
    if ( !empty($remtech2) )   $c->domain->remTECH($remtech2);

    if ( !empty($addns1) )     $c->domain->addNS($addns1, $addns1ip);
    if ( !empty($addns2) )     $c->domain->addNS($addns2, $addns2ip);
    if ( !empty($remns1) )     $c->domain->remNS($remns1);
    if ( !empty($remns2) )     $c->domain->remNS($remns2);

    if ( ! $c->domain->update() )
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


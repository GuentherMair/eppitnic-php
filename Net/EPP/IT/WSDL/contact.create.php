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
 * $Id: contact.create.php 463 2017-02-07 18:55:25Z gunny $
 */

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'ContactCreate',
  // INPUT
  array(
    'handle'               => 'xsd:string',
    'name'                 => 'xsd:string',
    'street'               => 'xsd:string',
    'city'                 => 'xsd:string',
    'cc'                   => 'xsd:string',
    'sp'                   => 'xsd:string',
    'pc'                   => 'xsd:string',
    'regCode'              => 'xsd:string',
    'voice'                => 'xsd:string',
    'email'                => 'xsd:string',
    'nationalityCode'      => 'xsd:string',
    'authInfo'             => 'xsd:string',
    'entityType'           => 'xsd:int',
    'consentForPublishing' => 'xsd:string',
    'org'                  => 'xsd:string',
    'street2'              => 'xsd:string',
    'street3'              => 'xsd:string',
    'fax'                  => 'xsd:string',
  ),
  // OUTPUT
  array(
    'status'            => 'xsd:int',
    'statusDescription' => 'xsd:string',
    'handle'            => 'xsd:string',
  ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#ContactCreate',
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
function ContactCreate($handle,
                       $name,
                       $street,
                       $city,
                       $cc,
                       $sp,
                       $pc,
                       $regCode,
                       $voice,
                       $email,
                       $nationalityCode,
                       $authInfo = "",
                       $entityType = 2,
                       $consentForPublishing = "false",
                       $org = "",
                       $street2 = "",
                       $street3 = "",
                       $fax = "") {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // check for empty mandatory fields
  if (empty($handle) ||
      empty($name) ||
      empty($street) ||
      empty($city) ||
      empty($cc) ||
      empty($sp) ||
      empty($pc) ||
      empty($regCode) ||
      empty($voice) ||
      empty($email) ||
      empty($nationalityCode))
    $c->statusCode = 2003;

  // connect
  if ($c->statusCode == 1000)
    $c->connect();

  // check contact
  if (($c->statusCode == 1000) && ! $c->contact->check($handle))
    $c->statusCode = 4002;

  // create contact
  if ($c->statusCode == 1000) {

    // set mandatory fields
    $c->contact->set('handle',          $handle);
    $c->contact->set('name',            $name);
    $c->contact->set('street',          $street);
    $c->contact->set('city',            $city);
    $c->contact->set('province',        $sp);
    $c->contact->set('postalcode',      $pc);
    $c->contact->set('countrycode',     $cc);
    $c->contact->set('voice',           $voice);
    $c->contact->set('email',           $email);
    $c->contact->set('nationalitycode', $nationalityCode);
    $c->contact->set('regcode',         $regCode);

    // set default values
    $c->contact->set('authinfo',        (empty($authInfo) ? md5(rand()) : $authInfo));
    $c->contact->set('org',             (empty($org) ? $name : $org));
    $c->contact->set('entitytype',      $entityType);
    if (strtolower($consentForPublishing) == "true")
      $c->contact->setConsent();
    else
      $c->contact->unsetConsent();

    // set optional fields
    if ( ! empty($street2)) $c->contact->set('street2', $street2);
    if ( ! empty($street3)) $c->contact->set('street3', $street3);
    if ( ! empty($fax))     $c->contact->set('fax',     $fax);

    if ( ! $c->contact->create())
      $c->createErrMsg($c->contact, 4001);
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array(
    'status'            => $c->statusCode,
    'statusDescription' => $c->statusDescription(),
    'handle'            => $handle,
  );
}

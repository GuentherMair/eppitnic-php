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
 * $Id: domain.info.php 176 2010-10-19 19:28:28Z gunny $
 */


//
// see http://stackoverflow.com/questions/6986350/generating-wsdl-with-nusoap-return-struct-with-various-types-int-string-arr
//

class DomainInfContactsResponseObject {
  public $status = NULL;
  public $statusDescription = NULL;
  public $domain = NULL;
  public $authInfo = NULL;
  public $registrant = NULL;
  public $admin = NULL;
  public $tech1 = NULL;
  public $tech2 = NULL;
  public $tech3 = NULL;
  public $tech4 = NULL;
  public $tech5 = NULL;
  public $tech6 = NULL;
  public $ns1 = NULL;
  public $ns1ip = NULL;
  public $ns2 = NULL;
  public $ns2ip = NULL;
  public $ns3 = NULL;
  public $ns3ip = NULL;
  public $ns4 = NULL;
  public $ns4ip = NULL;
  public $ns5 = NULL;
  public $ns5ip = NULL;
  public $ns6 = NULL;
  public $ns6ip = NULL;
  public $infcontacts = NULL;
}

$server->wsdl->addComplexType(
  // name
  'Contacts',
  // typeClass (complexType|simpleType|attribute)
  'complexType',
  // phpType: currently supported are array and struct (php assoc array)
  'struct',
  // compositor (all|sequence|choice)
  'all',
  // restrictionBase namespace:name (http://schemas.xmlsoap.org/soap/encoding/:Array)
  '',
  // elements = array ( name = array(name=>'',type=>'') )
  array(
    'type' => array(
      'name' => 'type',
      'type' => 'xsd:string'
    ),
    'id' => array(
      'name' => 'id',
      'type' => 'xsd:string'
    ),
    'name' => array(
      'name' => 'name',
      'type' => 'xsd:string'
    ),
    'org' => array(
      'name' => 'org',
      'type' => 'xsd:string'
    ),
    'street' => array(
      'name' => 'street',
      'type' => 'xsd:string'
    ),
    'street2' => array(
      'name' => 'street2',
      'type' => 'xsd:string'
    ),
    'street3' => array(
      'name' => 'street3',
      'type' => 'xsd:string'
    ),
    'city' => array(
      'name' => 'city',
      'type' => 'xsd:string'
    ),
    'province' => array(
      'name' => 'province',
      'type' => 'xsd:string'
    ),
    'postalcode' => array(
      'name' => '',
      'type' => 'xsd:string'
    ),
    'countrycode' => array(
      'name' => 'countrycode',
      'type' => 'xsd:string'
    ),
    'voice' => array(
      'name' => 'voice',
      'type' => 'xsd:string'
    ),
    'fax' => array(
      'name' => 'fax',
      'type' => 'xsd:string'
    ),
    'email' => array(
      'name' => 'email',
      'type' => 'xsd:string'
    ),
    'consentforpublishing' => array(
      'name' => 'consentforpublishing',
      'type' => 'xsd:string'
    ),
    'nationalitycode' => array(
      'name' => 'nationalitycode',
      'type' => 'xsd:string'
    ),
    'entitytype' => array(
      'name' => 'entitytype',
      'type' => 'xsd:string'
    ),
    'regcode' => array(
      'name' => 'regcode',
      'type' => 'xsd:string'
    ),
  )
);

$server->wsdl->addComplexType(
  // name
  'ContactsArray',
  // typeClass (complexType|simpleType|attribute)
  'complexType',
  // phpType: currently supported are array and struct (php assoc array)
  'array',
  // compositor (all|sequence|choice)
  '',
  // restrictionBase namespace:name (http://schemas.xmlsoap.org/soap/encoding/:Array)
  'SOAP-ENC:Array',
  // elements = array ( name = array(name=>'',type=>'') )
  array(),
  // attrs
  array(
    array(
      'ref' => 'SOAP-ENC:arrayType',
      'wsdl:arrayType' => 'tns:Contacts[]'
    )
  ),
  // arrayType: namespace:name (http://www.w3.org/2001/XMLSchema:string)
  'tns:Contacts'
);

$server->wsdl->addComplexType(
  // name
  'DomainInfContactsResponseObject',
  // typeClass (complexType|simpleType|attribute)
  'complexType',
  // phpType: currently supported are array and struct (php assoc array)
  'struct',
  // compositor (all|sequence|choice)
  'all',
  // restrictionBase namespace:name (http://schemas.xmlsoap.org/soap/encoding/:Array)
  '',
  // elements = array ( name = array(name=>'',type=>'') )
  array(
    'status'            => array('type' => 'xsd:int'),
    'statusDescription' => array('type' => 'xsd:string'),
    'domain'            => array('type' => 'xsd:string'),
    'authInfo'          => array('type' => 'xsd:string'),
    'registrant'        => array('type' => 'xsd:string'),
    'admin'             => array('type' => 'xsd:string'),
    'tech1'             => array('type' => 'xsd:string'),
    'tech2'             => array('type' => 'xsd:string'),
    'tech3'             => array('type' => 'xsd:string'),
    'tech4'             => array('type' => 'xsd:string'),
    'tech5'             => array('type' => 'xsd:string'),
    'tech6'             => array('type' => 'xsd:string'),
    'ns1'               => array('type' => 'xsd:string'),
    'ns1ip'             => array('type' => 'xsd:string'),
    'ns2'               => array('type' => 'xsd:string'),
    'ns2ip'             => array('type' => 'xsd:string'),
    'ns3'               => array('type' => 'xsd:string'),
    'ns3ip'             => array('type' => 'xsd:string'),
    'ns4'               => array('type' => 'xsd:string'),
    'ns4ip'             => array('type' => 'xsd:string'),
    'ns5'               => array('type' => 'xsd:string'),
    'ns5ip'             => array('type' => 'xsd:string'),
    'ns6'               => array('type' => 'xsd:string'),
    'ns6ip'             => array('type' => 'xsd:string'),
    'infcontacts'       => array('type' => 'tns:ContactsArray')
    // DON'T UNCOMMENT THE FOLLOWING COMMENTED LINES, BECAUSE THIS WAY IT DOESN'T WORK!!! - Left it in the code not to forget it....
    // 'minOccurs' => '0',
    // 'maxOccurs' => 'unbounded'
  )
);


/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainInfContacts',
  // INPUT
  array('domain'            => 'xsd:string',
        'authInfo'          => 'xsd:string',
        'type'              => 'xsd:string',
        ),
  // OUTPUT
  array('return'            => 'tns:DomainInfContactsResponseObject'),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainInfContacts',
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
function DomainInfContacts($domain, $authInfo, $type) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and get data
  if ( $c->connect() )
    if ( ! $c->domain->fetch($domain, $authInfo, $type) )
      $c->createErrMsg($c->domain, 4001);

  // disconnect
  $c->disconnect();

  // build tech strings
  $tech = $c->domain->get('tech');
  $i = 1;
  if ( is_array($tech) ) {
    foreach ( $tech as $key => $value ) {
      $name = 'tech'.$i++;
      $$name = $key;
    }
  } else {
    $tech1 = $tech;
    $i = 2;
  }
  for ( ; $i <= 6; $i++ ) {
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
    if ( isset($single_ns['ip'][0]['address']) )
      $$nameIP = $single_ns['ip'][0]['address'];
    else
      $$nameIP = '';
  }
  for ( ; $i <= 6; $i++ ) {
    $name = 'ns'.$i;
    $nameIP = 'ns'.$i.'ip';
    $$name = '';
    $$nameIP = '';
  }

  // TODO: build infContacts Object/Struct
  $infContactsObj = new DomainInfContactsResponseObject();
  $infContactsObj->status = $c->statusCode;
  $infContactsObj->statusDescription = $c->statusDescription();
  $infContactsObj->domain = $c->domain->get('domain');
  $infContactsObj->authInfo = $c->domain->get('authinfo');
  $infContactsObj->registrant = $c->domain->get('registrant');
  $infContactsObj->admin = $c->domain->get('admin');
  $infContactsObj->tech1 = $tech1;
  $infContactsObj->tech2 = $tech2;
  $infContactsObj->tech3 = $tech3;
  $infContactsObj->tech4 = $tech4;
  $infContactsObj->tech5 = $tech5;
  $infContactsObj->tech6 = $tech6;
  $infContactsObj->ns1 = $ns1;
  $infContactsObj->ns1ip = $ns1ip;
  $infContactsObj->ns2 = $ns2;
  $infContactsObj->ns2ip = $ns2ip;
  $infContactsObj->ns3 = $ns3;
  $infContactsObj->ns3ip = $ns3ip;
  $infContactsObj->ns4 = $ns4;
  $infContactsObj->ns4ip = $ns4ip;
  $infContactsObj->ns5 = $ns5;
  $infContactsObj->ns5ip = $ns5ip;
  $infContactsObj->ns6 = $ns6;
  $infContactsObj->ns6ip = $ns6ip;
  $infcontacts = $c->domain->get('infcontacts');
  foreach ($infcontacts as $infcontact)
    $infContactsObj->infcontacts[] = $infcontact;

  //file_put_contents('./infcontacts.txt', "START\n");
  //file_put_contents('./infcontacts.txt', print_r($infContactsObj, TRUE), FILE_APPEND);

  // return values as defined by the SOAP interface description above
  return $infContactsObj;
}


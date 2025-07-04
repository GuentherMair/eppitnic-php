<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'ContactUpdate',
  // INPUT
  array('handle'               => 'xsd:string',
        'name '                => 'xsd:string',
        'street'               => 'xsd:string',
        'city'                 => 'xsd:string',
        'cc'                   => 'xsd:string',
        'sp'                   => 'xsd:string',
        'pc'                   => 'xsd:string',
        'entityType'           => 'xsd:int',
        'regCode'              => 'xsd:string',
        'voice'                => 'xsd:string',
        'email'                => 'xsd:string',
        'nationalityCode'      => 'xsd:string',
        'authInfo'             => 'xsd:string',
        'consentForPublishing' => 'xsd:string',
        'org'                  => 'xsd:string',
        'street2'              => 'xsd:string',
        'street3'              => 'xsd:string',
        'fax'                  => 'xsd:string',
        ),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'handle'            => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#ContactUpdate',
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
function ContactUpdate($handle,
                       $name = "",
                       $street = "",
                       $city = "",
                       $cc = "",
                       $sp = "",
                       $pc = "",
                       $entityType = "",
                       $regCode = "",
                       $voice = "",
                       $email = "",
                       $nationalityCode = "",
                       $authInfo = "",
                       $consentForPublishing = "",
                       $org = "",
                       $street2 = "",
                       $street3 = "",
                       $fax = "") {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // check if handle was set
  if ( empty($handle) )
    $c->statusCode = 2003;

  // connect
  if ( $c->statusCode == 1000 )
    $c->connect();

  // check contact
  if ( ($c->statusCode == 1000) && $c->contact->check($handle) )
    $c->statusCode = 4002;

  // get contact
  if ( ($c->statusCode == 1000) && ! $c->contact->fetch($handle) )
    $c->statusCode = 4003;

  // update contact
  if ( $c->statusCode == 1000 ) {

    // set mandatory fields
    if ( !empty($name) )                 $c->contact->set('name',                 $name);
    if ( !empty($org) )                  $c->contact->set('org',                  $org);
    if ( !empty($street) )               $c->contact->set('street',               $street);
    if ( !empty($street2) )              $c->contact->set('street2',              $street2);
    if ( !empty($street3) )              $c->contact->set('street3',              $street3);
    if ( !empty($city) )                 $c->contact->set('city',                 $city);
    if ( !empty($sp) )                   $c->contact->set('province',             $sp);
    if ( !empty($pc) )                   $c->contact->set('postalcode',           $pc);
    if ( !empty($cc) )                   $c->contact->set('countrycode',          $cc);
    if ( !empty($voice) )                $c->contact->set('voice',                $voice);
    if ( !empty($email) )                $c->contact->set('email',                $email);
    if ( !empty($nationalityCode) )      $c->contact->set('nationalitycode',      $nationalityCode);
    if ( !empty($regCode) )              $c->contact->set('regcode',              $regCode);
    if ( !empty($consentForPublishing) ) $c->contact->set('consentforpublishing', $consentForPublishing);
    if ( !empty($entityType) )           $c->contact->set('entitytype',           $entityType);
    if ( !empty($authInfo) )             $c->contact->set('authinfo',             $authInfo);
    if ( !empty($fax) )                  $c->contact->set('fax',                  $fax);

    if ( ! $c->contact->update() )
      $c->createErrMsg($c->contact, 4001);
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'handle'            => $handle,
               );
}


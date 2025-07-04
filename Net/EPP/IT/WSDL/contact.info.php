<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'ContactInfo',
  // INPUT
  array('handle'               => 'xsd:string'),
  // OUTPUT
  array('status'               => 'xsd:int',
        'statusDescription'    => 'xsd:string',
        'handle'               => 'xsd:string',
        'name '                => 'xsd:string',
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
  if ( $c->connect() )
    if ( ! $c->contact->fetch($handle) )
      $c->createErrMsg($c->contact, 4001);

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'               => $c->statusCode,
               'statusDescription'    => $c->statusDescription(),
               'handle'               => $c->contact->get('handle'),
               'name '                => $c->contact->get('name'),
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
               'consentForPublishing' => ( ($c->contact->get('consentForPublishing') == 1) ? "true" : "false" ),
               );
}


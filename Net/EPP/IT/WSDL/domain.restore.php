<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainRestore',
  // INPUT
  array('domain'            => 'xsd:string'),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'domain'            => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainRestore',
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
function DomainRestore($domain) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and restore domain
  if ( $c->connect() )
    if ( $c->domain->restore($domain) === FALSE )
      $c->createErrMsg($c->domain, 4001);

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'domain'            => $domain,
               );
}


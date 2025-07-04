<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainCheck',
  // INPUT
  array('domain1'  => 'xsd:string',
        'domain2'  => 'xsd:string',
        'domain3'  => 'xsd:string',
        'domain4'  => 'xsd:string',
        'domain5'  => 'xsd:string',
        ),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'domain1'           => 'xsd:string',
        'statusDomain1'     => 'xsd:string',
        'domain2'           => 'xsd:string',
        'statusDomain2'     => 'xsd:string',
        'domain3'           => 'xsd:string',
        'statusDomain3'     => 'xsd:string',
        'domain4'           => 'xsd:string',
        'statusDomain4'     => 'xsd:string',
        'domain5'           => 'xsd:string',
        'statusDomain5'     => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainCheck',
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
function DomainCheck($domain1,
                     $domain2,
                     $domain3,
                     $domain4,
                     $domain5) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // verify that we have at least one domain
  if ( empty($domain1) )
    $c->statusCode = 2003;

  // connect and check domains
  if ( ($c->statusCode == 1000) && $c->connect() ) {
    $statusDomain1 = $c->contact->check($domain1) ? "available" : "unavailable";
    if ( !empty($domain2) ) $statusDomain2 = $c->contact->check($domain2) ? "available" : "unavailable";
    if ( !empty($domain3) ) $statusDomain3 = $c->contact->check($domain3) ? "available" : "unavailable";
    if ( !empty($domain4) ) $statusDomain4 = $c->contact->check($domain4) ? "available" : "unavailable";
    if ( !empty($domain5) ) $statusDomain5 = $c->contact->check($domain5) ? "available" : "unavailable";
  }

  // disconnect
  $c->disconnect();

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'domain1'           => $domain1,
               'statusDomain1'     => $statusDomain1,
               'domain2'           => $domain2,
               'statusDomain2'     => $statusDomain2,
               'domain3'           => $domain3,
               'statusDomain3'     => $statusDomain3,
               'domain4'           => $domain4,
               'statusDomain4'     => $statusDomain4,
               'domain5'           => $domain5,
               'statusDomain5'     => $statusDomain5,
               );
}


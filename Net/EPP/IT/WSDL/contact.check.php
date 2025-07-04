<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'ContactCheck',
  // INPUT
  array('handle1'           => 'xsd:string',
        'handle2'           => 'xsd:string',
        'handle3'           => 'xsd:string',
        'handle4'           => 'xsd:string',
        'handle5'           => 'xsd:string',
        ),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'handle1'           => 'xsd:string',
        'statusHandle1'     => 'xsd:string',
        'handle2'           => 'xsd:string',
        'statusHandle2'     => 'xsd:string',
        'handle3'           => 'xsd:string',
        'statusHandle3'     => 'xsd:string',
        'handle4'           => 'xsd:string',
        'statusHandle4'     => 'xsd:string',
        'handle5'           => 'xsd:string',
        'statusHandle5'     => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#ContactCheck',
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
function ContactCheck($handle1,
                      $handle2,
                      $handle3,
                      $handle4,
                      $handle5) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // verify that we have at least one handle
  if ( empty($handle1) )
    $c->statusCode = 2003;

  // connect and check contacts
  if ( ($c->statusCode == 1000) && $c->connect() ) {
    $statusHandle1 = $c->contact->check($handle1) ? "available" : "unavailable";
    if ( !empty($handle2) ) $statusHandle2 = $c->contact->check($handle2) ? "available" : "unavailable";
    if ( !empty($handle3) ) $statusHandle3 = $c->contact->check($handle3) ? "available" : "unavailable";
    if ( !empty($handle4) ) $statusHandle4 = $c->contact->check($handle4) ? "available" : "unavailable";
    if ( !empty($handle5) ) $statusHandle5 = $c->contact->check($handle5) ? "available" : "unavailable";
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'handle1'           => $handle1,
               'statusHandle1'     => $statusHandle1,
               'handle2'           => $handle2,
               'statusHandle2'     => $statusHandle2,
               'handle3'           => $handle3,
               'statusHandle3'     => $statusHandle3,
               'handle4'           => $handle4,
               'statusHandle4'     => $statusHandle4,
               'handle5'           => $handle5,
               'statusHandle5'     => $statusHandle5,
               );
}


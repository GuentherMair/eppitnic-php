<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'ContactDelete',
  // INPUT
  array('handle'            => 'xsd:string'),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'handle'            => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#ContactDelete',
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
function ContactDelete($handle) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and delete contact
  if ( $c->connect() )
    if ( $c->contact->delete($handle) === FALSE )
      $c->createErrMsg($c->contact, 4001);

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'handle'            => $handle,
               );
}


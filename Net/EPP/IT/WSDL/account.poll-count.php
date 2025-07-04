<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'PollMessageCount',
  // INPUT
  array(),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'count'             => 'xsd:int',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#PollMessageCount',
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
function PollMessageCount() {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and retrieve queue length
  if ( $c->connect() )
    $count = $c->session->pollMessageCount();
  else
    $count = "";

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'count'             => $count,
               );
}


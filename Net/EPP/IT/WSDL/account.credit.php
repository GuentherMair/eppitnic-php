<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'Credit',
  // INPUT
  array(),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'credit'            => 'xsd:float',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#Credit',
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
function Credit() {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and retrieve credit information
  if ( $c->connect() )
    $credit = $c->session->showCredit();
  else
    $credit = "";

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'credit'            => $credit,
               );
}


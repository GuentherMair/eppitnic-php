<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainTransferApprove',
  // INPUT
  array('domain'            => 'xsd:string',
        'authInfo'          => 'xsd:string',
        ),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'domain'            => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainTransferApprove',
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
function DomainTransferApprove($domain, $authInfo) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and delete domain
  if ( $c->connect() )
    if ( $c->domain->transferApprove($domain, $authInfo) === FALSE )
      $c->createErrMsg($c->domain, 4001);

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'domain'            => $domain,
               );
}


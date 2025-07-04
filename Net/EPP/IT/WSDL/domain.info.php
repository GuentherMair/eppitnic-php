<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainInfo',
  // INPUT
  array('domain'            => 'xsd:string'),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'domain'            => 'xsd:string',
        'authInfo'          => 'xsd:string',
        'registrant'        => 'xsd:string',
        'admin'             => 'xsd:string',
        'tech1'             => 'xsd:string',
        'tech2'             => 'xsd:string',
        'tech3'             => 'xsd:string',
        'tech4'             => 'xsd:string',
        'tech5'             => 'xsd:string',
        'tech6'             => 'xsd:string',
        'ns1'               => 'xsd:string',
        'ns1ip'             => 'xsd:string',
        'ns2'               => 'xsd:string',
        'ns2ip'             => 'xsd:string',
        'ns3'               => 'xsd:string',
        'ns3ip'             => 'xsd:string',
        'ns4'               => 'xsd:string',
        'ns4ip'             => 'xsd:string',
        'ns5'               => 'xsd:string',
        'ns5ip'             => 'xsd:string',
        'ns6'               => 'xsd:string',
        'ns6ip'             => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainInfo',
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
function DomainInfo($domain) {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect and get data
  if ( $c->connect() )
    if ( ! $c->domain->fetch($domain) )
      $c->createErrMsg($c->domain, 4001);

  // disconnect
  $c->disconnect();

  // build tech strings
  $tech = $c->domain->get('tech');
  if ( is_array($tech) ) {
    $i = 1;
    foreach ( $tech as $key => $value ) {
      $name = 'tech'.$i++;
      $$name = $key;
    }
  } else {
    $tech1 = $tech;
    $tech2 = "";
    $tech3 = "";
    $tech4 = "";
    $tech5 = "";
    $tech6 = "";
  }

  // build ns strings
  $ns = $c->domain->get('ns');
  $i = 1;
  foreach ($ns as $single_ns) {
    $name = 'ns'.$i;
    $nameIP = 'ns'.$i++.'ip';
    $$name = $single_ns['name'];
    $$nameIP = $single_ns['ip']['address'];
  }

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'domain'            => $c->domain->get('domain'),
               'authInfo'          => $c->domain->get('authinfo'),
               'registrant'        => $c->domain->get('registrant'),
               'admin'             => $c->domain->get('admin'),
               'tech1'             => $tech1,
               'tech2'             => $tech2,
               'tech3'             => $tech3,
               'tech4'             => $tech4,
               'tech5'             => $tech5,
               'tech6'             => $tech6,
               'ns1'               => $ns1,
               'ns1ip'             => $ns1ip,
               'ns2'               => $ns2,
               'ns2ip'             => $ns2ip,
               'ns3'               => $ns3,
               'ns3ip'             => $ns3ip,
               'ns4'               => $ns4,
               'ns4ip'             => $ns4ip,
               'ns5'               => $ns5,
               'ns5ip'             => $ns5ip,
               'ns6'               => $ns6,
               'ns6ip'             => $ns6ip,
               );
}


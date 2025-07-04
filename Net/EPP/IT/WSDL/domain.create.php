<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainCreate',
  // INPUT
  array('domain'            => 'xsd:string',
        'registrant'        => 'xsd:string',
        'admin'             => 'xsd:string',
        'tech1'             => 'xsd:string',
        'ns1'               => 'xsd:string',
        'ns2'               => 'xsd:string',
        'authInfo'          => 'xsd:string',
        'tech2'             => 'xsd:string',
        'tech3'             => 'xsd:string',
        'tech4'             => 'xsd:string',
        'tech5'             => 'xsd:string',
        'tech6'             => 'xsd:string',
        'ns1ip'             => 'xsd:string',
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
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'domain'            => 'xsd:string',
        'authInfo'          => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#DomainCreate',
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
function DomainCreate($domain,
                      $registrant,
                      $admin,
                      $tech1,
                      $ns1,
                      $ns2,
                      $authInfo = "",
                      $tech2 = "",
                      $tech3 = "",
                      $tech4 = "",
                      $tech5 = "",
                      $tech6 = "",
                      $ns1ip = "",
                      $ns2ip = "",
                      $ns3 = "",
                      $ns3ip = "",
                      $ns4 = "",
                      $ns4ip = "",
                      $ns5 = "",
                      $ns5ip = "",
                      $ns6 = "",
                      $ns6ip = "") {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // check for empty mandatory fields
  if ( empty($domain) ||
       empty($registrant) ||
       empty($admin) ||
       empty($tech1) ||
       empty($ns1) ||
       empty($ns2) )
    $c->statusCode = 2003;

  // connect
  if ( $c->statusCode == 1000 )
    $c->connect();

  // check domain
  if ( ($c->statusCode == 1000) && ! $c->domain->check($domain) )
    $c->statusCode = 4002;

  // create domain
  if ( $c->statusCode == 1000 ) {

    // if necessary generate a random authInfo key
    if ( empty($authInfo) ) $authInfo = md5(rand());

    // set mandatory fields
    $c->domain->set('domain',     $domain);
    $c->domain->set('registrant', $registrant);
    $c->domain->set('admin',      $admin);
    $c->domain->set('tech',       $tech1);
    $c->domain->addNS($ns1,       $ns1ip);
    $c->domain->addNS($ns2,       $ns2ip);
    $c->domain->set('authinfo',   $authInfo);

    // set optional fields
    if ( !empty($tech2) ) $c->domain->set('tech',  $tech2);
    if ( !empty($tech3) ) $c->domain->set('tech',  $tech3);
    if ( !empty($tech4) ) $c->domain->set('tech',  $tech4);
    if ( !empty($tech5) ) $c->domain->set('tech',  $tech5);
    if ( !empty($tech6) ) $c->domain->set('tech',  $tech6);
    if ( !empty($ns3) )   $c->domain->addNS($ns3,  $ns3ip);
    if ( !empty($ns4) )   $c->domain->addNS($ns4,  $ns4ip);
    if ( !empty($ns5) )   $c->domain->addNS($ns5,  $ns5ip);
    if ( !empty($ns6) )   $c->domain->addNS($ns6,  $ns6ip);

    if ( ! $c->domain->create() )
      $c->createErrMsg($c->domain, 4001);
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'domain'            => $domain,
               'authInfo'          => $authInfo,
               );
}


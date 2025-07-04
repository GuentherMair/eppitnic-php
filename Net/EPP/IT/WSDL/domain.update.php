<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'DomainUpdate',
  // INPUT
  array('domain'            => 'xsd:string',
        'registrant'        => 'xsd:string',
        'admin'             => 'xsd:string',
        'authInfo'          => 'xsd:string',
        'addtech1'          => 'xsd:string',
        'addtech2'          => 'xsd:string',
        'remtech1'          => 'xsd:string',
        'remtech2'          => 'xsd:string',
        'addns1'            => 'xsd:string',
        'addns1ip'          => 'xsd:string',
        'addns2'            => 'xsd:string',
        'addns2ip'          => 'xsd:string',
        'remns1'            => 'xsd:string',
        'remns2'            => 'xsd:string',
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
  'urn:'.$wsdl_ns.'#DomainUpdate',
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
function DomainUpdate($domain,
                      $registrant = "",
                      $admin = "",
                      $authInfo = "",
                      $addtech1 = "",
                      $addtech2 = "",
                      $remtech1 = "",
                      $remtech2 = "",
                      $addns1 = "",
                      $addns1ip = "",
                      $addns2 = "",
                      $addns2ip = "",
                      $remns1 = "",
                      $remns2 = "") {

  // create object
  $c = new Net_EPP_IT_WSDL();

  // check if domain was set
  if ( empty($domain) )
    $c->statusCode = 2003;

  // connect
  if ( $c->statusCode == 1000 )
    $c->connect();

  // check domain
  if ( ($c->statusCode == 1000) && $c->domain->check($domain) )
    $c->statusCode = 4002;

  // get domain
  if ( ($c->statusCode == 1000) && ! $c->domain->fetch($domain) )
    $c->statusCode = 4003;

  // update domain
  if ( $c->statusCode == 1000 ) {

    if ( !empty($domain) )     $c->domain->set('domain',     $domain);
    if ( !empty($registrant) ) $c->domain->set('registrant', $registrant);
    if ( !empty($admin) )      $c->domain->set('admin',      $admin);
    if ( !empty($authInfo) )   $c->domain->set('authinfo',   $authInfo);

    if ( !empty($addtech1) )   $c->domain->addTECH($addtech1);
    if ( !empty($addtech2) )   $c->domain->addTECH($addtech2);
    if ( !empty($remtech1) )   $c->domain->remTECH($remtech1);
    if ( !empty($remtech2) )   $c->domain->remTECH($remtech2);

    if ( !empty($addns1) )     $c->domain->addNS($addns1, $addns1ip);
    if ( !empty($addns2) )     $c->domain->addNS($addns2, $addns2ip);
    if ( !empty($remns1) )     $c->domain->remNS($remns1);
    if ( !empty($remns2) )     $c->domain->remNS($remns2);

    if ( ! $c->domain->update() )
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


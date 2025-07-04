<?php

$server->wsdl->addComplexType(
  'MessageQueueArray',
  'complexType',
  'struct',
  'sequence',
  '',
  array(
    'msgID'       => array('name' => 'msgID',       'type' => 'xsd:int',    'minOccurs' => '0', 'maxOccurs' => 'unbounded'),
    'msgTitle'    => array('name' => 'msgTitle',    'type' => 'xsd:string', 'minOccurs' => '0', 'maxOccurs' => 'unbounded'),
    'xmlResponse' => array('name' => 'xmlResponse', 'type' => 'xsd:string', 'minOccurs' => '0', 'maxOccurs' => 'unbounded'),
  )
);

$server->wsdl->addComplexType(
  'ContactCheckArray',
  'complexType',
  'struct',
  'sequence',
  '',
  array(
    'contact' => array('name' => 'contact', 'type' => 'xsd:string', 'minOccurs' => '1', 'maxOccurs' => '5'),
    'status'  => array('name' => 'status',  'type' => 'xsd:string', 'minOccurs' => '1', 'maxOccurs' => '5'),
  )
);

$server->wsdl->addComplexType(
  'DomainCheckArray',
  'complexType',
  'struct',
  'sequence',
  '',
  array(
    'domain' => array('name' => 'domain', 'type' => 'xsd:string', 'minOccurs' => '1', 'maxOccurs' => '5'),
    'status' => array('name' => 'status', 'type' => 'xsd:string', 'minOccurs' => '1', 'maxOccurs' => '5'),
  )
);


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


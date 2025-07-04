<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'Poll',
  // INPUT
  array('msgID'             => 'xsd:int',
        'store'             => 'xsd:string',
        'type'              => 'xsd:string',
        ),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'msgID'             => 'xsd:int',
        'msgTitle'          => 'xsd:string',
        'xmlResponse'       => 'xsd:string',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#Poll',
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
function Poll($msgID = null,
              $store = "true",
              $type = "req") {

  // prepare parameters
  $store = (strtolower($store) == "false") ? FALSE : TRUE;
  $type = (strtolower($type) == "ack") ? "ack" : "req";

  // create object
  $c = new Net_EPP_IT_WSDL();

  // connect
  if ( $c->connect() ) {
    // retrieve queue length
    if ( $c->session->pollMessageCount() == 0 ) {
      $c->statusCode = 3001;
    } else {
      // check msgID
      if ( empty($msgID) )
        $msgID = $c->session->pollID();

      // poll queue
      if ( $c->session->poll($store, $type, $msgID) )
        $msgTitle = $c->session->msgTitle;
      else
        $c->createErrMsg($c->session, 2000);

      // for the moment deliver the XML response string
      $xmlResponse = base64_encode($c->session->result['body']);
    }
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'msgID'             => $msgID,
               'msgTitle'          => $msgTitle,
               'xmlResponse'       => $xmlResponse,
               );
}


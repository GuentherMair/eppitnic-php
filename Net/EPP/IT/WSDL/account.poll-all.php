<?php

/*
 * register the SOAP method
 */
$server->register(
  // METHOD
  'PollAll',
  // INPUT
  array('msgID'             => 'xsd:int',
        'store'             => 'xsd:string',
        ),
  // OUTPUT
  array('status'            => 'xsd:int',
        'statusDescription' => 'xsd:string',
        'MessageQueueArray' => 'tns:MessageQueueArray',
        ),
  // NAMESPACE
  'urn:'.$wsdl_ns,
  // SOAPACTION (Endpoint/Methodname)
  'urn:'.$wsdl_ns.'#PollAll',
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
function PollAll($store = "true") {

  // prepare parameters
  $store = (strtolower($store) == "false") ? FALSE : TRUE;

  // create objects
  $c = new Net_EPP_IT_WSDL();
  $MessageQueueArray = array();

  // connect
  if ( $c->connect() ) {
    // retrieve queue length
    if ( $c->session->pollMessageCount() == 0 ) {
      $c->statusCode = 3001;
    } else {
      $msgID = array();
      $msgTitle = array();
      $xmlResponse = array();

      // poll queue
      while ( $c->session->pollMessageCount() > 0 ) {
        $msgID[] = $c->session->pollID();
        $xmlResponse[] = base64_encode($c->session->result['body']);
        if ( $c->session->poll($store, "req", $c->session->pollID()) ) {
          $msgTitle[] = $c->session->msgTitle;
        } else {
          $msgTitle[] = 'undefined';
          $c->createErrMsg($c->session, 4001);
        }
        $c->session->poll(FALSE, "ack", $c->session->pollID());
      }

      // build response array
      $MessageQueueArray = array('msgID' => $msgID, 'msgTitle' => $msgTitle, 'xmlResponse' => $xmlResponse);
    }
  }

  // disconnect
  $c->disconnect();

  // return values as defined by the SOAP interface description above
  return array('status'            => $c->statusCode,
               'statusDescription' => $c->statusDescription(),
               'MessageQueueArray' => $MessageQueueArray,
               );
}


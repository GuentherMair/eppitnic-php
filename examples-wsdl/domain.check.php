<?php

require_once 'libs/nusoap/nusoap.php'; 

$client = new nusoap_client('http://127.0.0.1:8090/wsdl.php?wsdl', true);

$err = $client->getError();
if ($err) {
  echo $err . "\n";
  exit;
}

$input = array('domain1' => 'testwithnoresultiguess.it',
               'domain2' => 'nic.it',
               'domain3' => 'inet-services.it',
               );
$output = $client->call('DomainCheck', $input);

if ( $client->fault ) {
  print_r($output);
} else {
  $err = $client->getError();
  if ( $err ) {
    echo "Error: ".$err."\n";
    echo "\n";
    // Display the input array
    echo "Input Array:\n";
    echo "==============================================\n";
    print_r($input);
    echo "\n";
    echo "\n";
    // Display the request
    echo "Request\n";
    echo "==============================================\n";
    echo $client->request . "\n";
    echo "\n";
    echo "\n";
    // Display the response
    echo "Response\n";
    echo "==============================================\n";
    echo $client->response . "\n";
    echo "\n";
    echo "\n";
    // Display the debug messages
    echo "Debug\n";
    echo "==============================================\n";
    echo $client->debug_str . "\n";
  } else {
    echo "Results\n";
    echo "==============================================\n";
    print_r($output);
  }
}
 

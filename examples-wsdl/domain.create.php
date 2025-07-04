<?php

require_once 'libs/nusoap/nusoap.php'; 

$client = new nusoap_client('http://127.0.0.1:8090/wsdl.php?wsdl', true);

$err = $client->getError();
if ($err) {
  echo $err . "\n";
  exit;
}

$input = array('domain' => 'test-guentherABC.it',
               'registrant' => 'GMHNDL0001',
               'admin' => 'GMHNDL0001',
               'tech1' => 'GMHNDL0001',
               'ns1' => 'dns1.inet-services.it',
               'ns2' => 'dns2.inet-services.it',
               'authInfo' => 'testdomain12345',
               );
$output = $client->call('DomainCreate', $input);

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
 

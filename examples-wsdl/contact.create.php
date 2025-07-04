<?php

require_once 'libs/nusoap/nusoap.php'; 

/**
 * This file is part of the WSDL interface to the EPP library.
 *
 * PHP version 5
 *
 * LICENSE:
 *
 * Copyright (c) 2009, Günther Mair <guenther.mair@hoslo.ch>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1) Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2) Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3) Neither the name of Günther Mair nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author      Günther Mair <guenther.mair@hoslo.ch>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 *
 * $Id: contact.create.php 161 2010-10-18 00:23:55Z gunny $
 */

$client = new nusoap_client('http://127.0.0.1:8090/wsdl.php?wsdl', true);

$err = $client->getError();
if ($err) {
  echo $err . "\n";
  exit;
}

$input = array('handle' => 'GMHNDL0001',
               'name' => 'Günther Mair',
               'street' => 'Andrianer Str. 7/G',
               'city' => 'Nals',
               'cc' => 'IT',
               'sp' => 'BZ',
               'pc' => '39010',
               'regCode' => '02509280216',
               'voice' => '+39.3486914569',
               'email' => 'guenther.mair@hoslo.ch',
               'nationalityCode' => 'IT',
               );
$output = $client->call('ContactCreate', $input);

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
 

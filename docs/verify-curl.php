<?php

// verify parameter count
if ( $argc < 2 )
  exit("SYNTAX: ".$argv[0]." LOCAL_IP [REMOTE_HOST]\n\n");
else
  $local_ip = $argv[1];

// select host that should be contacted
if ( $argc == 3 )
  $host = $argv[2];
else
  $host = "https://epp.nic.it";

function cURLcheckBasicFunctions() {
  if ( function_exists("curl_init") &&
       function_exists("curl_setopt") &&
       function_exists("curl_exec") &&
       function_exists("curl_close") )
    return true;
  else
    return false;
}

function cURLdownload($url, $ip) {
  $data = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <hello/>
</epp>';

  if( !cURLcheckBasicFunctions() ) return "basic cURL functions unavailable!";
  $ch = curl_init();
  if( !$ch ) return "FAIL: curl_init()";

  if( !curl_setopt($ch, CURLOPT_URL, $url) ) return "FAIL: curl_setopt(CURLOPT_URL)";
  if( !curl_setopt($ch, CURLOPT_HEADER, true) ) return "FAIL: curl_setopt(CURLOPT_HEADER)";
  if( !curl_setopt($ch, CURLOPT_RETURNTRANSFER, true) ) return "FAIL: curl_setopt(CURLOPT_RETURNTRANSFER)";
  if( !curl_setopt($ch, CURLOPT_HTTPHEADER,  array('content-type' => 'text/xml; charset=UTF-8')) ) return "FAIL: curl_setopt(CURLOPT_HTTPHEADER)";
  if( !curl_setopt($ch, CURLOPT_TIMEOUT, 30) ) return "FAIL: curl_setopt(CURLOPT_TIMEOUT)";
  if( !curl_setopt($ch, CURLOPT_MAXREDIRS, 4) ) return "FAIL: curl_setopt(CURLOPT_MAXREDIRS)";
  //if( !curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true) ) return "FAIL: curl_setopt(CURLOPT_FOLLOWLOCATION)";
  if( !curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/url-test.tmp') ) return "FAIL: curl_setopt(CURLOPT_COOKIEJAR)";
  if( !curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/url-test.tmp') ) return "FAIL: curl_setopt(CURLOPT_COOKIEFILE)";
  if( !curl_setopt($ch, CURLOPT_USERAGENT, 'PHP Net_EPP_Curl 1.0') ) return "FAIL: curl_setopt(CURLOPT_USERAGENT)";
  if( !curl_setopt($ch, CURLOPT_POST, false) ) return "FAIL: curl_setopt(CURLOPT_POST)";
  if( !curl_setopt($ch, CURLOPT_INTERFACE, $ip) ) return "FAIL: curl_setopt(CURLOPT_INTERFACE)";
  if( !curl_setopt($ch, CURLOPT_POSTFIELDS, $data) ) return "FAIL: curl_setopt(CURLOPT_POSTFIELDS)";

  $response = curl_exec($ch);
  $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $details['status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $details['headers'] = substr($response, 0, $header_size);
  $details['body'] = substr($response, $header_size);
  $details['error'] = ( $response === false ) ? curl_error($ch) : "";
  curl_close($ch);

  return htmlentities(print_r($details, true));
}

// download
echo "<pre>\n";
echo cURLdownload($host, $local_ip);
echo "</pre>\n";

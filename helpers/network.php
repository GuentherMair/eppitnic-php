<?php

function clientIp() {
  $headers = function_exists('apache_request_headers') ? apache_request_headers() : $_SERVER;

  if (array_key_exists('X-Forwarded-For', $headers) && filter_var($headers['X-Forwarded-For'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    return $headers['X-Forwarded-For'];
  } elseif (array_key_exists('HTTP_X_FORWARDED_FOR', $headers) && filter_var($headers['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    return $headers['HTTP_X_FORWARDED_FOR'];
  } else {
    return filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
  }
}

function clientIpInCidr(string $cidr): bool {
    [$network, $bits] = explode('/', $cidr);
    $ip      = ip2long(clientIp());
    $network = ip2long($network);
    $mask    = ~((1 << (32 - (int)$bits)) - 1);
    return ($ip & $mask) === ($network & $mask);
}

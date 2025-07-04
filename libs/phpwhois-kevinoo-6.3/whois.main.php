<?php

require_once __DIR__ . '/vendor/autoload.php';

use phpWhois\Whois as WhoisKevinoo;
class Whois
{
  function Lookup($domain)
  {
    $whois = new WhoisKevinoo();
    $result = $whois->lookup($domain, false);
    if (isset($result['regrinfo']['domain']['status']) && is_array($result['regrinfo']['domain']['status'])) {
      $result['regrinfo']['domain']['status'] = "multiple status fields (see detailed output)";
    }
    return $result;
  }
}

<?php

// Load composer framework
require_once __DIR__ . '/vendor/autoload.php';

use phpWhois\Whois;

$whois = new Whois();
$query = 'google.com';
$result = $whois->lookup($query,false);
//echo "<pre>";
print_r($result);
//echo "</pre>";

<?php

/**
 * A simple GET-based REST endpoint for domain WHOIS lookups.
 *
 * *** WARNING: this endpoint has NO authentication or authorization. It
 * *** MUST be placed behind an auth layer before being exposed on a
 * *** public-facing server. Adding that layer is explicitly out of scope
 * *** for now.
 *
 * Usage: GET /whois.php?domain=example.it
 */

set_include_path(dirname(__FILE__).'/..:'.ini_get('include_path'));

require_once 'vendor/autoload.php';

use phpWhois\Whois;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(array('error' => 'Method not allowed, use GET.'));
  exit;
}

$domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
if ($domain === '') {
  http_response_code(400);
  echo json_encode(array('error' => "Missing or empty 'domain' parameter."));
  exit;
}

try {
  $whois = new Whois();
  $result = $whois->lookup($domain, false);

  // collapse a multi-value status field into a single readable string
  if (isset($result['regrinfo']['domain']['status']) && is_array($result['regrinfo']['domain']['status'])) {
    $result['regrinfo']['domain']['status'] = "multiple status fields (see detailed output)";
  }

  echo json_encode($result);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(array('error' => 'WHOIS lookup failed: ' . $e->getMessage()));
}

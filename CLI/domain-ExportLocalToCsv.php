<?php

require_once dirname(__FILE__).'/../Net/EPP/Client.php'; // for the exit-code constants only, no EPP session needed
require_once dirname(__FILE__).'/../helpers/config.php';
require_once dirname(__FILE__).'/../helpers/db.php';

use RedBeanPHP\R;

// retrieve and test command line options
$options = getopt("u:a:o:");
$user_id = isset($options['u']) ? (int)$options['u'] : 1;
$isAdmin = isset($options['a']);

// export goes straight to a local DB report - no EPP session is required
$where = ['1 = 1'];
$params = [];
if ( ! $isAdmin) {
  $where[] = 'd.user_id = :user_id';
  $params[':user_id'] = $user_id;
}
$records = R::getAll("
  SELECT
    d.active, d.domain, d.authinfo, d.cr_date, d.ex_date,
    c.handle, c.org, c.name, c.email,
    u.billing_id
  FROM
    users u, contacts c, domains d
  WHERE
    d.registrant = c.handle AND
    c.user_id = u.id AND
    " . implode(' AND ', $where) . "
  ORDER BY d.domain ASC", $params);

$titles = ['Active', 'Domain', 'Auth-Info', 'Created', 'Expires', 'Registrant Handle', 'Registrant Org', 'Registrant Name', 'Registrant Email', 'Billing ID'];
$fields = ['active', 'domain', 'authinfo', 'cr_date', 'ex_date', 'handle', 'org', 'name', 'email', 'billing_id'];
$delimiter = ';';
$enclosure = '"';
$eol = "\n";

$csv = $enclosure . implode($enclosure.$delimiter.$enclosure, $titles) . $enclosure . $eol;
foreach ($records as $record) {
  $row = [];
  foreach ($fields as $field) {
    $row[] = $record[$field];
  }
  $csv .= $enclosure . implode($enclosure.$delimiter.$enclosure, $row) . $enclosure . $eol;
}

if (isset($options['o'])) {
  if (file_put_contents($options['o'], $csv) === FALSE) {
    echo "[FAILURE] unable to write to '{$options['o']}'\n";
    exit(OUTPUT_ERROR);
  }
  echo "[SUCCESS] exported domains for user ID {$user_id} to '{$options['o']}'\n";
} else {
  echo $csv;
}

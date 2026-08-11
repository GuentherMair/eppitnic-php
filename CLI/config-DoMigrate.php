<?php

use Net\EPP\Config;

// safe to require unconditionally, even before config/config.php exists --
// the DB connection is established lazily, by Config, on first use (not
// eagerly by the autoloader), so autoloading itself has no config.php
// dependency. This script's own dependency is simpler: config/config.php
// (written below) merely has to exist by the time Config::set() is first
// called, further down.
require_once dirname(__FILE__).'/../vendor/autoload.php';

// retrieve and test command line options
$options = getopt("f:");
$xmlFile = $options['f'] ?? dirname(__FILE__).'/../config.xml';

if ( ! is_readable($xmlFile)) {
  echo "[{$xmlFile}] is not a readable file.\n";
  exit(FILE_NOT_READABLE);
}

$xml = @simplexml_load_file($xmlFile);
if ($xml === false) {
  echo "Unable to parse '{$xmlFile}' as XML.\n";
  exit(INVALID_INPUT);
}

/**
 * SimpleXMLElement -> string, treating a missing/empty element as ''
 * (avoids relying on SimpleXMLElement's surprising truthiness in ternaries)
 */
function xmlStr(?SimpleXMLElement $el): string {
  return $el !== null ? trim((string) $el) : '';
}

// 1. config/config.php -- DB credentials. Left untouched if it already
// exists, so re-running this script never clobbers a working deployment's
// credentials. Must happen before the Config::set() loop below, which is
// what actually needs it to exist.
$configPhpFile = dirname(__FILE__).'/../config/config.php';
if (is_readable($configPhpFile)) {
  echo "[{$configPhpFile}] already exists -- leaving it untouched.\n";
} else {
  $db = $xml->db;
  $configPhp = "<?php\n\n"
    . "define('DB_TYPE',     " . var_export(xmlStr($db->dbtype), true) . ");\n"
    . "define('DB_HOST',     " . var_export(xmlStr($db->dbhost), true) . ");\n"
    . "define('DB_NAME',     " . var_export(xmlStr($db->dbname), true) . ");\n"
    . "define('DB_CHARSET',  'utf8');\n" // no config.xml source
    . "define('DB_USER',     " . var_export(xmlStr($db->dbuser), true) . ");\n"
    . "define('DB_PASSWORD', " . var_export(xmlStr($db->dbpwd), true) . ");\n";
  if (file_put_contents($configPhpFile, $configPhp) === false) {
    echo "Unable to write '{$configPhpFile}'.\n";
    exit(OUTPUT_ERROR);
  }
  echo "Wrote {$configPhpFile}.\n";
}

// 2. settings table -- everything else.
$port = xmlStr($xml->port);

$settings = [
  'region' => [
    'timezone'    => xmlStr($xml->timezone) ?: 'Europe/Rome',
    'lc_monetary' => 'it_IT',    // no config.xml source
    'lc_time'     => 'italian',  // no config.xml source
  ],
  // jwt_psk is a signing secret, not just another placeholder -- generate a
  // real one rather than leaving something that might accidentally go live
  'jwt_psk' => base64_encode(random_bytes(32)),
  // no config.xml source -- seeded with the same placeholder
  // config/mariadb-schema.sql uses; review and adjust by hand
  'safe_networks'   => ['127.0.0.1/32'],
  // browser origins only, and deployment-specific -- seeded empty rather than
  // with whatever hostnames happened to be on the author's machine. Requests
  // with no Origin header (curl, cron, API-token clients) are unaffected by
  // this list; see the CORS middleware in Net/EPP/Helpers.php.
  'allowed_origins' => [],
  'allowed_headers' => ['Authorization', 'Content-Type', 'X-Api-Key', 'Content-Disposition'],
  'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
  'epp' => [
    'server'         => xmlStr($xml->server),
    'server_deleted' => 'https://epp-deleted.nic.it', // no config.xml source
    'port'           => $port !== '' ? (int) $port : null,
    'interface'      => xmlStr($xml->interface),
    'username'       => xmlStr($xml->username),
    'password'       => xmlStr($xml->password),
    'lang'           => xmlStr($xml->lang),
    'cl_trid_prefix' => xmlStr($xml->clTRIDprefix),
    // config.xml's passwordexpirydays/passwordexpirynext are deliberately not
    // carried over: nothing ever read them. Automated rotation is now driven by
    // the registry's own passwdReminder poll messages, and this timestamp is
    // what rate-limits it to one attempt per 24 hours (see
    // cronjobs/process-poll-queue.php). 0 means "never attempted".
    'lastPasswordUpdate' => 0,
  ],
  'dnssec' => [
    'active'     => (int) xmlStr($xml->dnssec->active),
    'algorithm'  => (int) xmlStr($xml->dnssec->algorithm),
    'digesttype' => (int) xmlStr($xml->dnssec->digesttype),
  ],
  'smarty' => [
    'use_sub_dirs' => xmlStr($xml->smarty->use_sub_dirs) !== '' ? (bool) xmlStr($xml->smarty->use_sub_dirs) : null,
    'template_dir' => xmlStr($xml->smarty->template_dir) !== '' ? xmlStr($xml->smarty->template_dir) : null,
    'config_dir'   => xmlStr($xml->smarty->config_dir)   !== '' ? xmlStr($xml->smarty->config_dir)   : null,
    'compile_dir'  => xmlStr($xml->smarty->compile_dir)  !== '' ? xmlStr($xml->smarty->compile_dir)  : null,
    'cache_dir'    => xmlStr($xml->smarty->cache_dir)    !== '' ? xmlStr($xml->smarty->cache_dir)    : null,
  ],
  // config.xml's DEBUG flag is not carried over -- nothing ever read the
  // resulting 'debug' setting. Per-object verbosity is the $debug property on
  // Net\EPP objects (LOG_* levels), and debugfile below is what turns on cURL
  // wire logging.
  'debugfile'       => xmlStr($xml->debugfile),
  'certificatefile' => null, // no config.xml source
  'cookie_dir'      => xmlStr($xml->cookie_dir) !== '' ? xmlStr($xml->cookie_dir) : null,
  'pdnsutil_path'   => null, // no config.xml source
  'pdnsutil_ttl'    => 3600, // no config.xml source
];

foreach ($settings as $key => $value) {
  Config::set($key, $value);
  echo "Seeded setting '{$key}'.\n";
}

echo "\nDone. jwt_psk was auto-generated. safe_networks, allowed_origins/headers/methods,\n";
echo "certificatefile and pdnsutil_path/pdnsutil_ttl have no config.xml source -- review\n";
echo "and adjust them by hand.\n";

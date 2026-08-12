<?php

namespace Net\EPP\Cli;

use Net\EPP\Config;

/**
 * Convert a 6.x config.xml into config/config.php and the `settings` table.
 *
 * A one-time upgrade step: 7.0 keeps the database credentials in a file
 * because they are needed to reach the database that holds everything else,
 * and everything else in `settings`.
 */
final class ConfigMigrateCommand extends Command
{
    public function describe(): string {
        return 'convert a 6.x config.xml into config/config.php and settings';
    }

    public function options(): array {
        return [
            'file=' => 'the config.xml to read (default: config.xml in the project root)',
        ];
    }

    /**
     * SimpleXMLElement -> string, treating a missing/empty element as ''
     * (avoids relying on SimpleXMLElement's surprising truthiness in ternaries)
     */
    private static function xmlStr(?\SimpleXMLElement $el): string {
        return $el !== null ? trim((string) $el) : '';
    }

    public function run(): int {
        $xmlFile = (string) $this->option('file', dirname(__DIR__, 3) . '/config.xml');

        if ( ! is_readable($xmlFile)) {
            $this->warn("'{$xmlFile}' is not a readable file");
            return FILE_NOT_READABLE;
        }

        $xml = @simplexml_load_file($xmlFile);
        if ($xml === false) {
            $this->warn("unable to parse '{$xmlFile}' as XML");
            return INVALID_INPUT;
        }

        // 1. config/config.php -- DB credentials. Left untouched if it already
        // exists, so re-running this script never clobbers a working deployment's
        // credentials. Must happen before the Config::set() loop below, which is
        // what actually needs it to exist.
        $configPhpFile = dirname(__DIR__, 3) . '/config/config.php';
        if (is_readable($configPhpFile)) {
          $this->line("[{$configPhpFile}] already exists -- leaving it untouched.");
        } else {
          $db = $xml->db;
          $configPhp = "<?php\n\n"
            . "define('DB_TYPE',     " . var_export(self::xmlStr($db->dbtype), true) . ");\n"
            . "define('DB_HOST',     " . var_export(self::xmlStr($db->dbhost), true) . ");\n"
            . "define('DB_NAME',     " . var_export(self::xmlStr($db->dbname), true) . ");\n"
            . "define('DB_CHARSET',  'utf8');\n" // no config.xml source
            . "define('DB_USER',     " . var_export(self::xmlStr($db->dbuser), true) . ");\n"
            . "define('DB_PASSWORD', " . var_export(self::xmlStr($db->dbpwd), true) . ");\n";
          if (file_put_contents($configPhpFile, $configPhp) === false) {
            $this->line("Unable to write '{$configPhpFile}'.");
            return OUTPUT_ERROR;
          }
          $this->line("Wrote {$configPhpFile}.");
        }

        // 2. settings table -- everything else.
        $port = self::xmlStr($xml->port);

        $settings = [
          'region' => [
            'timezone'    => self::xmlStr($xml->timezone) ?: 'Europe/Rome',
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
            'server'         => self::xmlStr($xml->server),
            'server_deleted' => 'https://epp-deleted.nic.it', // no config.xml source
            'port'           => $port !== '' ? (int) $port : null,
            'interface'      => self::xmlStr($xml->interface),
            'username'       => self::xmlStr($xml->username),
            'password'       => self::xmlStr($xml->password),
            'lang'           => self::xmlStr($xml->lang),
            'cl_trid_prefix' => self::xmlStr($xml->clTRIDprefix),
            // config.xml's passwordexpirydays/passwordexpirynext are deliberately not
            // carried over: nothing ever read them. Automated rotation is now driven by
            // the registry's own passwdReminder poll messages, and this timestamp is
            // what rate-limits it to one attempt per 24 hours (see
            // cronjobs/process-poll-queue.php). 0 means "never attempted".
            'lastPasswordUpdate' => 0,
          ],
          'dnssec' => [
            'active'     => (int) self::xmlStr($xml->dnssec->active),
            'algorithm'  => (int) self::xmlStr($xml->dnssec->algorithm),
            'digesttype' => (int) self::xmlStr($xml->dnssec->digesttype),
          ],
          // config.xml's DEBUG flag is not carried over -- nothing ever read the
          // resulting 'debug' setting. Per-object verbosity is the $debug property on
          // Net\EPP objects (users.debug), and debugfile below is what turns on cURL
          // wire logging.
          'debugfile'       => self::xmlStr($xml->debugfile),
          'certificatefile' => null, // no config.xml source
          'cookie_dir'      => self::xmlStr($xml->cookie_dir) !== '' ? self::xmlStr($xml->cookie_dir) : null,
          'pdnsutil_path'   => null, // no config.xml source
          'pdnsutil_ttl'    => 3600, // no config.xml source
        ];

        foreach ($settings as $key => $value) {
          Config::set($key, $value);
          $this->line("Seeded setting '{$key}'.");
        }

        $this->line("\nDone. jwt_psk was auto-generated. safe_networks, allowed_origins/headers/methods,");
        $this->line("certificatefile and pdnsutil_path/pdnsutil_ttl have no config.xml source -- review");
        $this->line("and adjust them by hand.");

        return 0;
    }
}

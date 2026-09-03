<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Config;
use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\DatabaseCredentials;
use Eppitnic\Support\PasswordGenerator;

/**
 * Convert a 6.x config.xml into config/config.php and the `settings` table. A
 * one-time upgrade step: the DB credentials stay in a file because they are
 * needed to reach the database holding everything else.
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
        $xmlFile = (string) $this->option('file', EPPITNIC_ROOT . '/config.xml');

        if ( ! is_readable($xmlFile)) {
            $this->warn("'{$xmlFile}' is not a readable file");
            return FILE_NOT_READABLE;
        }

        $xml = @simplexml_load_file($xmlFile);
        if ($xml === false) {
            $this->warn("unable to parse '{$xmlFile}' as XML");
            return INVALID_INPUT;
        }

        // 1. config/config.php -- DB credentials. Left alone if it exists, so a
        // re-run never clobbers a working deployment. Before the Config::set()
        // loop below, which needs it to exist
        if (ConfigFile::exists()) {
          $this->line("[" . ConfigFile::path() . "] already exists -- leaving it untouched.");
        } else {
          $db = $xml->db;
          try {
            // \Throwable, not \RuntimeException: DatabaseCredentials throws
            // \InvalidArgumentException on a <db> missing dbname or dbuser, and
            // that wants the same clean exit as a failed write, not a trace
            ConfigFile::write(new DatabaseCredentials(
                type:     self::xmlStr($db->dbtype) ?: 'mysql',
                host:     self::xmlStr($db->dbhost) ?: 'localhost',
                name:     self::xmlStr($db->dbname),
                charset:  'utf8', // no config.xml source
                user:     self::xmlStr($db->dbuser),
                password: self::xmlStr($db->dbpwd),
            ));
          } catch (\Throwable $e) {
            $this->line($e->getMessage());
            return OUTPUT_ERROR;
          }
          $this->line("Wrote " . ConfigFile::path() . ".");
        }

        // 2. settings table -- everything else.
        $port = self::xmlStr($xml->port);

        $settings = [
          'region' => [
            'timezone'    => self::xmlStr($xml->timezone) ?: 'Europe/Rome',
            'lc_monetary' => 'it_IT',    // no config.xml source
            'lc_time'     => 'italian',  // no config.xml source
          ],
          // jwt_psk is a signing secret, not just another placeholder --
          // generate a real one rather than leaving something that might
          // accidentally go live
          'jwt_psk' => PasswordGenerator::signingKey(),
          // no config.xml source -- seeded with the same placeholder
          // config/mariadb-schema.sql uses; review and adjust by hand
          'safe_networks'   => ['127.0.0.1/32'],
          // browser origins only, and deployment-specific, so seeded empty.
          // Requests with no Origin header (curl, cron, token clients) are
          // unaffected -- see the CORS middleware in src/Api/Middleware.php
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
            // config.xml's passwordexpiry* are not carried over: nothing read
            // them. Rotation now follows the registry's passwdReminder, and
            // this timestamp rate-limits it to one attempt per 24h. 0 means
            // never
            'lastPasswordUpdate' => 0,
          ],
          'dnssec' => [
            'active'     => (int) self::xmlStr($xml->dnssec->active),
            'algorithm'  => (int) self::xmlStr($xml->dnssec->algorithm),
            'digesttype' => (int) self::xmlStr($xml->dnssec->digesttype),
          ],
          // config.xml's DEBUG flag is not carried over: nothing read it.
          // Verbosity is now per-object (users.debug), and debugfile below is
          // what turns on cURL wire logging
          'debugfile'       => self::xmlStr($xml->debugfile),
          'certificatefile' => null, // no config.xml source
          // no config.xml source -- 6.x never held a session open between
          // requests. Off until explicitly turned on with 'config keepalive on'
          'keepalive'         => false,
          'session_serialize' => false,
          'session_cookies'   => [],
          'session_timestamp' => 0,
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

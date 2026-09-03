<?php

namespace Eppitnic\Setup;

use Eppitnic\Config;
use Eppitnic\Persistence\User;
use Eppitnic\Support\PasswordPolicy;
use Eppitnic\Support\Validate;
use PDO;

/**
 * First-run setup, one orchestration behind three front ends: `eppitnic setup`,
 * src/Api/Routes/setup.php and public/setup.html. config/config.php is written
 * last, its existence being what the application treats as "installed".
 *
 * @category    Net
 * @package     Eppitnic\Setup\Installer
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Installer
{
    /**
     * The field list every front end renders from. `required` tracks what
     * blocks install(), not what is shown: the db_* fields default or may be
     * blank, so marking those would demand a value install() supplies anyway.
     *
     * @return list<array{name: string, label: string, default: string, secret: bool, group: string, required: bool}>
     */
    public static function requirements(): array {
        return [
            ['name' => 'db_type',            'label' => 'Database type',     'default' => 'mysql',     'secret' => false, 'group' => 'database', 'required' => false],
            ['name' => 'db_host',            'label' => 'Database host',     'default' => 'localhost', 'secret' => false, 'group' => 'database', 'required' => false],
            ['name' => 'db_name',            'label' => 'Database name',     'default' => 'eppitnic',  'secret' => false, 'group' => 'database', 'required' => true],
            ['name' => 'db_charset',         'label' => 'Database charset',  'default' => 'utf8',      'secret' => false, 'group' => 'database', 'required' => false],
            ['name' => 'db_user',            'label' => 'Database user',     'default' => 'eppitnic',  'secret' => false, 'group' => 'database', 'required' => true],
            ['name' => 'db_password',        'label' => 'Database password', 'default' => '',          'secret' => true,  'group' => 'database', 'required' => false],
            ['name' => 'admin_username',     'label' => 'Admin username',    'default' => 'admin',     'secret' => false, 'group' => 'admin',    'required' => true],
            ['name' => 'admin_password',     'label' => 'Admin password',    'default' => '',          'secret' => true,  'group' => 'admin',    'required' => true],
            ['name' => 'admin_email',        'label' => 'Admin e-mail',      'default' => '',          'secret' => false, 'group' => 'admin',    'required' => false],
            ['name' => 'epp_username',       'label' => 'EPP username',      'default' => '',          'secret' => false, 'group' => 'epp',      'required' => false],
            ['name' => 'epp_password',       'label' => 'EPP password',      'default' => '',          'secret' => true,  'group' => 'epp',      'required' => false],
            ['name' => 'epp_cl_trid_prefix', 'label' => 'EPP clTRID prefix', 'default' => '',          'secret' => false, 'group' => 'epp',      'required' => false],
        ];
    }

    /**
     * Whether setup is reachable at all -- the one predicate to change if this
     * ever needs more than "the file does not exist yet".
     */
    public static function isOpen(): bool {
        return ! ConfigFile::exists();
    }

    /**
     * Probe candidate credentials with a plain PDO connection, dropped straight
     * after. Not RedBeanPHP: R::setup() is process-global and cannot be
     * un-called, so a retried candidate would inherit the first attempt's
     * state.
     *
     * @throws \RuntimeException if the connection fails
     * @return array{server_version: string}
     */
    public static function verify(DatabaseCredentials $creds): array {
        try {
            $pdo = new PDO($creds->dsn(), $creds->user, $creds->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to connect to the database: ' . $e->getMessage(), 0, $e);
        } finally {
            $pdo = null; // no global state to undo -- this is the whole point
        }

        return ['server_version' => $version];
    }

    /**
     * The clTRID prefix to store: the one given, or the registrar part of the
     * username ("ABCD-REG" -> "ABCD"). Its own method so validateEpp() checks
     * the value install() will actually store.
     *
     * @param array<string, mixed> $input every requirements() field, snake_case
     */
    private static function clTridPrefix(array $input, string $eppUsername): string {
        // `?? ''` rather than a bare read: the field is optional and an API
        // client that simply leaves it out is normal, so the key's absence is
        // not a programming error to warn about
        return (string) (($input['epp_cl_trid_prefix'] ?? '') ?: (strtok($eppUsername, '-') ?: $eppUsername));
    }

    /**
     * Reject an EPP credential the registry would not accept. Stored as given
     * until now, so an over-long username failed at every `<login>` days later.
     * Same check as `config epp-set`, so the front ends cannot disagree.
     *
     * @param array<string, mixed> $input every requirements() field, snake_case
     * @throws \InvalidArgumentException on a value the registry would refuse
     */
    private static function validateEpp(array $input): void {
        $username = (string) ($input['epp_username'] ?? '');
        if ($username === '') {
            return; // nothing is seeded without one -- see install()'s step 7
        }

        $candidates = [
            'username'       => $username,
            'password'       => (string) ($input['epp_password'] ?? ''),
            'cl_trid_prefix' => self::clTridPrefix($input, $username),
        ];

        foreach ($candidates as $field => $value) {
            // an omitted password is left at the schema placeholder, same as
            // an install that seeds no EPP block at all; only a supplied one
            // has to satisfy epp:pwType
            if ($field === 'password' && $value === '') {
                continue;
            }
            if ($error = Validate::eppField($field, $value)) {
                throw new \InvalidArgumentException('epp_' . $error);
            }
        }
    }

    /**
     * @param array<string, mixed> $input every requirements() field, snake_case
     * @throws \InvalidArgumentException on a missing required field
     * @throws \RuntimeException if already installed, the database is
     *         unreachable/unsuitable, or the admin username is taken
     * @return array{schema_version: string, admin: array{id: int, username: string}}
     */
    public static function install(array $input): array {
        if ( ! self::isOpen()) {
            throw new \RuntimeException("'" . ConfigFile::path() . "' already exists -- eppitnic is already set up.");
        }

        $adminUsername = trim((string) ($input['admin_username'] ?? ''));
        $adminPassword = (string) ($input['admin_password'] ?? '');
        if ($adminUsername === '' || $adminPassword === '') {
            throw new \InvalidArgumentException('admin_username and admin_password are required.');
        }

        // Checked here and not only in the browser: this endpoint is reachable
        // with curl, and the account it creates is the one administrator the
        // installation starts with.
        if ( ! PasswordPolicy::isAcceptable($adminPassword)) {
            throw new \InvalidArgumentException(PasswordPolicy::explain($adminPassword));
        }

        // Only when the caller sent one. A confirmation field is a slip-guard
        // for a person typing into a form, not something an API client should
        // be made to repeat itself over.
        $confirm = $input['admin_password_confirm'] ?? null;
        if ($confirm !== null && (string) $confirm !== $adminPassword) {
            throw new \InvalidArgumentException('The two admin passwords do not match.');
        }

        // Here, not at step 7 where these are stored: step 6 creates the admin,
        // and User::create() refuses an existing username -- so a throw after
        // it would make the retry fail on the previous attempt's own account
        self::validateEpp($input);

        // 1. probe credentials (raw PDO) -- nothing committed yet
        $creds = DatabaseCredentials::fromArray($input);
        self::verify($creds);

        // 2. define() the six constants in-process -- Config::connect() reads
        // them exactly as it would from a config/config.php that already
        // existed (Config::loadConfig() tolerates this, see Config.php)
        foreach ($creds->toDefines() as $name => $value) {
            if ( ! defined($name)) {
                define($name, $value);
            }
        }

        // 3. connect RedBeanPHP
        Config::connect();

        // 4. fresh vs. migrate -- refuses a database that is neither
        SchemaInstaller::install();

        // 5. migrate() (no-op on fresh: mariadb-schema.sql already stamped
        // SCHEMA_VERSION), load settings, generate jwt_psk
        Config::init();

        // 6. first admin user
        $adminId = User::create(
            username: $adminUsername,
            password: $adminPassword,
            email: ($input['admin_email'] ?? '') !== '' ? $input['admin_email'] : null,
            admin: true,
        );

        // 7. seed epp, if given -- optional: a fresh install still boots with
        // it left at the schema placeholder, same as today, just configured
        // by hand afterward instead of interactively at this point
        if (($input['epp_username'] ?? '') !== '') {
            $epp = Config::get('epp');
            $epp['username']       = (string) $input['epp_username'];
            $epp['password']       = (string) ($input['epp_password'] ?? '');
            $epp['cl_trid_prefix'] = self::clTridPrefix($input, $epp['username']);
            Config::set('epp', $epp);
        }

        // 8. the commit point
        ConfigFile::write($creds);

        return [
            'schema_version' => SCHEMA_VERSION,
            'admin'          => ['id' => $adminId, 'username' => $adminUsername],
        ];
    }
}

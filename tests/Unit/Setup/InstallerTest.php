<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Config;
use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\Installer;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * requirements()'s shape and install()'s validation are plain unit tests. The
 * full happy path needs a real database throughout, so it is gated like
 * SchemaInstallerTest: skipped when no local MariaDB is reachable, never
 * failed.
 */
final class InstallerTest extends TestCase
{
    private const DB_NAME = 'eppitnic_setuptest_installer';

    /** a PDO driver that does not exist: fails at once, reaching no server */
    private const UNREACHABLE_DB = [
        'db_type' => 'eppitnic_test_bogus_driver', 'db_host' => 'localhost',
        'db_name' => 'x', 'db_user' => 'x', 'db_password' => 'x',
    ];

    /** @var string[] throwaway config directories to remove afterwards */
    private array $scratchDirectories = [];

    protected function tearDown(): void {
        ConfigFile::usePath(null);
        foreach ($this->scratchDirectories as $dir) {
            @unlink($dir . '/config.php');
            @rmdir($dir);
        }
    }

    /**
     * get_current_user() answers for the *script file*, which under
     * RunInSeparateProcess is a PHPUnit temp file and can differ from the shell
     * user making the socket-auth connection. This answers the real one.
     */
    private static function dbUser(): string {
        return posix_getpwuid(posix_geteuid())['name'];
    }

    public function testRequirementsListsEveryExpectedField(): void {
        $names = array_column(Installer::requirements(), 'name');

        $this->assertSame([
            'db_type', 'db_host', 'db_name', 'db_charset', 'db_user', 'db_password',
            'admin_username', 'admin_password', 'admin_email',
            'registrar_tag', 'epp_username', 'epp_password', 'epp_cl_trid_prefix',
        ], $names);
    }

    public function testSecretIsTrueOnlyForThePasswordFields(): void {
        foreach (Installer::requirements() as $field) {
            $isPassword = str_ends_with($field['name'], '_password');
            $this->assertSame($isPassword, $field['secret'], "{$field['name']}'s secret flag is wrong");
        }
    }

    public function testRequiredTracksWhatActuallyBlocksInstall(): void {
        // db_type/db_host/db_charset default in
        // DatabaseCredentials::fromArray() and db_password may be blank, so
        // none of those block install(); db_name/db_user have no fallback and
        // the admin fields and the registrar tag are checked
        $required = ['db_name', 'db_user', 'admin_username', 'admin_password', 'registrar_tag'];

        foreach (Installer::requirements() as $field) {
            $expected = in_array($field['name'], $required, true);
            $this->assertSame($expected, $field['required'], "{$field['name']}'s required flag is wrong");
        }
    }

    public function testInstallThrowsBeforeTouchingTheDatabaseWhenAdminFieldsAreBlank(): void {
        $this->useThrowawayConfigPath();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/admin_username and admin_password/');
        Installer::install(self::UNREACHABLE_DB);
    }

    public function testInstallThrowsWhenConfigFileAlreadyExists(): void {
        $path = $this->useThrowawayConfigPath();
        file_put_contents($path, "<?php\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists/');
        Installer::install(['admin_username' => 'admin', 'admin_password' => 'secret']);
    }

    // -----------------------------------------------------------------
    // EPP credentials -- the registry's rules, not this codebase's
    // -----------------------------------------------------------------

    /**
     * These fields were stored exactly as given until now: the `config epp-*`
     * verbs checked them and the browser installer's path did not, so the
     * mismatch only showed at `<login>`. Checked before the database is
     * touched.
     *
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function unacceptableEppInput(): array {
        return [
            'no registrar tag'              => [['registrar_tag' => ''], 'registrar_tag'],
            'tag without -REG'              => [['registrar_tag' => 'MYCOMPANY'], 'registrar_tag'],
            'maintainer account as tag'     => [['registrar_tag' => 'MYCOMPANY-MNT'], 'registrar_tag'],
            'lower-case tag'                => [['registrar_tag' => 'mycompany-REG'], 'registrar_tag'],
            'username over 64'              => [['epp_username' => str_repeat('A', 61) . '-REG'], 'epp_username'],
            'username under 3'              => [['epp_username' => 'AB'], 'epp_username'],
            'password over pwType\'s 16'    => [
                ['epp_username' => 'TEST-REG', 'epp_password' => 'far-too-long-a-password'],
                'epp_password',
            ],
            'password under pwType\'s 6'    => [
                ['epp_username' => 'TEST-REG', 'epp_password' => 'short'],
                'epp_password',
            ],
            'prefix leaving no room'        => [
                ['epp_username' => 'TEST-REG', 'epp_cl_trid_prefix' => str_repeat('A', 33)],
                'epp_cl_trid_prefix',
            ],
            // a prefix typed as such is refused, not silently rewritten
            'lower-case prefix'             => [
                ['epp_username' => 'TEST-REG', 'epp_cl_trid_prefix' => 'test'],
                'epp_cl_trid_prefix',
            ],
        ];
    }

    /**
     * @param array<string, string> $epp
     */
    #[DataProvider('unacceptableEppInput')]
    public function testInstallRefusesEppCredentialsTheRegistryWouldNot(array $epp, string $field): void {
        $this->useThrowawayConfigPath();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($field, '/') . '/');
        Installer::install($epp + $this->minimalInput());
    }

    /**
     * The complement: only the registrar tag is required. The username
     * defaults to it and the password may follow later; each of these gets
     * past validation and fails on the database instead.
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function acceptableEppInput(): array {
        return [
            'the tag alone'            => [[]],
            'username, no password'    => [['epp_username' => 'TEST-REG']],
            'username and password'    => [['epp_username' => 'TEST-REG', 'epp_password' => 'good-enough']],
            'EPP user without suffix'  => [['epp_username' => 'mario.rossi']],
            // derived from the tag when omitted -- "TEST-REG" -> "TEST"
            'prefix left to derive'    => [['epp_cl_trid_prefix' => '']],
            // derived from a tag that isn't a valid prefix as it stands
            'prefix derived and fixed' => [['registrar_tag' => 'MY.CO-REG']],
        ];
    }

    /**
     * @param array<string, string> $epp
     */
    #[DataProvider('acceptableEppInput')]
    public function testInstallDoesNotRefuseAnAcceptableEppBlock(array $epp): void {
        $this->useThrowawayConfigPath();

        // past validation, install() goes on to the database, which this
        // input cannot reach -- so anything but InvalidArgumentException means
        // the EPP block was accepted, which is what is being asserted
        $this->expectException(\RuntimeException::class);
        Installer::install($epp + $this->minimalInput());
    }

    /**
     * Enough to get past the admin and registrar tag checks, with a DSN nothing can connect to.
     *
     * @return array<string, string>
     */
    private function minimalInput(): array {
        return self::UNREACHABLE_DB + [
            'admin_username' => 'admin', 'admin_password' => 'Setup-Admin-42!',
            'registrar_tag' => 'TEST-REG',
        ];
    }

    private function useThrowawayConfigPath(): string {
        $dir = sys_get_temp_dir() . '/eppitnic-installer-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->scratchDirectories[] = $dir;
        $path = $dir . '/config.php';
        ConfigFile::usePath($path);
        return $path;
    }

    // -----------------------------------------------------------------
    // full happy path -- real MariaDB required
    // -----------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testInstallAppliesSchemaCreatesAdminAndWritesConfigFile(): void {
        try {
            $root = new PDO('mysql:host=localhost', self::dbUser(), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('no local MariaDB reachable: ' . $e->getMessage());
        }
        $root->exec('DROP DATABASE IF EXISTS `' . self::DB_NAME . '`');
        $root->exec('CREATE DATABASE `' . self::DB_NAME . '`');

        $dir = sys_get_temp_dir() . '/eppitnic-installer-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/config.php';
        ConfigFile::usePath($path);

        try {
            $result = Installer::install([
                'db_type' => 'mysql', 'db_host' => 'localhost', 'db_name' => self::DB_NAME,
                'db_charset' => 'utf8', 'db_user' => self::dbUser(), 'db_password' => '',
                'admin_username' => 'setupadmin', 'admin_password' => 'Setup-Admin-42!',
                'admin_email' => 'admin@example.test',
                'registrar_tag' => 'MY.CO-REG', 'epp_username' => 'mario.rossi',
            ]);

            $this->assertSame(SCHEMA_VERSION, $result['schema_version']);
            $this->assertSame('setupadmin', $result['admin']['username']);
            $this->assertGreaterThan(0, $result['admin']['id']);

            $this->assertTrue(ConfigFile::exists());
            $this->assertSame(0600, fileperms($path) & 0777);
            $written = (string) file_get_contents($path);
            $this->assertStringNotContainsString('Setup-Admin-42!', $written, 'the admin password leaked into config.php');

            $adminRow = R::getRow('SELECT username, role, reseller_id FROM users WHERE id = ?', [$result['admin']['id']]);
            $this->assertSame('setupadmin', $adminRow['username']);
            $this->assertSame('admin', $adminRow['role']);
            $this->assertSame(1, (int) $adminRow['reseller_id'], 'the first admin belongs to the registrar itself');
            $this->assertSame('Registrar (self)', R::getCell('SELECT name FROM resellers WHERE id = 1'));

            $epp = Config::get('epp');
            $this->assertSame('MY.CO-REG', $epp['registrar_tag']);
            $this->assertSame('mario.rossi', $epp['username'], 'a given username wins over the tag');
            $this->assertSame('MYCO', $epp['cl_trid_prefix'], 'derived from the tag, not the username');

            // a second install() against the now-configured fixture must
            // refuse, not silently create a second admin
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/already exists/');
            Installer::install([
                'db_type' => 'mysql', 'db_host' => 'localhost', 'db_name' => self::DB_NAME,
                'db_charset' => 'utf8', 'db_user' => self::dbUser(), 'db_password' => '',
                'admin_username' => 'second', 'admin_password' => 'whatever',
            ]);
        } finally {
            @unlink($path);
            @rmdir($dir);
            try {
                $root->exec('DROP DATABASE IF EXISTS `' . self::DB_NAME . '`');
            } catch (\Throwable) {
                // best effort
            }
        }
    }
}

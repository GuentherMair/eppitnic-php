<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Config;
use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\Installer;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * requirements()'s shape and install()'s validation are plain unit tests.
 * The full happy path -- schema applied, admin row created, config.php
 * written -- needs a real database throughout, so it's gated the same way
 * SchemaInstallerTest is: skip when no local MariaDB is reachable, never
 * fail the suite for its absence (tests/bootstrap.php's contract).
 */
final class InstallerTest extends TestCase
{
    private const DB_NAME = 'eppitnic_setuptest_installer';

    protected function tearDown(): void {
        ConfigFile::usePath(null);
    }

    /**
     * get_current_user() answers the owning user of the *script file*, which
     * under #[RunInSeparateProcess] is a PHPUnit-generated temp file and can
     * differ from the shell user actually running the socket-auth MariaDB
     * connection. posix_getpwuid(posix_geteuid()) answers the real
     * process-owning user instead.
     */
    private static function dbUser(): string {
        return posix_getpwuid(posix_geteuid())['name'];
    }

    public function testRequirementsListsEveryExpectedField(): void {
        $names = array_column(Installer::requirements(), 'name');

        $this->assertSame([
            'db_type', 'db_host', 'db_name', 'db_charset', 'db_user', 'db_password',
            'admin_username', 'admin_password', 'admin_email',
            'epp_username', 'epp_password', 'epp_cl_trid_prefix',
        ], $names);
    }

    public function testSecretIsTrueOnlyForThePasswordFields(): void {
        foreach (Installer::requirements() as $field) {
            $isPassword = str_ends_with($field['name'], '_password');
            $this->assertSame($isPassword, $field['secret'], "{$field['name']}'s secret flag is wrong");
        }
    }

    public function testRequiredTracksWhatActuallyBlocksInstall(): void {
        // db_type/db_host/db_charset fall back to a real default in
        // DatabaseCredentials::fromArray() when omitted, and db_password may
        // genuinely be blank (local trust-auth) -- none of those block
        // install(). db_name/db_user have no such fallback, and
        // admin_username/admin_password are checked explicitly.
        $required = ['db_name', 'db_user', 'admin_username', 'admin_password'];

        foreach (Installer::requirements() as $field) {
            $expected = in_array($field['name'], $required, true);
            $this->assertSame($expected, $field['required'], "{$field['name']}'s required flag is wrong");
        }
    }

    public function testInstallThrowsBeforeTouchingTheDatabaseWhenAdminFieldsAreBlank(): void {
        $this->useThrowawayConfigPath();

        // a DSN that would fail loudly if ever reached, so a silent pass here
        // can't be mistaken for "the admin-field check never ran"
        $input = [
            'db_type' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => '1',
            'db_name' => 'x', 'db_user' => 'x', 'db_password' => 'x',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/admin_username and admin_password/');
        Installer::install($input);
    }

    public function testInstallThrowsWhenConfigFileAlreadyExists(): void {
        $path = $this->useThrowawayConfigPath();
        file_put_contents($path, "<?php\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists/');
        Installer::install(['admin_username' => 'admin', 'admin_password' => 'secret']);
    }

    private function useThrowawayConfigPath(): string {
        $dir = sys_get_temp_dir() . '/eppitnic-installer-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
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
            ]);

            $this->assertSame(SCHEMA_VERSION, $result['schema_version']);
            $this->assertSame('setupadmin', $result['admin']['username']);
            $this->assertGreaterThan(0, $result['admin']['id']);

            $this->assertTrue(ConfigFile::exists());
            $this->assertSame(0600, fileperms($path) & 0777);
            $written = (string) file_get_contents($path);
            $this->assertStringNotContainsString('a-strong-password', $written, 'the admin password leaked into config.php');

            $adminRow = R::getRow('SELECT username, admin FROM users WHERE id = ?', [$result['admin']['id']]);
            $this->assertSame('setupadmin', $adminRow['username']);
            $this->assertSame(1, (int) $adminRow['admin']);

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

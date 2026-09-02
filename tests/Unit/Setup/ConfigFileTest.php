<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\DatabaseCredentials;
use PHPUnit\Framework\TestCase;

/**
 * config/config.php is never touched here -- every test points ConfigFile at a
 * throwaway path, which is why that seam exists: this checkout has a real one
 * holding real credentials, leaving no other way to reach those branches.
 */
final class ConfigFileTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/eppitnic-configfile-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->path = $this->dir . '/config.php';
        ConfigFile::usePath($this->path);
    }

    protected function tearDown(): void {
        ConfigFile::usePath(null);
        // the not-writable test may leave $this->dir chmoded down -- restore
        // before cleanup so unlink/rmdir don't fail
        @chmod($this->dir, 0700);
        @unlink($this->path);
        @rmdir($this->dir);
    }

    private function creds(): DatabaseCredentials {
        return new DatabaseCredentials(type: 'mysql', host: 'localhost', name: 'eppitnic', charset: 'utf8', user: 'eppitnic', password: 'sekrit');
    }

    public function testExistsIsFalseForANonexistentPath(): void {
        $this->assertFalse(ConfigFile::exists());
    }

    public function testExistsIsTrueAfterWrite(): void {
        ConfigFile::write($this->creds());

        $this->assertTrue(ConfigFile::exists());
    }

    public function testWriteProducesTheSixExpectedDefines(): void {
        ConfigFile::write($this->creds());

        // grepped as raw text, not required -- requiring it would define()
        // real DB_* constants and pollute the rest of the suite
        $contents = (string) file_get_contents($this->path);
        foreach (['DB_TYPE', 'DB_HOST', 'DB_NAME', 'DB_CHARSET', 'DB_USER', 'DB_PASSWORD'] as $constant) {
            $this->assertStringContainsString("define('{$constant}',", $contents);
        }
        $this->assertStringContainsString("'sekrit'", $contents);
    }

    public function testWrittenFileModeIsExactly0600(): void {
        ConfigFile::write($this->creds());

        $this->assertSame(0600, fileperms($this->path) & 0777);
    }

    public function testWriteRefusesToOverwriteAnExistingFile(): void {
        ConfigFile::write($this->creds());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists/');
        ConfigFile::write($this->creds());
    }

    public function testWriteThrowsWhenTheDirectoryIsNotWritable(): void {
        chmod($this->dir, 0500);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not writable/');
        ConfigFile::write($this->creds());
    }

    // -----------------------------------------------------------------
    // EPPITNIC_CONFIG_DIR
    // -----------------------------------------------------------------

    public function testPathHonoursEppitnicConfigDirWhenNoSeamIsSet(): void {
        ConfigFile::usePath(null);
        putenv('EPPITNIC_CONFIG_DIR=' . $this->dir);
        try {
            $this->assertSame($this->dir . '/config.php', ConfigFile::path());
        } finally {
            putenv('EPPITNIC_CONFIG_DIR');
        }
    }

    public function testExplicitSeamStillWinsOverEppitnicConfigDir(): void {
        putenv('EPPITNIC_CONFIG_DIR=/should-not-be-used');
        try {
            $this->assertSame($this->path, ConfigFile::path());
        } finally {
            putenv('EPPITNIC_CONFIG_DIR');
        }
    }

    public function testPathDefaultsToConfigDirectoryWhenEnvVarIsUnset(): void {
        ConfigFile::usePath(null);
        putenv('EPPITNIC_CONFIG_DIR');

        $this->assertSame(EPPITNIC_ROOT . '/config/config.php', ConfigFile::path());
    }
}

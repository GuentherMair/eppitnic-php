<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Epp\Transport\Curl;
use Eppitnic\Service\DebugFile;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

final class DebugFileTest extends EppTestCase
{
    private string $dir;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        $this->dir = sys_get_temp_dir() . '/eppitnic-debugfile-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/sub', 0700, true);
        $this->dir = (string) realpath($this->dir);
        putenv('EPPITNIC_VAR_DIR=' . $this->dir);
        Config::loadForTesting(['debugfile' => ''] + static::SETTINGS);
    }

    protected function tearDown(): void {
        putenv('EPPITNIC_VAR_DIR');
        foreach ((array) glob($this->dir . '/{,sub/}*.log', GLOB_BRACE) as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/sub');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testABareNameLandsInTheVarDirectory(): void {
        $this->assertSame($this->dir . '/epp.log', DebugFile::resolve('epp.log'));
    }

    public function testAPathBelowTheVarDirectoryIsAccepted(): void {
        $this->assertSame($this->dir . '/sub/epp.log', DebugFile::resolve($this->dir . '/sub/epp.log'));
    }

    /** @return array<string, array{0: string}> */
    public static function refusedPaths(): array {
        return [
            'not a .log file'     => ['epp.php'],
            'config.php'          => ['config.php'],
            'hidden file'         => ['.epp.log'],
            'outside the var dir' => ['/tmp/epp.log'],
            'climbing out'        => ['../epp.log'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedPaths')]
    public function testResolveRefuses(string $value): void {
        $this->expectException(\InvalidArgumentException::class);
        DebugFile::resolve(str_starts_with($value, '../') ? $this->dir . '/' . $value : $value);
    }

    public function testSetCreatesTheFilePrivateAndRecordsHistory(): void {
        $status = DebugFile::set('epp.log', 3);

        $this->assertSame($this->dir . '/epp.log', $status['path']);
        $this->assertTrue($status['exists']);
        $this->assertTrue($status['writable']);
        $this->assertSame(0600, fileperms($this->dir . '/epp.log') & 0777);
        $this->assertSame($this->dir . '/epp.log', Config::get('debugfile'));

        $row = R::getRow("SELECT * FROM history WHERE object = 'debugfile'");
        $this->assertSame('3', (string) $row['user_id']);
    }

    public function testSetRefusesANewLogWhileOneIsRecording(): void {
        DebugFile::set('epp.log', 3);

        $this->expectException(\DomainException::class);
        DebugFile::set('other.log', 3);
    }

    public function testSetTheRecordingFileAgainChangesNothing(): void {
        DebugFile::set('epp.log', 3);

        $this->assertSame($this->dir . '/epp.log', DebugFile::set('epp.log', 3)['path']);
        $this->assertCount(1, R::getAll("SELECT * FROM history WHERE object = 'debugfile'"));
    }

    public function testDeleteRemovesTheFileThenTheSetting(): void {
        DebugFile::set('epp.log', 3);
        file_put_contents($this->dir . '/epp.log', 'traffic');

        $this->assertSame('', DebugFile::delete(3)['path']);
        $this->assertFileDoesNotExist($this->dir . '/epp.log');
        $this->assertSame('', Config::get('debugfile'));
        $this->assertSame('delete', R::getCell("SELECT action FROM history WHERE object = 'debugfile' ORDER BY id DESC"));
    }

    public function testDeleteOfAFileAlreadyGoneClearsTheSetting(): void {
        DebugFile::set('epp.log', 3);
        unlink($this->dir . '/epp.log');

        $this->assertSame('', DebugFile::delete(3)['path']);
    }

    public function testAFileThatCannotBeDeletedKeepsTheSetting(): void {
        DebugFile::set($this->dir . '/sub/epp.log', 3);
        chmod($this->dir . '/sub', 0500);
        try {
            DebugFile::delete(3);
            $this->fail('the delete reported success');
        } catch (\RuntimeException) {
        } finally {
            chmod($this->dir . '/sub', 0700);
        }

        $this->assertFileExists($this->dir . '/sub/epp.log');
        $this->assertSame($this->dir . '/sub/epp.log', Config::get('debugfile'));
    }

    public function testDeleteWhileOffDoesNothing(): void {
        $this->assertSame('', DebugFile::delete(3)['path']);
        $this->assertEmpty(R::getAll("SELECT * FROM history WHERE object = 'debugfile'"));
    }

    public function testAnUnwritableDirectoryLeavesTheSettingAlone(): void {
        chmod($this->dir . '/sub', 0500);
        try {
            DebugFile::set($this->dir . '/sub/epp.log', 3);
            $this->fail('an unwritable directory was accepted');
        } catch (\InvalidArgumentException) {
        } finally {
            chmod($this->dir . '/sub', 0700);
        }

        $this->assertSame('', Config::get('debugfile'));
        $this->assertEmpty(R::getAll("SELECT * FROM history WHERE object = 'debugfile'"));
    }

    public function testTailIsNullWhileTheLogIsEmpty(): void {
        DebugFile::set('epp.log', 3);

        $this->assertNull(DebugFile::tail());
    }

    public function testTailReturnsTheLogsEnd(): void {
        DebugFile::set('epp.log', 3);
        file_put_contents($this->dir . '/epp.log', str_repeat('x', DebugFile::TAIL_BYTES) . 'END');

        $tail = DebugFile::tail();
        $this->assertTrue($tail['truncated']);
        $this->assertSame(DebugFile::TAIL_BYTES + 3, $tail['size']);
        $this->assertStringEndsWith('END', $tail['content']);
        $this->assertSame(DebugFile::TAIL_BYTES, strlen($tail['content']));
    }

    public function testMaskHidesPasswordsAuthCodesAndCookies(): void {
        $masked = Curl::mask(
            "> Cookie: JSESSIONID=abc123\n"
            . "Set-Cookie: JSESSIONID=zzz; Path=/\n"
            . "Authorization: Basic dXNlcjpwdw==\n"
            . '<login><clID>A-REG</clID><pw>s3cr3t</pw><newPW>n3w-pw</newPW></login>'
            . '<domain:authInfo><domain:pw>AUTH-1</domain:pw></domain:authInfo>'
        );

        foreach (['abc123', 'zzz', 'dXNlcjpwdw', 's3cr3t', 'n3w-pw', 'AUTH-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked);
        }
        $this->assertStringContainsString('<clID>A-REG</clID>', $masked, 'only secrets are masked');
        $this->assertStringContainsString('<domain:pw>***</domain:pw>', $masked);
    }

    public function testAnUnwritableDebugFileOnlyWarns(): void {
        $curl = new Curl('https://epp.example.it');
        $log = ini_set('error_log', $this->dir . '/php-errors.log');
        try {
            $curl->setDebugFile('/nonexistent/dir/epp.log');
        } finally {
            ini_set('error_log', (string) $log);
        }

        $this->assertStringContainsString('is not writable', (string) file_get_contents($this->dir . '/php-errors.log'));
    }
}

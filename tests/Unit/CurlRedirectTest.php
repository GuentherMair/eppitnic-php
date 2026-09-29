<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Transport\Curl;
use PHPUnit\Framework\TestCase;

/**
 * An EPP command is posted once, to the configured server: a redirect is
 * reported as the answer, not followed to wherever it points.
 */
final class CurlRedirectTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $dir = '';
    private int $port = 0;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/eppitnic-curl-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/router.php', <<<'PHP'
        <?php
        if ($_SERVER['REQUEST_URI'] === '/elsewhere') {
            file_put_contents(__DIR__ . '/followed', 'yes');
            echo 'followed';
            return;
        }
        header('Location: /elsewhere', true, 302);
        PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            $this->markTestSkipped("cannot bind a local port: {$error}");
        }
        $this->port = (int) substr(stream_socket_get_name($socket, false), strrpos(stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $this->dir . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $this->port) === false; $i++) {
            usleep(100000);
        }
    }

    protected function tearDown(): void {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testARedirectIsNotFollowed(): void {
        $curl = new Curl("http://127.0.0.1:{$this->port}/epp");

        $body = $curl->query('<epp/>');

        $this->assertSame(302, $curl->getHttpStatus());
        $this->assertSame('', $body);
        $this->assertFileDoesNotExist($this->dir . '/followed');
    }
}

<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\DoctorNormalizePayloadsCommand;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * Rewriting the legacy `__SERIALIZED:` envelope in place.
 *
 * A one-way rewrite of every stored EPP body, so it is worth knowing it works
 * before it is pointed at a table with twenty thousand rows in it.
 */
final class NormalizePayloadsTest extends EppTestCase
{
    private const XML = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response/></epp>';

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['transactions', 'responses', 'msgqueue'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, cl_trdata TEXT)');
        R::exec('CREATE TABLE responses (id INTEGER PRIMARY KEY, sv_httpdata TEXT, sv_httpheaders TEXT, extvaluereason TEXT)');
        R::exec('CREATE TABLE msgqueue (id INTEGER PRIMARY KEY, sv_httpdata TEXT, sv_httpheaders TEXT)');

        Config::loadForTesting(self::SETTINGS);
    }

    private static function wrap(string $body): string {
        return '__SERIALIZED:' . base64_encode(serialize($body));
    }

    /**
     * @param string[] $args
     */
    private function normalizeWith(array $args = ['--yes']): string {
        $command = new DoctorNormalizePayloadsCommand($args);

        ob_start();
        $command->run();
        $command->flush();
        return (string) ob_get_clean();
    }

    public function testAnEnvelopedRowBecomesItsBody(): void {
        R::exec('INSERT INTO msgqueue (id, sv_httpdata) VALUES (1, ?)', [self::wrap(self::XML)]);

        $this->normalizeWith();

        $this->assertSame(self::XML, R::getCell('SELECT sv_httpdata FROM msgqueue WHERE id = 1'));
    }

    public function testAPlainRowIsLeftAlone(): void {
        R::exec('INSERT INTO msgqueue (id, sv_httpdata) VALUES (1, ?)', [self::XML]);

        $output = $this->normalizeWith();

        $this->assertSame(self::XML, R::getCell('SELECT sv_httpdata FROM msgqueue WHERE id = 1'));
        $this->assertStringContainsString('no enveloped rows', $output);
    }

    /**
     * Every column the 6.x codebase wrapped, not only the one that happened to
     * be noticed first.
     */
    public function testEveryWrappedColumnIsCovered(): void {
        R::exec('INSERT INTO transactions (id, cl_trdata) VALUES (1, ?)', [self::wrap('a')]);
        R::exec('INSERT INTO responses (id, sv_httpdata, sv_httpheaders, extvaluereason) VALUES (1, ?, ?, ?)',
            [self::wrap('b'), self::wrap('c'), self::wrap('d')]);
        R::exec('INSERT INTO msgqueue (id, sv_httpdata, sv_httpheaders) VALUES (1, ?, ?)',
            [self::wrap('e'), self::wrap('f')]);

        $this->normalizeWith();

        $this->assertSame('a', R::getCell('SELECT cl_trdata FROM transactions WHERE id = 1'));
        $this->assertSame('b', R::getCell('SELECT sv_httpdata FROM responses WHERE id = 1'));
        $this->assertSame('c', R::getCell('SELECT sv_httpheaders FROM responses WHERE id = 1'));
        $this->assertSame('d', R::getCell('SELECT extvaluereason FROM responses WHERE id = 1'));
        $this->assertSame('e', R::getCell('SELECT sv_httpdata FROM msgqueue WHERE id = 1'));
        $this->assertSame('f', R::getCell('SELECT sv_httpheaders FROM msgqueue WHERE id = 1'));
    }

    /**
     * A damaged envelope is still the only copy of whatever it holds, so it is
     * reported and left as it is rather than replaced with an empty string.
     */
    public function testADamagedEnvelopeIsLeftAlone(): void {
        $damaged = '__SERIALIZED:!!!not base64!!!';
        R::exec('INSERT INTO msgqueue (id, sv_httpdata) VALUES (1, ?)', [$damaged]);
        R::exec('INSERT INTO msgqueue (id, sv_httpdata) VALUES (2, ?)', [self::wrap(self::XML)]);

        $output = $this->normalizeWith();

        $this->assertSame($damaged, R::getCell('SELECT sv_httpdata FROM msgqueue WHERE id = 1'));
        $this->assertSame(self::XML, R::getCell('SELECT sv_httpdata FROM msgqueue WHERE id = 2'));

        // one of the two, not both -- the count is on stdout; the warning
        // naming the skipped row goes to stderr, where a caller can separate it
        $this->assertStringContainsString('1 row(s) rewritten', $output);
    }

    /**
     * `_` is a LIKE wildcard, so an unescaped pattern would also select bodies
     * that merely look like the marker at that offset -- and those are not
     * enveloped, so decoding them would be a no-op that still counted.
     */
    public function testTheMarkerIsMatchedLiterally(): void {
        $lookalike = 'XYSERIALIZED:whatever';
        R::exec('INSERT INTO msgqueue (id, sv_httpdata) VALUES (1, ?)', [$lookalike]);

        $output = $this->normalizeWith();

        $this->assertSame($lookalike, R::getCell('SELECT sv_httpdata FROM msgqueue WHERE id = 1'));
        $this->assertStringContainsString('no enveloped rows', $output);
    }

    public function testDryRunWritesNothing(): void {
        $stored = self::wrap(self::XML);
        R::exec('INSERT INTO msgqueue (id, sv_httpdata) VALUES (1, ?)', [$stored]);

        $output = $this->normalizeWith(['--dry-run']);

        $this->assertSame($stored, R::getCell('SELECT sv_httpdata FROM msgqueue WHERE id = 1'));
        $this->assertStringContainsString('would be rewritten', $output);
    }

    /**
     * The batch loop walks by id, since each pass removes rows from the set the
     * WHERE clause matches -- an offset would step over the ones that moved
     * down into its place. More rows than one batch holds is the case that
     * would show it.
     */
    public function testEveryRowIsReachedAcrossBatches(): void {
        for ($id = 1; $id <= 1200; $id++) {
            R::exec('INSERT INTO msgqueue (id, sv_httpdata) VALUES (?, ?)', [$id, self::wrap("body {$id}")]);
        }

        $this->normalizeWith();

        $left = (int) R::getCell("SELECT COUNT(*) FROM msgqueue WHERE sv_httpdata LIKE '!_!_SERIALIZED:%' ESCAPE '!'");
        $this->assertSame(0, $left, 'rows were skipped between batches');
        $this->assertSame('body 1200', R::getCell('SELECT sv_httpdata FROM msgqueue WHERE id = 1200'));
    }
}

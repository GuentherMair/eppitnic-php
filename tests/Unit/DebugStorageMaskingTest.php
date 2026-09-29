<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Session;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * Under debug the raw exchange is stored, but passwords and authinfo are
 * masked there as in the debug file.
 */
final class DebugStorageMaskingTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['transactions', 'responses'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, cl_trid TEXT, cl_trtype TEXT, cl_trobject TEXT, cl_trdata TEXT)');
        R::exec('CREATE TABLE responses (id INTEGER PRIMARY KEY, cl_trid TEXT, sv_trid TEXT, sv_code TEXT, status INTEGER,
                 sv_httpcode INTEGER, sv_httpheaders TEXT, sv_httpdata TEXT, extvaluereasoncode TEXT, extvaluereason TEXT)');
    }

    public function testTheStoredLoginRequestHidesBothPasswords(): void {
        $this->nic->debug = true;
        $this->nic->EPPCfg->password = 'old-secret-pw';
        $this->transport->queue(CommandCatalog::LOGIN_RESPONSE);

        (new Session($this->nic))->login('new-secret-pw');

        $stored = (string) R::getCell('SELECT cl_trdata FROM transactions');
        $this->assertStringContainsString('***', $stored);
        $this->assertStringNotContainsString('old-secret-pw', $stored);
        $this->assertStringNotContainsString('new-secret-pw', $stored);
    }

    public function testTheStoredResponseHidesAuthinfo(): void {
        $this->nic->debug = true;
        $response = str_replace(
            '<trID>',
            '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:authInfo>'
                . '<domain:pw>authinfo-secret</domain:pw></domain:authInfo></domain:infData></resData><trID>',
            CommandCatalog::LOGIN_RESPONSE
        );
        $this->transport->queue($response);

        (new Session($this->nic))->login();

        $stored = (string) R::getCell('SELECT sv_httpdata FROM responses');
        $this->assertStringContainsString('***', $stored);
        $this->assertStringNotContainsString('authinfo-secret', $stored);
    }
}

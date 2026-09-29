<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The export keeps a domain whose registrant is not stored locally, and the
 * autocomplete takes % and _ in its term literally.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DomainListQueriesTest extends TestCase
{
    private \Slim\App $app;

    protected function setUp(): void {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'contacts', 'transfers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, registrant TEXT,
                 active INTEGER DEFAULT 1, authinfo TEXT, cr_date TEXT, ex_date TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, org TEXT, name TEXT, email TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER,
                 status TEXT NOT NULL DEFAULT 'pending')");
        R::exec("INSERT INTO contacts (handle, name, email) VALUES ('KNOWN', 'Mario', 'm@example.it')");
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES
                 ('with-contact.it', 2, 'KNOWN'), ('without-contact.it', 2, 'ELSEWHERE'),
                 ('a_b.it', 2, 'KNOWN'), ('axb.it', 2, 'KNOWN'), ('100%.it', 2, 'KNOWN'), ('1000.it', 2, 'KNOWN')");
        R::exec("INSERT INTO transfers (domain, reseller_id, status) VALUES ('in.it', 2, 'pending'), ('back.it', 2, 'cancelled')");

        $this->app = AppFactory::create();
        Middleware::register($this->app);
        $app = $this->app;
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';
    }

    private function get(string $path): ResponseInterface {
        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
        );
    }

    /** @return string[] */
    private function autocomplete(string $term): array {
        return json_decode((string) $this->get('/v1/domains/autocomplete?term=' . urlencode($term))->getBody(), true)['domains'];
    }

    public function testTheExportKeepsADomainWhoseRegistrantIsNotStoredLocally(): void {
        $csv = (string) $this->get('/v1/domains/export')->getBody();

        $this->assertStringContainsString('with-contact.it', $csv);
        $this->assertStringContainsString('without-contact.it', $csv);
    }

    public function testUnderscoreAndPercentAreLiteralInTheTerm(): void {
        $this->assertSame(['a_b.it'], $this->autocomplete('a_b'));
        $this->assertSame(['100%.it'], $this->autocomplete('100%'));
        $this->assertSame([], $this->autocomplete('%%'));
    }
}

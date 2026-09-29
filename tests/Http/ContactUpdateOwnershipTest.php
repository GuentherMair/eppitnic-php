<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A reseller reads a contact merely attached to one of its domains, but only
 * the owner may change it.
 *
 * Own process: contact.php declares functions, so it loads once per process.
 */
#[RunClassInSeparateProcess]
final class ContactUpdateOwnershipTest extends TestCase
{
    protected function setUp(): void {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'trusted_proxies' => [],
            'remote_auth'     => ['enabled' => false, 'header' => null],
        ]);
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'domains', 'contacts'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, reseller_id INTEGER, handle TEXT UNIQUE, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 domain TEXT UNIQUE, registrant TEXT, admin TEXT, tech TEXT)');
        TestAccounts::ensure(2, 'user', 2);
        TestAccounts::ensure(3, 'user', 3);
        // reseller 3 owns the tech contact, reseller 2's domain uses it
        R::exec("INSERT INTO contacts (reseller_id, handle) VALUES (3, 'TECH-SHARED')");
        R::exec("INSERT INTO domains (reseller_id, domain, tech) VALUES (2, 'two.it', ?)", [
            serialize(['TECH-SHARED' => 'TECH-SHARED']),
        ]);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    private function patch(int $userId, string $handle): ResponseInterface {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/contact.php';

        $token = TestAccounts::issueToken(['id' => $userId, 'max_token_age' => 60, 'reseller_id' => $userId])['token'];
        $request = (new ServerRequestFactory())->createServerRequest('PATCH', "http://localhost/v1/contacts/{$handle}")
            ->withParsedBody(['email' => 'new@example.it'])
            ->withHeader('Authorization', "Bearer {$token}");
        return $app->handle($request);
    }

    public function testAResellerCannotUpdateAContactOnlyAttachedToItsDomain(): void {
        // 403 comes before any registry session: a fetch from here would
        // need the network
        $response = $this->patch(2, 'TECH-SHARED');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'You are not authorized to update this contact',
            json_decode((string) $response->getBody(), true)['error']
        );
    }

    public function testTheSameResellerMayStillReadIt(): void {
        $this->assertTrue(\Eppitnic\Api\Access::canAccessContact('TECH-SHARED', new \Eppitnic\Persistence\Scope(2, 2, 'user')));
        $this->assertFalse(\Eppitnic\Api\Access::ownsContact('TECH-SHARED', new \Eppitnic\Persistence\Scope(2, 2, 'user')));
        $this->assertTrue(\Eppitnic\Api\Access::ownsContact('TECH-SHARED', new \Eppitnic\Persistence\Scope(3, 3, 'user')));
    }
}

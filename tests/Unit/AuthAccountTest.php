<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Api\Auth;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Every credential is checked against the account as it stands now: an
 * inactive user or reseller is refused at once, and the role comes from the
 * database, not from what the token remembers.
 */
final class AuthAccountTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS users');
        R::exec('DROP TABLE IF EXISTS resellers');
        TestAccounts::ensureReseller(2, 'Two');
        TestAccounts::ensureReseller(3, 'Three');
        TestAccounts::ensure(1, 'admin', 1);
        TestAccounts::ensure(2, 'manager', 2);
        TestAccounts::ensure(3, 'user', 2);
        TestAccounts::ensure(4, 'user', 3);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    private function request(int $id, string $role = 'user', bool $totpPending = false): Request {
        $token = Auth::issueToken([
            'id' => $id, 'username' => "user{$id}", 'role' => $role,
            'has_totp' => $totpPending, 'totp_verified' => ! $totpPending, 'max_token_age' => 60,
        ])['token'];
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/users/me')
            ->withHeader('Authorization', "Bearer {$token}");
    }

    public function testTheRoleAndResellerComeFromTheDatabase(): void {
        // the token claims admin; the database says plain user of reseller 2
        $actor = Auth::actor($this->request(3, 'admin'));

        $this->assertSame('user', $actor['role']);
        $this->assertFalse($actor['isAdmin']);
        $this->assertSame(2, $actor['resellerId']);
        $this->assertSame('Two', Auth::verify($this->request(3))->data->reseller_name);
    }

    public function testADeactivatedUserIsRefusedAtOnce(): void {
        R::exec('UPDATE users SET active = 0 WHERE id = 3');

        $this->expectException(HttpForbiddenException::class);
        $this->expectExceptionMessage('Your account is deactivated');
        Auth::verify($this->request(3));
    }

    public function testADeactivatedResellerRefusesAllItsUsers(): void {
        R::exec('UPDATE resellers SET active = 0 WHERE id = 2');

        foreach ([2, 3] as $id) {
            try {
                Auth::verify($this->request($id));
                $this->fail("user {$id} got in");
            } catch (HttpForbiddenException $e) {
                $this->assertSame('Your reseller account is deactivated', $e->getMessage());
            }
        }
        $this->assertSame(4, Auth::actor($this->request(4))['id'], 'another reseller is unaffected');
    }

    public function testAUserThatNoLongerExistsIsUnauthenticated(): void {
        $this->expectException(HttpUnauthorizedException::class);
        Auth::verify($this->request(99));
    }

    public function testAManagerMayActForTheirOwnResellersUser(): void {
        $this->assertSame(2, Auth::actorFor($this->request(2, 'manager'), 3)['id']);
    }

    public function testAManagerMayNotActForAnotherResellersUser(): void {
        $this->expectException(HttpForbiddenException::class);
        Auth::actorFor($this->request(2, 'manager'), 4);
    }

    public function testAManagerMayNotActForAnAdmin(): void {
        R::exec("UPDATE users SET reseller_id = 1 WHERE id = 2");

        $this->expectException(HttpForbiddenException::class);
        Auth::actorFor($this->request(2, 'manager'), 1);
    }

    public function testAManagerActingForSomeoneElseNeedsMfa(): void {
        $this->expectException(HttpForbiddenException::class);
        $this->expectExceptionMessage('MFA verification required');
        Auth::actorFor($this->request(2, 'manager', true), 3);
    }

    public function testAPlainUserMayOnlyActForThemselves(): void {
        $this->assertSame(3, Auth::actorFor($this->request(3), 3)['id']);

        $this->expectException(HttpForbiddenException::class);
        Auth::actorFor($this->request(3), 2);
    }

    public function testRequireManagerRefusesAPlainUser(): void {
        $this->assertTrue(Auth::requireManager($this->request(2, 'manager'))['isManager']);

        $this->expectException(HttpForbiddenException::class);
        Auth::requireManager($this->request(3));
    }
}

<?php

namespace Eppitnic\Api;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Eppitnic\Config;
use Eppitnic\Persistence\Scope;
use OTPHP\TOTP;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Who the caller is, and whether they may do this. Three credentials reach the
 * same place -- a JWT, a hashed automation token synthesized into the same
 * claims shape, and TOTP on top of either -- and downstream reads one object.
 *
 * @category    Net
 * @package     Eppitnic\Api\Auth
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Auth
{
    /** @var \WeakMap<Request, object>|null verify()'s result, once per request */
    private static ?\WeakMap $verified = null;

    /**
     * The authenticated caller, as every route needs them. Just these fields: a
     * route wanting more of the token calls verify() directly, so carrying it
     * here too would be a second way to reach the same thing.
     *
     * @param Request $request the incoming HTTP request
     * @return array{id: int, role: string, isAdmin: bool, isManager: bool, resellerId: int, debug: bool, scope: Scope}
     * @throws HttpUnauthorizedException if the request carries no usable
     *                       credential
     */
    public static function actor(Request $request): array {
        $decoded = self::verify($request);
        $scope = new Scope((int) $decoded->data->id, (int) $decoded->data->reseller_id, (string) $decoded->data->role);
        return [
            'id'         => $scope->userId,
            'role'       => $scope->role,
            'isAdmin'    => $scope->isAdmin(),
            'isManager'  => $scope->isManager(),
            'resellerId' => $scope->resellerId,
            'debug'      => ! empty($decoded->data->debug),
            'scope'      => $scope,
        ];
    }

    // -----------------------------------------------------------------
    // JWT / fixed API token auth
    // -----------------------------------------------------------------

    /**
     * Match $token against users.api_token and synthesize the claims object
     * JWT::decode() would return, so downstream works the same either way.
     * Null lets callers fall through. MFA is bypassed: no human is present.
     *
     * @param string $token the raw bearer token from the Authorization header
     * @return object|null synthesized decoded-claims object, or null if not a
     *                     valid fixed token
     */
    private static function verifyFixedApiToken(string $token): ?object {
        $user = R::getRow("
            SELECT id, username
            FROM users
            WHERE api_token = :token AND active = 1
              AND (api_token_expires = 0 OR api_token_expires > UNIX_TIMESTAMP())
        ", [':token' => hash('sha256', $token)]);

        if (empty($user)) {
            return null;
        }

        return (object) [
            'data' => (object) [
                'id'            => (int) $user['id'],
                'username'      => $user['username'],
                'has_totp'      => false,
                'needs_totp'    => false,
                'totp_verified' => false,
            ],
        ];
    }

    // -----------------------------------------------------------------
    // remote auth (front server / trusted proxy)
    // -----------------------------------------------------------------

    /**
     * The `remote_auth` setting, or disabled if it was never seeded -- same
     * reasoning as ClientIp::trustedProxies() treating a missing key as
     * empty.
     */
    private static function remoteAuthSettings(): array {
        try {
            return (array) Config::get('remote_auth');
        } catch (\Throwable) {
            return ['enabled' => false, 'header' => null];
        }
    }

    /**
     * @return bool whether $request's Authorization header uses the Bearer
     *              scheme -- if so, it always takes the JWT/fixed-token path,
     *              remote auth or not
     */
    private static function isBearerAuth(Request $request): bool {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '') {
            return false;
        }
        return strcasecmp(explode(' ', $authHeader, 2)[0], 'Bearer') === 0;
    }

    /**
     * The remote username server mode or header mode resolved, if any.
     * Header mode never falls back to REMOTE_USER, and only ever reads the
     * header from a `trusted_proxies` peer -- everyone else is ignored.
     *
     * @param array $remoteAuth the `remote_auth` setting
     * @return string|null the trimmed username, or null if none was found
     */
    private static function resolveRemoteUsername(Request $request, array $remoteAuth): ?string {
        $serverParams = $request->getServerParams();
        $header = $remoteAuth['header'] ?? null;

        if ($header !== null && $header !== '') {
            $peer = (string) ($serverParams['REMOTE_ADDR'] ?? '');
            if ($peer === '' || ! ClientIp::isTrustedProxy($peer)) {
                return null;
            }
            $username = trim($request->getHeaderLine($header));
            return $username !== '' ? $username : null;
        }

        $username = trim((string) (
            $serverParams['REMOTE_USER'] ?? $serverParams['REDIRECT_REMOTE_USER'] ?? ''
        ));
        return $username !== '' ? $username : null;
    }

    /**
     * Match $username against a local user and synthesize the claims object
     * JWT::decode() would return -- same shape as verifyFixedApiToken(), plus
     * `remote_auth: true`. MFA is the front server's job, so has_totp is
     * always false here. An inactive account is withAccount()'s to refuse.
     *
     * @throws HttpForbiddenException if $username has no local account
     */
    private static function verifyRemoteUser(string $username, Request $request): object {
        $user = R::getRow("
            SELECT id, username, debug, max_token_age, max_idle_time
            FROM users
            WHERE username = :username
        ", [':username' => $username]);

        if (empty($user)) {
            throw new HttpForbiddenException($request, "Remote user '{$username}' has no active account");
        }

        return (object) [
            'data' => (object) [
                'id'            => (int) $user['id'],
                'username'      => $user['username'],
                'has_totp'      => false,
                'needs_totp'    => false,
                'totp_verified' => false,
                'debug'         => (bool) $user['debug'],
                'max_token_age' => $user['max_token_age'],
                'max_idle_time' => $user['max_idle_time'],
                'remote_auth'   => true,
            ],
        ];
    }

    /**
     * validate the Authorization header: a real JWT (session login), falling
     * back to a fixed automation token (see verifyFixedApiToken()) if JWT
     * decoding fails for any reason. When `remote_auth.enabled` is true and
     * the request carries no Bearer token, a front server's REMOTE_USER (or
     * a trusted proxy's header) is tried first instead.
     *
     * @param Request $request the incoming HTTP request
     * @return object decoded claims, shaped identically regardless of which
     *                auth mechanism matched
     * @throws HttpForbiddenException if a remote username was found but maps
     *                       to no active local user
     * @throws HttpUnauthorizedException if neither a valid JWT nor a valid
     *                       fixed token is found
     */
    public static function verify(Request $request): object {
        self::$verified ??= new \WeakMap();
        return self::$verified[$request] ??= self::withAccount(self::credential($request), $request);
    }

    /**
     * The account as it stands now, not as the token remembers it: a
     * deactivated user or reseller loses access at once, and a role change
     * applies from the next request. Overwrites whatever the token claimed.
     *
     * @throws HttpUnauthorizedException if the user no longer exists
     * @throws HttpForbiddenException if the user or their reseller is inactive
     */
    private static function withAccount(object $decoded, Request $request): object {
        $account = R::getRow('
            SELECT u.active, u.role, u.reseller_id, r.name AS reseller_name, r.active AS reseller_active
            FROM users u JOIN resellers r ON r.id = u.reseller_id
            WHERE u.id = ?
        ', [(int) $decoded->data->id]);

        if (empty($account)) {
            throw new HttpUnauthorizedException($request, 'User not found');
        }
        if ((int) $account['active'] !== 1) {
            throw new HttpForbiddenException($request, 'Your account is deactivated');
        }
        if ((int) $account['reseller_active'] !== 1) {
            throw new HttpForbiddenException($request, 'Your reseller account is deactivated');
        }

        unset($decoded->data->admin);
        $decoded->data->role = $account['role'];
        $decoded->data->reseller_id = (int) $account['reseller_id'];
        $decoded->data->reseller_name = $account['reseller_name'];
        return $decoded;
    }

    private static function credential(Request $request): object {
        $remoteAuth = self::remoteAuthSettings();
        if ( ! empty($remoteAuth['enabled']) && ! self::isBearerAuth($request)) {
            $username = self::resolveRemoteUsername($request, $remoteAuth);
            if ($username !== null) {
                return self::verifyRemoteUser($username, $request);
            }
        }

        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            throw new HttpUnauthorizedException($request, 'Authorization token not set');
        }

        $parts = explode(' ', $authHeader);
        if (count($parts) !== 2 || empty($parts[1])) {
            throw new HttpUnauthorizedException($request, 'Authorization token not valid');
        }
        $token = $parts[1];

        // an automation token is opaque, not a signed triple, so it can only
        // fail JWT::decode() -- check the fixed-token store before giving up.
        // Costs nothing on the normal path
        try {
            return JWT::decode($token, new Key(Config::get('jwt_psk'), 'HS256'));
        } catch (SignatureInvalidException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Signature is not valid: ' . $e->getMessage());
        } catch (BeforeValidException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token is not yet valid: ' . $e->getMessage());
        } catch (ExpiredException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token has expired: ' . $e->getMessage());
        } catch (\UnexpectedValueException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token contains unexpected value: ' . $e->getMessage());
        } catch (\DomainException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token outside expected domain: ' . $e->getMessage());
        }
    }

    /**
     * @param Request $request the incoming HTTP request
     * @return int the authenticated user's id
     */
    public static function userId(Request $request): int {
        return (int) self::verify($request)->data->id;
    }

    /**
     * require that MFA, if enabled for this user, has already been verified
     * this session
     *
     * @param Request $request the incoming HTTP request
     * @return object decoded JWT claims
     * @throws HttpForbiddenException if MFA is enabled but not yet verified
     */
    public static function requireMfa(Request $request): object {
        $decoded = self::verify($request);
        if (!empty($decoded->data->has_totp) && empty($decoded->data->totp_verified)) {
            throw new HttpForbiddenException($request, 'MFA verification required');
        }
        return $decoded;
    }

    /**
     * require an admin-privileged, MFA-verified (if enabled) token
     *
     * @param Request $request the incoming HTTP request
     * @return int the authenticated admin's user id
     * @throws HttpForbiddenException if not an admin, or MFA is enabled but not
     *                       yet verified
     */
    public static function requireAdmin(Request $request): int {
        $decoded = self::verify($request);
        if ($decoded->data->role !== 'admin') {
            throw new HttpForbiddenException($request, 'Admin access required');
        }
        self::requireMfa($request);
        return (int) $decoded->data->id;
    }

    /**
     * require a manager or admin, MFA-verified (if enabled)
     *
     * @return array the caller, as actor() returns it
     * @throws HttpForbiddenException if a plain user, or MFA is enabled but not
     *                       yet verified
     */
    public static function requireManager(Request $request): array {
        $actor = self::actor($request);
        if ( ! $actor['isManager']) {
            throw new HttpForbiddenException($request, 'Manager access required');
        }
        self::requireMfa($request);
        return $actor;
    }

    /**
     * The caller, for something that belongs to user $userId: their own, an
     * admin's to reach into, or their manager's (same reseller, never an
     * admin). Acting for someone else needs a verified MFA too.
     *
     * @param Request $request the incoming HTTP request
     * @param int $userId whose data is being touched
     * @return array the caller, as actor() returns it
     * @throws HttpForbiddenException if it is someone else's and the caller may
     *                       not act for them
     */
    public static function actorFor(Request $request, int $userId): array {
        $actor = self::actor($request);
        if ($actor['id'] === $userId) {
            return $actor;
        }
        if ($actor['role'] === 'manager') {
            $target = R::getRow('SELECT reseller_id, role FROM users WHERE id = ?', [$userId]);
            if ( ! empty($target) && (int) $target['reseller_id'] === $actor['resellerId'] && $target['role'] !== 'admin') {
                self::requireMfa($request);
                return $actor;
            }
        }
        self::requireAdmin($request);
        return $actor;
    }

    /**
     * Sign a new JWT for $data, adding the standard claims. The lifetime comes
     * from $data['max_token_age'], in minutes, spelled like the users column;
     * null or non-positive means the default, since 0 would already be expired.
     *
     * @param array $data claims to embed (may include 'max_token_age', in
     *              minutes, defaulting to 240)
     * @return array $data merged with the signed 'token' string
     */
    public static function issueToken(array $data): array {
        $maxTokenAge = (int) ($data['max_token_age'] ?? 0);
        if ($maxTokenAge <= 0) {
            $maxTokenAge = 240;
        }

        $iat = time();
        $nbf = $iat - 60 * 5;
        $exp = $iat + 60 * $maxTokenAge;

        $data['exp'] = $exp;

        $payload = [
            'data' => $data,
            'iss'  => 'http://www.inet-services.it',
            'sub'  => 'invoicing API',
            'nbf'  => $nbf,
            'iat'  => $iat,
            'exp'  => $exp,
        ];

        return array_merge(
            ['token' => JWT::encode($payload, Config::get('jwt_psk'), 'HS256')],
            $data
        );
    }

    // -----------------------------------------------------------------
    // TOTP (MFA)
    // -----------------------------------------------------------------

    /**
     * generate a new TOTP secret + provisioning URI for a user enrolling in MFA
     *
     * @param string $username shown as the label in authenticator apps
     * @return array ['secret' => ..., 'uri' => ...]
     */
    public static function totpGenerate(string $username): array {
        // 20 bytes, per RFC 4226 section 4 and what authenticator apps expect.
        // otphp defaults to 64, whose base32 encoding overflows
        // users.totp_secret varchar(64) and is silently truncated on write
        $totp = TOTP::generate(null, 20);
        $totp->setLabel($username);
        $totp->setIssuer('inet-services.it');
        return [
            'secret' => $totp->getSecret(),
            'uri'    => $totp->getProvisioningUri(),
        ];
    }

    /**
     * @param string $secret the user's TOTP secret
     * @param string $code the 6-digit code to check
     * @return bool status
     */
    public static function totpVerify(string $secret, string $code): bool {
        try {
            // leeway in seconds; 29 = just under one 30s period, giving
            // practical ±1-window tolerance
            return TOTP::createFromSecret($secret)->verify($code, null, 29);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

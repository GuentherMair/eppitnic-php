<?php

namespace Eppitnic\Api;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Eppitnic\Config;
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
    /**
     * The authenticated caller, as every route needs them. Just these fields: a
     * route wanting more of the token calls verify() directly, so carrying it
     * here too would be a second way to reach the same thing.
     *
     * @param Request $request the incoming HTTP request
     * @return array{id: int, isAdmin: bool, debug: bool}
     * @throws HttpUnauthorizedException if the request carries no usable credential
     */
    public static function actor(Request $request): array {
        $decoded = self::verify($request);
        return [
            'id'      => (int) $decoded->data->id,
            'isAdmin' => (int) $decoded->data->admin === 1,
            'debug'   => ! empty($decoded->data->debug),
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
     * @return object|null synthesized decoded-claims object, or null if not a valid fixed token
     */
    private static function verifyFixedApiToken(string $token): ?object {
        $user = R::getRow("
            SELECT id, admin, username
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
                'admin'         => (int) $user['admin'],
                'username'      => $user['username'],
                'has_totp'      => false,
                'needs_totp'    => false,
                'totp_verified' => false,
            ],
        ];
    }

    /**
     * validate the Authorization header: a real JWT (session login), falling
     * back to a fixed automation token (see verifyFixedApiToken()) if JWT
     * decoding fails for any reason
     *
     * @param Request $request the incoming HTTP request
     * @return object decoded claims, shaped identically regardless of which auth mechanism matched
     * @throws HttpUnauthorizedException if neither a valid JWT nor a valid fixed token is found
     */
    public static function verify(Request $request): object {
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
     * require that MFA, if enabled for this user, has already been verified this session
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
     * @throws HttpForbiddenException if not an admin, or MFA is enabled but not yet verified
     */
    public static function requireAdmin(Request $request): int {
        $decoded = self::verify($request);
        if ((int) $decoded->data->admin !== 1) {
            throw new HttpForbiddenException($request, 'Admin access required');
        }
        if (!empty($decoded->data->has_totp) && empty($decoded->data->totp_verified)) {
            throw new HttpForbiddenException($request, 'MFA verification required');
        }
        return (int) $decoded->data->id;
    }

    /**
     * Sign a new JWT for $data, adding the standard claims. The lifetime comes
     * from $data['max_token_age'], in minutes, spelled like the users column;
     * null or non-positive means the default, since 0 would already be expired.
     *
     * @param array $data claims to embed (may include 'max_token_age', in minutes, defaulting to 240)
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
            // leeway in seconds; 29 = just under one 30s period, giving practical ±1-window tolerance
            return TOTP::createFromSecret($secret)->verify($code, null, 29);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

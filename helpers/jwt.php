<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\SignatureInvalidException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Exception\HttpForbiddenException;
use RedBeanPHP\R;

/**
 * check whether $token matches a stored fixed automation token (users.api_token,
 * hashed at rest) and, if so, synthesize a decoded-claims object shaped exactly
 * like what JWT::decode() would return -- so every downstream consumer
 * (jwtUserID/jwtRequireAdmin/jwtRequireMfa, and every route reading
 * $decoded->data->id / ->admin / etc.) works identically regardless of which
 * auth mechanism was actually used. Returns null if $token isn't a valid,
 * unexpired fixed token, so callers can fall through to their normal failure
 * path unchanged.
 *
 * Automation tokens bypass MFA entirely (has_totp forced false) -- there's no
 * human present to enter a TOTP code in a headless/scripted context.
 */
function verifyFixedApiToken(string $token): ?object {
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

function jwtVerify(Request $request): object {
    $authHeader = $request->getHeaderLine('Authorization');

    if (empty($authHeader)) {
        throw new HttpUnauthorizedException($request, 'Authorization token not set');
    }

    $parts = explode(' ', $authHeader);
    if (count($parts) !== 2 || empty($parts[1])) {
        throw new HttpUnauthorizedException($request, 'Authorization token not valid');
    }
    $token = $parts[1];

    // a fixed automation token never round-trips through JWT::decode() successfully
    // (it's an opaque random string, not a signed header.payload.signature triple),
    // so on any decode failure below, check it against the fixed-token store before
    // giving up -- this only adds work on the failure path, never the normal one
    try {
        return JWT::decode($token, new Key(getConfig('jwt_psk'), 'HS256'));
    } catch (SignatureInvalidException $e) {
        if ($fixed = verifyFixedApiToken($token)) return $fixed;
        throw new HttpUnauthorizedException($request, 'Signature is not valid: ' . $e->getMessage());
    } catch (BeforeValidException $e) {
        if ($fixed = verifyFixedApiToken($token)) return $fixed;
        throw new HttpUnauthorizedException($request, 'Token is not yet valid: ' . $e->getMessage());
    } catch (ExpiredException $e) {
        if ($fixed = verifyFixedApiToken($token)) return $fixed;
        throw new HttpUnauthorizedException($request, 'Token has expired: ' . $e->getMessage());
    } catch (\UnexpectedValueException $e) {
        if ($fixed = verifyFixedApiToken($token)) return $fixed;
        throw new HttpUnauthorizedException($request, 'Token contains unexpected value: ' . $e->getMessage());
    } catch (\DomainException $e) {
        if ($fixed = verifyFixedApiToken($token)) return $fixed;
        throw new HttpUnauthorizedException($request, 'Token outside expected domain: ' . $e->getMessage());
    }
}

function jwtUserID(Request $request): int {
    return (int) jwtVerify($request)->data->id;
}

function jwtRequireMfa(Request $request): object {
    $decoded = jwtVerify($request);
    if (!empty($decoded->data->has_totp) && empty($decoded->data->totp_verified)) {
        throw new HttpForbiddenException($request, 'MFA verification required');
    }
    return $decoded;
}

function jwtRequireAdmin(Request $request): int {
    $decoded = jwtVerify($request);
    if ((int) $decoded->data->admin !== 1) {
        throw new HttpForbiddenException($request, 'Admin access required');
    }
    if (!empty($decoded->data->has_totp) && empty($decoded->data->totp_verified)) {
        throw new HttpForbiddenException($request, 'MFA verification required');
    }
    return (int) $decoded->data->id;
}

function jwtBuild(array $data): array {
    $maxTokenAge = isset($data['maxTokenAge']) ? (int) $data['maxTokenAge'] : 240;

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
        ['token' => JWT::encode($payload, getConfig('jwt_psk'), 'HS256')],
        $data
    );
}

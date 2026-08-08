<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\SignatureInvalidException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Exception\HttpForbiddenException;

function jwtVerify(Request $request): object {
    $authHeader = $request->getHeaderLine('Authorization');

    if (empty($authHeader)) {
        throw new HttpUnauthorizedException($request, 'Authorization token not set');
    }

    $parts = explode(' ', $authHeader);
    if (count($parts) !== 2 || empty($parts[1])) {
        throw new HttpUnauthorizedException($request, 'Authorization token not valid');
    }

    try {
        return JWT::decode($parts[1], new Key(getConfig('jwt_psk'), 'HS256'));
    } catch (SignatureInvalidException $e) {
        throw new HttpUnauthorizedException($request, 'Signature is not valid: ' . $e->getMessage());
    } catch (BeforeValidException $e) {
        throw new HttpUnauthorizedException($request, 'Token is not yet valid: ' . $e->getMessage());
    } catch (ExpiredException $e) {
        throw new HttpUnauthorizedException($request, 'Token has expired: ' . $e->getMessage());
    } catch (\UnexpectedValueException $e) {
        throw new HttpUnauthorizedException($request, 'Token contains unexpected value: ' . $e->getMessage());
    } catch (\DomainException $e) {
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

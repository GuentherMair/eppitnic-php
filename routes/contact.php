<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

require_once dirname(__FILE__).'/../Net/EPP/IT/Contact.php';

/**
 * serialize a Net_EPP_IT_Contact's relevant fields for a JSON response
 */
function contactToArray(Net_EPP_IT_Contact $contact): array {
    return [
        'handle'                => $contact->get('handle'),
        'status'                => $contact->get('status'),
        'name'                  => $contact->get('name'),
        'org'                   => $contact->get('org'),
        'street'                => $contact->get('street'),
        'street2'               => $contact->get('street2'),
        'street3'               => $contact->get('street3'),
        'city'                  => $contact->get('city'),
        'province'              => $contact->get('province'),
        'postalcode'            => $contact->get('postalcode'),
        'countrycode'           => $contact->get('countrycode'),
        'voice'                 => $contact->get('voice'),
        'fax'                   => $contact->get('fax'),
        'email'                 => $contact->get('email'),
        'authinfo'              => $contact->get('authinfo'),
        'consentforpublishing'  => $contact->get('consentforpublishing'),
        'nationalitycode'       => $contact->get('nationalitycode'),
        'entitytype'            => $contact->get('entitytype'),
        'regcode'               => $contact->get('regcode'),
        'schoolcode'            => $contact->get('schoolcode'),
    ];
}

/**
 * a reseller may access a contact they don't directly own if it's attached
 * (as registrant, admin, or tech) to at least one domain they DO own
 */
function canAccessContact(string $handle, int $user_id, bool $isAdmin): bool {
    if ($isAdmin) {
        return true;
    }
    $owns = (int) R::getCell("SELECT COUNT(*) FROM contacts WHERE handle = ? AND user_id = ?", [$handle, $user_id]);
    if ($owns > 0) {
        return true;
    }
    $attached = (int) R::getCell("
        SELECT COUNT(*) FROM domains
        WHERE user_id = ? AND (registrant = ? OR admin = ? OR tech LIKE ?)
    ", [$user_id, $handle, $handle, '%"' . $handle . '"%']);
    return $attached > 0;
}

$app->get('/v1/contacts', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $params  = $request->getQueryParams();

    $nic = new Net_EPP_Client();
    $contact = new Net_EPP_IT_Contact($nic);
    $contacts = $contact->listContacts($user_id, $isAdmin, ($params['active'] ?? '1') !== '0');

    $response->getBody()->write(json_encode(['contacts' => $contacts]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $handle = $args['handle'];

    if ( ! canAccessContact($handle, $user_id, $isAdmin)) {
        $response->getBody()->write(json_encode(['error' => 'You are not authorized to view this contact']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $contact = withEppSession(function ($nic) use ($handle, $user_id) {
            $contact = new Net_EPP_IT_Contact($nic);
            if ( ! $contact->fetch($handle)) {
                return null;
            }
            $contact->loadDB($handle, $user_id, true);
            return $contact;
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ($contact === null) {
        $response->getBody()->write(json_encode(['error' => "Contact '{$handle}' not found"]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['contact' => contactToArray($contact)]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/contacts', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $params = $request->getParsedBody() ?? [];

    if ($err = requireFields($params, ['name']) ?? maxLength($params, CONTACT_FIELD_MAX_LENGTHS)) {
        $response->getBody()->write(json_encode(['error' => $err]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    if ( ! empty($params['email']) && ! isValidEmailFormat($params['email'])) {
        $response->getBody()->write(json_encode(['error' => 'email is not a valid address']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $result = withEppSession(function ($nic) use ($params, $user_id) {
            $contact = new Net_EPP_IT_Contact($nic);
            foreach ($params as $key => $value) {
                if ($key === 'handle') {
                    continue; // handled explicitly below
                }
                if (in_array($key, ['name', 'org', 'street', 'street2', 'street3', 'city', 'province', 'postalcode', 'countrycode', 'voice', 'fax', 'email', 'authinfo', 'nationalitycode', 'entitytype', 'regcode', 'schoolcode'])) {
                    $contact->set($key, $value);
                }
            }
            $contact->set('handle', empty($params['handle']) ? generateContactHandle(new Net_EPP_IT_Contact($nic)) : $params['handle']);
            if (empty($params['authinfo'])) {
                $contact->set('authinfo', substr(md5(rand()), 0, 16));
            }
            if ( ! $contact->create()) {
                return ['ok' => false, 'error' => $contact->getError()];
            }
            $contact->storeDB($user_id);
            return ['ok' => true, 'contact' => $contact];
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['contact' => contactToArray($result['contact'])]));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->patch('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $handle = $args['handle'];
    $params = $request->getParsedBody() ?? [];

    if ( ! canAccessContact($handle, $user_id, $isAdmin)) {
        $response->getBody()->write(json_encode(['error' => 'You are not authorized to update this contact']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    if ($err = maxLength($params, CONTACT_FIELD_MAX_LENGTHS)) {
        $response->getBody()->write(json_encode(['error' => $err]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    if ( ! empty($params['email']) && ! isValidEmailFormat($params['email'])) {
        $response->getBody()->write(json_encode(['error' => 'email is not a valid address']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $result = withEppSession(function ($nic) use ($handle, $params, $user_id, $isAdmin) {
            $contact = new Net_EPP_IT_Contact($nic);
            if ( ! $contact->fetch($handle)) {
                return ['ok' => false, 'status' => 404, 'error' => "Contact '{$handle}' not found"];
            }
            foreach ($params as $key => $value) {
                if (in_array($key, ['name', 'org', 'street', 'street2', 'street3', 'city', 'province', 'postalcode', 'countrycode', 'voice', 'fax', 'email', 'authinfo', 'nationalitycode', 'entitytype', 'regcode', 'schoolcode'])) {
                    $contact->set($key, $value);
                }
            }
            if ( ! $contact->update()) {
                return ['ok' => false, 'status' => 400, 'error' => $contact->getError()];
            }
            $contact->updateDB($handle, $user_id, $isAdmin);
            return ['ok' => true, 'contact' => $contact];
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus($result['status'])->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['contact' => contactToArray($result['contact'])]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->delete('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $handle = $args['handle'];

    try {
        $result = withEppSession(function ($nic) use ($handle, $user_id, $isAdmin) {
            $contact = new Net_EPP_IT_Contact($nic);
            if ( ! $contact->delete($handle)) {
                return ['ok' => false, 'error' => $contact->getError()];
            }
            $contact->deleteContactDB($handle, $user_id, $isAdmin);
            return ['ok' => true];
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['deleted' => true, 'handle' => $handle]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

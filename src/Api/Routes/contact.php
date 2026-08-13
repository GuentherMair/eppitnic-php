<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Service\EppSession;
use Eppitnic\Support\Validate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * serialize a Contact's relevant fields for a JSON response
 */
function contactToArray(Contact $contact): array {
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
    ['id' => $user_id, 'isAdmin' => $isAdmin] = Auth::actor($request);
    $params  = $request->getQueryParams();

    $nic = new Client();
    $contact = new Contact($nic);
    $contacts = $contact->listContacts($user_id, $isAdmin, ($params['active'] ?? '1') !== '0');

    return Json::response($response, ['contacts' => $contacts]);
});

$app->get('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Auth::actor($request);
    $handle = $args['handle'];

    if ( ! canAccessContact($handle, $user_id, $isAdmin)) {
        return Json::response($response, ['error' => 'You are not authorized to view this contact'], 403);
    }

    // the registry is authoritative -- its answer is returned as-is, never
    // overlaid with the local row (overlaying is what used to blank the whole
    // object out, since loadDB() re-initializes before its own lookup and
    // leaves it empty when that lookup misses). Mirrors GET /v1/domains/{name}.
    try {
        $contact = EppSession::run(function ($nic) use ($handle) {
            $contact = new Contact($nic);
            return $contact->fetch($handle) ? $contact : null;
        }, $debug);
    } catch (\RuntimeException $e) {
        // registry unreachable -- indistinguishable from "not found" as far as
        // this route is concerned, both fall through to the local fallback
        $contact = null;
    }

    if ($contact !== null) {
        return Json::response($response, ['contact' => contactToArray($contact), 'stale' => false]);
    }

    // registry lookup failed: serve the last known local state instead, flagged
    // as potentially out of date. The ACL argument stays TRUE deliberately --
    // canAccessContact() above has already authorized this caller, including the
    // attached-to-a-domain-I-own case where the contact is owned by somebody
    // else, and scoping the fallback by user_id would 404 exactly those.
    // (via a variable: Contact::__construct() takes its Client by reference)
    $nic = new Client();
    $contact = new Contact($nic);
    if ( ! $contact->loadDB($handle, $user_id, true)) {
        return Json::response($response, ['error' => "Contact '{$handle}' not found"], 404);
    }

    return Json::response($response, ['contact' => contactToArray($contact), 'stale' => true]);
});

$app->post('/v1/contacts', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'debug' => $debug] = Auth::actor($request);
    $params = $request->getParsedBody() ?? [];

    if ($err = Validate::requireFields($params, ['name']) ?? Validate::maxLength($params, Validate::CONTACT_FIELD_MAX_LENGTHS)) {
        return Json::response($response, ['error' => $err], 400);
    }
    if ( ! empty($params['email']) && ! Validate::isEmail($params['email'])) {
        return Json::response($response, ['error' => 'email is not a valid address'], 400);
    }

    try {
        $result = EppSession::run(function ($nic) use ($params, $user_id) {
            $contact = new Contact($nic);
            foreach ($params as $key => $value) {
                if ($key === 'handle') {
                    continue; // handled explicitly below
                }
                if (in_array($key, ['name', 'org', 'street', 'street2', 'street3', 'city', 'province', 'postalcode', 'countrycode', 'voice', 'fax', 'email', 'authinfo', 'nationalitycode', 'entitytype', 'regcode', 'schoolcode'])) {
                    $contact->set($key, $value);
                }
            }
            $contact->set('handle', empty($params['handle']) ? $contact->generateHandle() : $params['handle']);
            if (empty($params['authinfo'])) {
                $contact->set('authinfo', $contact->authinfo());
            }
            if ( ! $contact->create()) {
                return ['ok' => false, 'error' => $contact->getError()];
            }
            $contact->storeDB($user_id);
            return ['ok' => true, 'contact' => $contact];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    return Json::response($response, ['contact' => contactToArray($result['contact'])], 201);
});

$app->patch('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Auth::actor($request);
    $handle = $args['handle'];
    $params = $request->getParsedBody() ?? [];

    if ( ! canAccessContact($handle, $user_id, $isAdmin)) {
        return Json::response($response, ['error' => 'You are not authorized to update this contact'], 403);
    }
    if ($err = Validate::maxLength($params, Validate::CONTACT_FIELD_MAX_LENGTHS)) {
        return Json::response($response, ['error' => $err], 400);
    }
    if ( ! empty($params['email']) && ! Validate::isEmail($params['email'])) {
        return Json::response($response, ['error' => 'email is not a valid address'], 400);
    }

    try {
        $result = EppSession::run(function ($nic) use ($handle, $params, $user_id, $isAdmin) {
            $contact = new Contact($nic);
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
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    return Json::response($response, ['contact' => contactToArray($result['contact'])]);
});

$app->delete('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Auth::actor($request);
    $handle = $args['handle'];

    try {
        $result = EppSession::run(function ($nic) use ($handle, $user_id, $isAdmin) {
            $contact = new Contact($nic);
            if ( ! $contact->delete($handle)) {
                return ['ok' => false, 'error' => $contact->getError()];
            }
            $contact->deleteContactDB($handle, $user_id, $isAdmin);
            return ['ok' => true];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    return Json::response($response, ['deleted' => true, 'handle' => $handle]);
});

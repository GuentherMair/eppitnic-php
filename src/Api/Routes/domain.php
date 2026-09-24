<?php

use Eppitnic\Api\Access;
use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Scope;
use Eppitnic\Persistence\SerializedColumn;
use Eppitnic\Service\DomainService;
use Eppitnic\Service\EppSession;
use Eppitnic\Support\Csv;
use Eppitnic\Support\Validate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * serialize a Domain's relevant fields for a JSON response
 */
function domainToArray(Domain $domain): array {
    return [
        'domain'     => $domain->get('domain'),
        'status'     => $domain->get('status'),
        'registrant' => $domain->get('registrant'),
        'admin'      => $domain->get('admin'),
        'tech'       => array_keys((array)$domain->get('tech')),
        'ns'         => array_keys((array)$domain->get('ns')),
        'authinfo'   => $domain->get('authinfo'),
        'dnssec'     => $domain->get('dnssec'),
        'cr_date'    => $domain->get('crDate'),
        'ex_date'    => $domain->get('exDate'),
    ];
}

/**
 * the 403 every ownership check above answers with
 */
function domainForbidden(Response $response, string $domain): Response {
    return Json::response($response, ['error' => "You are not authorized to modify domain '{$domain}'"], 403);
}

$app->get('/v1/domains', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $params  = $request->getQueryParams();

    $nic = new Client();
    $domain = new Domain($nic);
    $domains = $domain->listDomains(
        $scope,
        $params['registrant'] ?? null,
        ($params['active'] ?? '1') !== '0',
        isset($params['age']) ? (int) $params['age'] : 0
    );

    return Json::response($response, ['domains' => $domains]);
});

$app->get('/v1/domains/expiring', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $days = (int) ($request->getQueryParams()['days'] ?? 30);

    // scoped by the DOMAIN's reseller, like every other domain route
    $where = ['1 = 1'];
    $params = [':days' => $days];
    if ( ! $scope->isAdmin()) {
        $where[] = 'd.reseller_id = :reseller_id';
        $params[':reseller_id'] = $scope->resellerId;
    }

    $domains = R::getAll("
        SELECT d.*, c.handle, c.org, c.name, c.email
        FROM contacts c, domains d
        WHERE
            d.ex_date < NOW() + INTERVAL :days DAY AND
            d.active = 1 AND
            d.registrant = c.handle AND
            " . implode(' AND ', $where) . "
        ORDER BY d.ex_date ASC", $params);

    // raw SQL, so nothing has turned these back into arrays yet -- shipping
    // them as stored would put PHP's serialize() format in a JSON response
    foreach ($domains as &$row) {
        foreach (['ns', 'tech', 'status', 'dnssec'] as $column) {
            $row[$column] = SerializedColumn::toArray($row[$column] ?? null);
        }
    }
    unset($row);

    return Json::response($response, ['domains' => $domains]);
});

$app->get('/v1/domains/autocomplete', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $term = $request->getQueryParams()['term'] ?? '';
    $limit = (int) ($request->getQueryParams()['limit'] ?? 10) ?: 10;

    $where = ['domain LIKE :term'];
    $params = [':term' => "%{$term}%"];
    if ( ! $scope->isAdmin()) {
        $where[] = 'reseller_id = :reseller_id';
        $params[':reseller_id'] = $scope->resellerId;
    }

    $domains = R::getCol("SELECT domain FROM domains WHERE active = 1 AND " . implode(' AND ', $where), $params);
    $transfersIn = R::getCol("SELECT concat(domain, ' (transfer-in)') FROM transfers WHERE " . implode(' AND ', $where), $params);
    $domains = array_merge($domains, $transfersIn);
    sort($domains);

    return Json::response($response, ['domains' => array_slice($domains, 0, $limit)]);
});

$app->get('/v1/domains/export', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);

    $where = ['1 = 1'];
    $params = [];
    if ( ! $scope->isAdmin()) {
        $where[] = 'd.reseller_id = :reseller_id';
        $params[':reseller_id'] = $scope->resellerId;
    }
    $records = R::getAll("
        SELECT
            d.active, d.domain, d.authinfo, d.cr_date, d.ex_date,
            c.handle, c.org, c.name, c.email
        FROM
            contacts c, domains d
        WHERE
            d.registrant = c.handle AND
            " . implode(' AND ', $where) . "
        ORDER BY d.domain ASC", $params);

    $titles = ['Active', 'Domain', 'Auth-Info', 'Created', 'Expires', 'Registrant Handle', 'Registrant Org', 'Registrant Name', 'Registrant Email'];
    $fields = ['active', 'domain', 'authinfo', 'cr_date', 'ex_date', 'handle', 'org', 'name', 'email'];

    // Csv::row() rather than a fourth inline copy of the quoting -- it also
    // doubles embedded quotes, which this did not: 'Rossi "Da Bepi" S.r.l.'
    // ended the field early and shifted every following column
    $csv = Csv::row($titles, ';');
    foreach ($records as $record) {
        $row = [];
        foreach ($fields as $field) {
            $row[] = $record[$field];
        }
        $csv .= Csv::row($row, ';');
    }

    $response->getBody()->write($csv);
    return $response
        ->withHeader('Content-Type', 'text/csv; charset=utf-8')
        ->withHeader('Content-Disposition', 'attachment; filename="domains-export.csv"');
});

$app->get('/v1/domains/transfers', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $registrant = $request->getQueryParams()['registrant'] ?? '';

    // scoped by the requesting reseller (transfers.reseller_id), matching the
    // transfer/cancel authorization check
    $where = ['t.registrant = c.handle'];
    $bind = [];
    if ($registrant !== '') {
        $where[] = 't.registrant = :registrant';
        $bind[':registrant'] = $registrant;
    }
    if ( ! $scope->isAdmin()) {
        $where[] = 't.reseller_id = :reseller_id';
        $bind[':reseller_id'] = $scope->resellerId;
    }

    $rows = R::getAll("
        SELECT t.id, t.domain, t.techc, t.dns, t.reseller_id, c.name, c.email
        FROM transfers t, contacts c
        WHERE " . implode(' AND ', $where), $bind);

    $transfers = array_map(function ($row) {
        $row['techc'] = empty($row['techc']) ? [] : unserialize($row['techc']);
        $row['dns'] = empty($row['dns']) ? [] : unserialize($row['dns']);
        return $row;
    }, $rows);

    return Json::response($response, ['transfers' => $transfers]);
});

$app->get('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];

    // before the registry: its answer carries the authinfo, which is ours for
    // every reseller's domain. 404 so another reseller's names don't leak
    if ( ! Access::canAccessDomain($name, $scope, true)) {
        return Json::response($response, ['error' => "Domain '{$name}' not found"], 404);
    }

    // the registry is authoritative: its answer is returned as-is, never
    // overlaid with the local row -- loadDB() re-initializes before its lookup
    // and leaves the object empty when that misses
    try {
        $domain = EppSession::run(function ($nic) use ($name) {
            $domain = new Domain($nic);
            return $domain->fetch($name) ? $domain : null;
        }, $debug);
    } catch (\RuntimeException $e) {
        // registry unreachable -- indistinguishable from "not found" as far as
        // this route is concerned, both fall through to the local fallback
        $domain = null;
    }

    if ($domain !== null) {
        return Json::response($response, ['domain' => domainToArray($domain), 'stale' => false]);
    }

    // registry lookup failed: serve the last known local state, flagged as
    // possibly stale. loadDB() scopes by reseller, so another reseller's
    // domain is simply not found. (via a variable: Domain takes it by
    // reference)
    $nic = new Client();
    $domain = new Domain($nic);
    if ( ! $domain->loadDB($name, $scope)) {
        return Json::response($response, ['error' => "Domain '{$name}' not found"], 404);
    }

    return Json::response($response, ['domain' => domainToArray($domain), 'stale' => true]);
});

$app->post('/v1/domains', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $params = $request->getParsedBody() ?? [];

    if ($err = Validate::requireFields($params, ['domain', 'registrant']) ?? Validate::maxLength($params, Validate::DOMAIN_FIELD_MAX_LENGTHS)) {
        return Json::response($response, ['error' => $err], 400);
    }
    if ( ! Validate::isDomain($params['domain'])) {
        return Json::response($response, ['error' => "'{$params['domain']}' is not a valid .it domain name"], 400);
    }
    if ( ! Access::canUseAsRegistrant($params['registrant'], $scope)) {
        return Json::response($response, ['error' => "Contact '{$params['registrant']}' is not yours to use as registrant"], 403);
    }
    if ( ! Access::withinQuota($scope)) {
        return Json::response($response, ['error' => 'Daily operation quota exceeded'], 429);
    }

    try {
        $result = EppSession::run(
            fn($nic) => DomainService::createOrTransfer($nic, $params, $scope->userId),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    Access::recordRequest($params['domain'], $result['action'] === 'created' ? 'register' : 'transfer', $scope);
    return Json::response($response, ['domain' => domainToArray($result['domain'])], 201);
});

$app->post('/v1/domains/import', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $params = $request->getParsedBody() ?? [];

    $names = array_unique(array_filter(array_map('trim', (array) ($params['domains'] ?? []))));
    if (empty($names)) {
        return Json::response($response, ['error' => 'domains is required (array of domain names)'], 400);
    }

    try {
        $results = EppSession::run(
            fn($nic) => DomainService::import($nic, $names, $scope),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    return Json::response($response, ['results' => $results]);
});

$app->patch('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }
    if ($err = Validate::maxLength($params, Validate::DOMAIN_FIELD_MAX_LENGTHS)) {
        return Json::response($response, ['error' => $err], 400);
    }

    try {
        $result = EppSession::run(function ($nic) use ($name, $params, $scope) {
            $domain = new Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }

            // registrant changes are a distinct EPP operation
            // (Domain::updateRegistrant(), which requires authinfo to change
            // alongside it and ignores ns/tech) -- handled by the dedicated
            // POST /domains/{name}/registrant endpoint instead
            if (array_key_exists('admin', $params)) $domain->set('admin', $params['admin']);
            if (array_key_exists('authinfo', $params)) $domain->set('authinfo', $params['authinfo']);

            // diffed add/remove: caller sends the full target NS/tech/DNSSEC
            // list, we add what's missing and remove what's no longer present
            if (array_key_exists('ns', $params)) {
                $current = array_keys((array) $domain->get('ns'));
                $target = array_map(fn($ns) => $ns['name'] ?? $ns, (array) $params['ns']);
                foreach (array_diff($target, $current) as $add) $domain->addNS($add);
                foreach (array_diff($current, $target) as $rem) $domain->remNS($rem);
            }
            if (array_key_exists('tech', $params)) {
                $current = array_keys((array) $domain->get('tech'));
                $target = (array) $params['tech'];
                foreach (array_diff($target, $current) as $add) $domain->addTECH($add);
                foreach (array_diff($current, $target) as $rem) $domain->remTECH($rem);
            }
            if (array_key_exists('dnssec', $params)) {
                $current = array_keys((array) $domain->get('dnssec'));
                $target = [];
                foreach ((array) $params['dnssec'] as $ds) {
                    $domain->addDNSSEC($ds['keytag'], $ds['algorithm'], $ds['digesttype'], $ds['digest']);
                    $target[] = $ds['digest'];
                }
                foreach (array_diff($current, $target) as $rem) $domain->remDNSSEC($rem);
            }

            // update() resets this to 0 on success, so it must be captured
            // beforehand
            $changes = $domain->changedFields();

            if ( ! $domain->update()) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            $domain->updateDB($name, $scope, $changes);

            return ['ok' => true, 'domain' => $domain];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    return Json::response($response, ['domain' => domainToArray($result['domain'])]);
});

$app->post('/v1/domains/{name}/registrant', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['registrant'])) {
        return Json::response($response, ['error' => 'registrant is required'], 400);
    }
    // a registrant change moves the domain to that contact's reseller
    // (Domain::updateDB()), so it must be one of the caller's -- or this is a
    // way to hand a domain away by accident
    if ( ! Access::canUseAsRegistrant($params['registrant'], $scope)) {
        return Json::response($response, ['error' => "Contact '{$params['registrant']}' is not yours to use as registrant"], 403);
    }

    try {
        $result = EppSession::run(function ($nic) use ($name, $params, $scope) {
            $domain = new Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }

            $domain->set('registrant', $params['registrant']);
            // updateRegistrant() requires authinfo to change alongside
            // registrant -- rotate it (caller-supplied, or freshly generated)
            // as part of the change
            $domain->set('authinfo', $params['authinfo'] ?? $domain->authinfo());

            if ( ! $domain->updateRegistrant()) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            $domain->updateDB($name, $scope);
            return ['ok' => true, 'domain' => $domain];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    return Json::response($response, ['domain' => domainToArray($result['domain'])]);
});

$app->post('/v1/domains/{name}/status', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['state'])) {
        return Json::response($response, ['error' => 'state is required'], 400);
    }
    $action = $params['action'] ?? 'add';

    try {
        $result = EppSession::run(function ($nic) use ($name, $params, $action) {
            $domain = new Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }
            if ( ! $domain->updateStatus($params['state'], $action)) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            return ['ok' => true, 'domain' => $domain];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    // updateStatus() doesn't participate in the changes-bitmask, so it can't
    // go through the usual updateDB() guard -- sync the status column directly
    $sql = "UPDATE domains SET status = :status WHERE domain = :domain";
    $sqlParams = [':status' => serialize($result['domain']->get('status')), ':domain' => $name];
    if ( ! $scope->isAdmin()) {
        $sql .= " AND reseller_id = :reseller_id";
        $sqlParams[':reseller_id'] = $scope->resellerId;
    }
    R::exec($sql, $sqlParams);
    $id = (int) R::getCell("SELECT id FROM domains WHERE domain = ?", [$name]);
    History::record('domains', $id, 'update', ['status' => $result['domain']->get('status')], $scope->userId);

    return Json::response($response, ['domain' => domainToArray($result['domain'])]);
});

$app->delete('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getQueryParams();
    $mode = $params['mode'] ?? 'now';

    // checked up front so both the registry delete (mode=now) and the
    // schedule-a-reminder branch (mode=expiry|date) answer identically
    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }

    if ($mode === 'expiry' || $mode === 'date') {
        $date = $mode === 'date' ? ($params['date'] ?? null) : null;
        if ($mode === 'date' && empty($date)) {
            return Json::response($response, ['error' => 'date is required when mode=date'], 400);
        }

        $row = R::getRow("SELECT id, ex_date FROM domains WHERE domain = :domain" . ($scope->isAdmin() ? '' : ' AND reseller_id = :reseller_id'), array_filter([
            ':domain' => $name,
            ':reseller_id' => $scope->isAdmin() ? null : $scope->resellerId,
        ], fn($v) => $v !== null));
        if (empty($row)) {
            return Json::response($response, ['error' => "Domain '{$name}' not found"], 404);
        }

        // object='registry', action='delete': `domain reap-deletions` reads
        // this and deletes the domain at the registry once `date` arrives.
        // The explicit action is what lets that job's own query say, in SQL,
        // that a delete is the only thing it will ever act on.
        R::exec("INSERT INTO tasks (domain, date, notice, email, object, action) VALUES (:domain, :date, :notice, '', 'registry', 'delete')", [
            ':domain' => $name,
            ':date'   => $date ?: $row['ex_date'],
            ':notice' => 'scheduled deletion',
        ]);

        return Json::response($response, ['scheduled' => true, 'domain' => $name, 'date' => $date ?: $row['ex_date']]);
    }

    try {
        $result = EppSession::run(function ($nic) use ($name, $scope) {
            $domain = new Domain($nic);
            if ( ! $domain->delete($name)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $domain->deleteDomainDB($name, $scope);
            return ['ok' => true];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    return Json::response($response, ['deleted' => true, 'domain' => $name]);
});

$app->post('/v1/domains/{name}/restore', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];

    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }

    try {
        $result = EppSession::run(function ($nic) use ($name, $scope) {
            $domain = new Domain($nic);
            if ( ! $domain->restore($name)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $domain->restoreDomainDB($name, $scope);
            return ['ok' => true];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    return Json::response($response, ['restored' => true, 'domain' => $name]);
});

$app->post('/v1/domains/{name}/owner', function (Request $request, Response $response, array $args): Response {
    $actorId = Auth::requireAdmin($request);
    ['debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if (empty($params['reseller_id'])) {
        return Json::response($response, ['error' => 'reseller_id (the new owner) is required'], 400);
    }
    $resellerId = (int) $params['reseller_id'];
    if ((int) R::getCell('SELECT COUNT(*) FROM resellers WHERE id = ?', [$resellerId]) === 0) {
        return Json::response($response, ['error' => "Reseller {$resellerId} not found"], 404);
    }

    try {
        $result = EppSession::run(
            fn($nic) => DomainService::changeOwner($nic, $name, $resellerId, $actorId),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    return Json::response($response, ['domain' => domainToArray($result['domain'])]);
});

$app->post('/v1/domains/{name}/transfer', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    // a transfer-in request is a *claim*: the caller is not supposed to own the
    // domain yet, so Access::canAccessDomain() would reject every legitimate request.
    // What must be blocked is claiming a domain another reseller holds.
    if (Access::domainHeldByAnotherReseller($name, $scope)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['authinfo'])) {
        return Json::response($response, ['error' => 'authinfo is required'], 400);
    }
    $registrant = (string) ($params['registrant'] ?? '');
    if ($registrant !== '' && ! Access::canUseAsRegistrant($registrant, $scope)) {
        return Json::response($response, ['error' => "Contact '{$registrant}' is not yours to use as registrant"], 403);
    }
    if ( ! Access::withinQuota($scope)) {
        return Json::response($response, ['error' => 'Daily operation quota exceeded'], 429);
    }
    // the transfer belongs where the domain will: its registrant's reseller
    $resellerId = $registrant !== '' ? Domain::resellerOf($registrant) : $scope->resellerId;

    try {
        $result = EppSession::run(function ($nic) use ($name, $params, $resellerId) {
            $domain = new Domain($nic);
            if ( ! $domain->transfer($name, $params['authinfo'])) {
                return ['ok' => false, 'error' => $domain->getError()];
            }

            R::exec("
                INSERT INTO transfers (reseller_id, domain, registrant, techc, dns)
                VALUES (:reseller_id, :domain, :registrant, :techc, :dns)
            ", [
                ':reseller_id' => $resellerId,
                ':domain'     => $name,
                ':registrant' => $params['registrant'] ?? '',
                ':techc'      => serialize((array) ($params['tech'] ?? [])),
                ':dns'        => serialize((array) ($params['ns'] ?? [])),
            ]);
            return ['ok' => true];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    Access::recordRequest($name, 'transfer', $scope);
    return Json::response($response, ['requested' => true, 'domain' => $name], 201);
});

foreach (['approve', 'reject', 'cancel'] as $transferAction) {
    $app->post("/v1/domains/{name}/transfer/{$transferAction}", function (Request $request, Response $response, array $args) use ($transferAction): Response {
        ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
        $name = $args['name'];
        $params = $request->getParsedBody() ?? [];
        $authinfo = $params['authinfo'] ?? '';
        $method = 'transfer' . ucfirst($transferAction);

        // approve/reject answer a request for a domain we sponsor, so the
        // caller must own the `domains` row; cancel withdraws our own request,
        // which exists only in `transfers`
        if ( ! Access::canAccessDomain($name, $scope, $transferAction === 'cancel')) {
            return domainForbidden($response, $name);
        }

        try {
            $result = EppSession::run(function ($nic) use ($name, $authinfo, $method) {
                $domain = new Domain($nic);
                if ( ! $domain->$method($name, $authinfo)) {
                    return ['ok' => false, 'error' => $domain->getError()];
                }
                if ($method !== 'transferCancel') {
                    R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
                }
                return ['ok' => true];
            }, $debug);
        } catch (\RuntimeException $e) {
            return Json::response($response, ['error' => $e->getMessage()], 502);
        }

        if ( ! $result['ok']) {
            return Json::response($response, ['error' => $result['error']], 400);
        }

        return Json::response($response, [$transferAction => true, 'domain' => $name]);
    });
}

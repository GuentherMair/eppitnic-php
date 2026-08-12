<?php

use Net\EPP\Client;
use Net\EPP\Helpers;
use Net\EPP\IT\Domain;
use Net\EPP\Service\DomainService;
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
 * A domain may be operated on by its local owner, or by any admin. Unlike
 * contacts (canAccessContact(), routes/contact.php) there is no wider
 * attachment rule -- a domain belongs to exactly one local user.
 *
 * Every write route calls this *before* opening an EPP session: the local
 * *DB() helpers scope their own UPDATE by user_id, but that happens after the
 * registry has already been changed, which is far too late to be an authorization
 * check.
 *
 * @param bool $includePending also accept a domain that so far only exists as a
 *                     pending transfer-in request (the `transfers` table) --
 *                     the state a transfer/cancel operates on, where the
 *                     domain isn't in `domains` yet
 */
function canAccessDomain(string $domain, int $user_id, bool $isAdmin, bool $includePending = false): bool {
    if ($isAdmin) {
        return true;
    }
    $owns = (int) R::getCell("SELECT COUNT(*) FROM domains WHERE domain = ? AND user_id = ?", [$domain, $user_id]);
    if ($owns > 0) {
        return true;
    }
    if ($includePending) {
        return (int) R::getCell("SELECT COUNT(*) FROM transfers WHERE domain = ? AND user_id = ?", [$domain, $user_id]) > 0;
    }
    return false;
}

/**
 * whether some *other* local user already holds this domain. This is the check
 * that applies to claim-style operations (requesting a transfer-in), where the
 * caller is not expected to own the domain yet -- an ownership check would
 * reject every legitimate request -- but must not be able to pull a domain away
 * from a colleague either.
 */
function domainHeldByAnotherUser(string $domain, int $user_id, bool $isAdmin): bool {
    if ($isAdmin) {
        return false;
    }
    return (int) R::getCell(
        "SELECT COUNT(*) FROM domains WHERE domain = ? AND user_id <> ? AND active = 1",
        [$domain, $user_id]
    ) > 0;
}

/**
 * the 403 every ownership check above answers with
 */
function domainForbidden(Response $response, string $domain): Response {
    return Helpers::json($response, ['error' => "You are not authorized to modify domain '{$domain}'"], 403);
}

/**
 * Whether the caller may attach $handle to a domain as its registrant.
 *
 * A domain's local owner is derived from its registrant contact
 * (Domain::updateDB() reassigns domains.user_id to the new registrant's owner
 * on a registrant change), so letting a caller name a contact somebody else
 * owns is how the two ownership notions drift apart -- and the drift is
 * invisible, because the resulting domain then answers to one user in the
 * listings and another in the renewals view. Domain::updateDB()'s own comment
 * has always assumed the caller checks this; this is that check.
 *
 * Ownership, not canAccessContact(): being allowed to *read* a contact because
 * it happens to hang off one of your domains is not grounds for making it the
 * registrant of another.
 */
function canUseAsRegistrant(string $handle, int $user_id, bool $isAdmin): bool {
    if ($isAdmin) {
        return true;
    }
    return (int) R::getCell(
        "SELECT COUNT(*) FROM contacts WHERE handle = ? AND user_id = ?",
        [$handle, $user_id]
    ) > 0;
}

$app->get('/v1/domains', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin] = Helpers::actor($request);
    $params  = $request->getQueryParams();

    $nic = new Client();
    $domain = new Domain($nic);
    $domains = $domain->listDomains(
        $user_id,
        $isAdmin,
        $params['registrant'] ?? null,
        ($params['active'] ?? '1') !== '0',
        isset($params['age']) ? (int) $params['age'] : 0
    );

    return Helpers::json($response, ['domains' => $domains]);
});

$app->get('/v1/domains/expiring', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin] = Helpers::actor($request);
    $days = (int) ($request->getQueryParams()['days'] ?? 30);

    // scoped by the DOMAIN's owner, like every other domain route (listDomains(),
    // export, autocomplete, canAccessDomain()). It used to scope by the
    // registrant CONTACT's owner, which meant a domain whose registrant belongs
    // to another user was missing from its own owner's renewals list and showing
    // up in that other user's -- who cannot act on it at all.
    $where = ['1 = 1'];
    $params = [':days' => $days];
    if ( ! $isAdmin) {
        $where[] = 'd.user_id = :user_id';
        $params[':user_id'] = $user_id;
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

    return Helpers::json($response, ['domains' => $domains]);
});

$app->get('/v1/domains/autocomplete', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin] = Helpers::actor($request);
    $term = $request->getQueryParams()['term'] ?? '';
    $limit = (int) ($request->getQueryParams()['limit'] ?? 10) ?: 10;

    $where = ['domain LIKE :term'];
    $params = [':term' => "%{$term}%"];
    if ( ! $isAdmin) {
        $where[] = 'user_id = :user_id';
        $params[':user_id'] = $user_id;
    }

    $domains = R::getCol("SELECT domain FROM domains WHERE active = 1 AND " . implode(' AND ', $where), $params);
    $transfersIn = R::getCol("SELECT concat(domain, ' (transfer-in)') FROM transfers WHERE " . implode(' AND ', $where), $params);
    $domains = array_merge($domains, $transfersIn);
    sort($domains);

    return Helpers::json($response, ['domains' => array_slice($domains, 0, $limit)]);
});

$app->get('/v1/domains/export', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin] = Helpers::actor($request);

    $where = ['1 = 1'];
    $params = [];
    if ( ! $isAdmin) {
        $where[] = 'd.user_id = :user_id';
        $params[':user_id'] = $user_id;
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

    // Helpers::rowToCSV() rather than inlining the quoting a fourth time. It
    // also doubles embedded quotes, which the inline version here did not: an
    // organisation named 'Rossi "Da Bepi" S.r.l.' used to end the field early
    // and shift every following column of that row.
    $csv = Helpers::rowToCSV($titles, ';');
    foreach ($records as $record) {
        $row = [];
        foreach ($fields as $field) {
            $row[] = $record[$field];
        }
        $csv .= Helpers::rowToCSV($row, ';');
    }

    $response->getBody()->write($csv);
    return $response
        ->withHeader('Content-Type', 'text/csv; charset=utf-8')
        ->withHeader('Content-Disposition', 'attachment; filename="domains-export.csv"');
});

$app->get('/v1/domains/transfers', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin] = Helpers::actor($request);
    $registrant = $request->getQueryParams()['registrant'] ?? '';

    // scoped by who REQUESTED the transfer (transfers.user_id), which is also
    // what the transfer/cancel authorization check uses -- scoping by the
    // registrant contact's owner instead meant a user could be shown a pending
    // transfer they were not allowed to cancel, and vice versa. The joined user
    // is likewise the requester now, so `user_id`/`email_user` agree with it.
    $where = ['t.registrant = c.handle', 't.user_id = u.id'];
    $bind = [];
    if ($registrant !== '') {
        $where[] = 't.registrant = :registrant';
        $bind[':registrant'] = $registrant;
    }
    if ( ! $isAdmin) {
        $where[] = 't.user_id = :user_id';
        $bind[':user_id'] = $user_id;
    }

    $rows = R::getAll("
        SELECT
            t.id, t.domain, t.techc, t.dns, t.user_id AS transferUserID,
            c.name, c.email,
            u.id AS user_id, u.email AS email_user
        FROM transfers t, contacts c, users u
        WHERE " . implode(' AND ', $where), $bind);

    $transfers = array_map(function ($row) {
        $row['techc'] = empty($row['techc']) ? [] : unserialize($row['techc']);
        $row['dns'] = empty($row['dns']) ? [] : unserialize($row['dns']);
        return $row;
    }, $rows);

    return Helpers::json($response, ['transfers' => $transfers]);
});

$app->get('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
    $name = $args['name'];

    // the registry is authoritative -- its answer is returned as-is, never
    // overlaid with the local row (overlaying is what used to blank the whole
    // object out, since loadDB() re-initializes before its own lookup and
    // leaves it empty when that lookup misses)
    try {
        $domain = Helpers::withEppSession(function ($nic) use ($name) {
            $domain = new Domain($nic);
            return $domain->fetch($name) ? $domain : null;
        }, $debug);
    } catch (\RuntimeException $e) {
        // registry unreachable -- indistinguishable from "not found" as far as
        // this route is concerned, both fall through to the local fallback
        $domain = null;
    }

    if ($domain !== null) {
        return Helpers::json($response, ['domain' => domainToArray($domain), 'stale' => false]);
    }

    // registry lookup failed: serve the last known local state instead, flagged
    // as potentially out of date. loadDB() scopes by user_id, so a domain the
    // caller doesn't own is simply not found here.
    // (via a variable: Domain::__construct() takes its Client by reference)
    $nic = new Client();
    $domain = new Domain($nic);
    if ( ! $domain->loadDB($name, $user_id, $isAdmin)) {
        return Helpers::json($response, ['error' => "Domain '{$name}' not found"], 404);
    }

    return Helpers::json($response, ['domain' => domainToArray($domain), 'stale' => true]);
});

$app->post('/v1/domains', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
    $params = $request->getParsedBody() ?? [];

    if ($err = Helpers::requireFields($params, ['domain', 'registrant']) ?? Helpers::maxLength($params, Helpers::DOMAIN_FIELD_MAX_LENGTHS)) {
        return Helpers::json($response, ['error' => $err], 400);
    }
    if ( ! Helpers::isValidDomainFormat($params['domain'])) {
        return Helpers::json($response, ['error' => "'{$params['domain']}' is not a valid .it domain name"], 400);
    }
    if ( ! canUseAsRegistrant($params['registrant'], $user_id, $isAdmin)) {
        return Helpers::json($response, ['error' => "Contact '{$params['registrant']}' is not yours to use as registrant"], 403);
    }

    // quota check -- count today's domain creations against this user's cap,
    // sourced from the changelog audit trail rather than a separate counter
    if ( ! $isAdmin) {
        $user = R::getRow("SELECT max_operations FROM users WHERE id = ?", [$user_id]);
        $maxOps = (int) ($user['max_operations'] ?? 0);
        if ($maxOps > 0) {
            $used = (int) R::getCell("
                SELECT COUNT(*) FROM changelog
                WHERE user_id = ? AND object = 'domains' AND action = 'create' AND DATE(timestamp) = CURDATE()
            ", [$user_id]);
            if ($used >= $maxOps) {
                return Helpers::json($response, ['error' => 'Daily operation quota exceeded'], 429);
            }
        }
    }

    try {
        $result = Helpers::withEppSession(
            fn($nic) => DomainService::createOrTransfer($nic, $params, $user_id),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Helpers::json($response, ['error' => $result['error']], 400);
    }

    return Helpers::json($response, ['domain' => domainToArray($result['domain'])], 201);
});

$app->post('/v1/domains/import', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'debug' => $debug] = Helpers::actor($request);
    $params = $request->getParsedBody() ?? [];

    $names = array_unique(array_filter(array_map('trim', (array) ($params['domains'] ?? []))));
    if (empty($names)) {
        return Helpers::json($response, ['error' => 'domains is required (array of domain names)'], 400);
    }

    try {
        $results = Helpers::withEppSession(
            fn($nic) => DomainService::import($nic, $names, $user_id),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    return Helpers::json($response, ['results' => $results]);
});

$app->patch('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! canAccessDomain($name, $user_id, $isAdmin)) {
        return domainForbidden($response, $name);
    }
    if ($err = Helpers::maxLength($params, Helpers::DOMAIN_FIELD_MAX_LENGTHS)) {
        return Helpers::json($response, ['error' => $err], 400);
    }

    try {
        $result = Helpers::withEppSession(function ($nic) use ($name, $params, $user_id, $isAdmin) {
            $domain = new Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }

            // registrant changes are a distinct EPP operation (Domain::updateRegistrant(),
            // which requires authinfo to change alongside it and ignores ns/tech) --
            // handled by the dedicated POST /domains/{name}/registrant endpoint instead
            if (array_key_exists('admin', $params)) $domain->set('admin', $params['admin']);
            if (array_key_exists('authinfo', $params)) $domain->set('authinfo', $params['authinfo']);

            // diffed add/remove: caller sends the full target NS/tech/DNSSEC list,
            // we add what's missing and remove what's no longer present
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

            // update() resets this to 0 on success, so it must be captured beforehand
            $changes = (int) $domain->get('changes');

            if ( ! $domain->update()) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            $domain->updateDB($name, $user_id, $isAdmin, $changes);

            return ['ok' => true, 'domain' => $domain];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Helpers::json($response, ['error' => $result['error']], $result['status']);
    }

    return Helpers::json($response, ['domain' => domainToArray($result['domain'])]);
});

$app->post('/v1/domains/{name}/registrant', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! canAccessDomain($name, $user_id, $isAdmin)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['registrant'])) {
        return Helpers::json($response, ['error' => 'registrant is required'], 400);
    }
    // a registrant change also moves the domain's local ownership to that
    // contact's owner (Domain::updateDB()), so this must be a contact the
    // caller owns -- otherwise it is a way to hand your domain to someone else,
    // or to take one out of your own listings by accident
    if ( ! canUseAsRegistrant($params['registrant'], $user_id, $isAdmin)) {
        return Helpers::json($response, ['error' => "Contact '{$params['registrant']}' is not yours to use as registrant"], 403);
    }

    try {
        $result = Helpers::withEppSession(function ($nic) use ($name, $params, $user_id, $isAdmin) {
            $domain = new Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }

            $domain->set('registrant', $params['registrant']);
            // updateRegistrant() requires authinfo to change alongside registrant --
            // rotate it (caller-supplied, or freshly generated) as part of the change
            $domain->set('authinfo', $params['authinfo'] ?? $domain->authinfo());

            if ( ! $domain->updateRegistrant()) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            $domain->updateDB($name, $user_id, $isAdmin);
            return ['ok' => true, 'domain' => $domain];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Helpers::json($response, ['error' => $result['error']], $result['status']);
    }

    return Helpers::json($response, ['domain' => domainToArray($result['domain'])]);
});

$app->post('/v1/domains/{name}/status', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! canAccessDomain($name, $user_id, $isAdmin)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['state'])) {
        return Helpers::json($response, ['error' => 'state is required'], 400);
    }
    $action = $params['action'] ?? 'add';

    try {
        $result = Helpers::withEppSession(function ($nic) use ($name, $params, $action) {
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
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Helpers::json($response, ['error' => $result['error']], $result['status']);
    }

    // updateStatus() doesn't participate in the changes-bitmask, so it can't
    // go through the usual updateDB() guard -- sync the status column directly
    $sql = "UPDATE domains SET status = :status WHERE domain = :domain";
    $sqlParams = [':status' => serialize($result['domain']->get('status')), ':domain' => $name];
    if ( ! $isAdmin) {
        $sql .= " AND user_id = :user_id";
        $sqlParams[':user_id'] = $user_id;
    }
    R::exec($sql, $sqlParams);
    $id = (int) R::getCell("SELECT id FROM domains WHERE domain = ?", [$name]);
    Helpers::logChanges('domains', $id, 'update', ['status' => $result['domain']->get('status')], $user_id);

    return Helpers::json($response, ['domain' => domainToArray($result['domain'])]);
});

$app->delete('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
    $name = $args['name'];
    $params = $request->getQueryParams();
    $mode = $params['mode'] ?? 'now';

    // checked up front so both the registry delete (mode=now) and the
    // schedule-a-reminder branch (mode=expiry|date) answer identically
    if ( ! canAccessDomain($name, $user_id, $isAdmin)) {
        return domainForbidden($response, $name);
    }

    if ($mode === 'expiry' || $mode === 'date') {
        $date = $mode === 'date' ? ($params['date'] ?? null) : null;
        if ($mode === 'date' && empty($date)) {
            return Helpers::json($response, ['error' => 'date is required when mode=date'], 400);
        }

        $row = R::getRow("SELECT id, ex_date FROM domains WHERE domain = :domain" . ($isAdmin ? '' : ' AND user_id = :user_id'), array_filter([
            ':domain' => $name,
            ':user_id' => $isAdmin ? null : $user_id,
        ], fn($v) => $v !== null));
        if (empty($row)) {
            return Helpers::json($response, ['error' => "Domain '{$name}' not found"], 404);
        }

        // no `action` here -- this is a future-dated notice, not a DNS-sync event yet.
        // pdnsutil_updates.php gates 'delete' rows off created_time, not this row's `date`,
        // so tagging this action='delete' now would tear down DNS ~12h after the reminder
        // was set instead of on the actual future date. Whatever later executes this
        // scheduled deletion (mode=now) fires its own fresh action='delete' row then.
        R::exec("INSERT INTO reminder (domain, date, notice, email) VALUES (:domain, :date, :notice, '')", [
            ':domain' => $name,
            ':date'   => $date ?: $row['ex_date'],
            ':notice' => 'scheduled deletion',
        ]);

        return Helpers::json($response, ['scheduled' => true, 'domain' => $name, 'date' => $date ?: $row['ex_date']]);
    }

    try {
        $result = Helpers::withEppSession(function ($nic) use ($name, $user_id, $isAdmin) {
            $domain = new Domain($nic);
            if ( ! $domain->delete($name)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $domain->deleteDomainDB($name, $user_id, $isAdmin);
            return ['ok' => true];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Helpers::json($response, ['error' => $result['error']], 400);
    }

    return Helpers::json($response, ['deleted' => true, 'domain' => $name]);
});

$app->post('/v1/domains/{name}/restore', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
    $name = $args['name'];

    if ( ! canAccessDomain($name, $user_id, $isAdmin)) {
        return domainForbidden($response, $name);
    }

    try {
        $result = Helpers::withEppSession(function ($nic) use ($name, $user_id, $isAdmin) {
            $domain = new Domain($nic);
            if ( ! $domain->restore($name)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $domain->restoreDomainDB($name, $user_id, $isAdmin);
            return ['ok' => true];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Helpers::json($response, ['error' => $result['error']], 400);
    }

    return Helpers::json($response, ['restored' => true, 'domain' => $name]);
});

$app->post('/v1/domains/{name}/owner', function (Request $request, Response $response, array $args): Response {
    Helpers::jwtRequireAdmin($request);
    ['debug' => $debug] = Helpers::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if (empty($params['user_id'])) {
        return Helpers::json($response, ['error' => 'user_id (the new owner) is required'], 400);
    }
    $newOwnerId = (int) $params['user_id'];

    try {
        $result = Helpers::withEppSession(
            fn($nic) => DomainService::changeOwner($nic, $name, $newOwnerId),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Helpers::json($response, ['error' => $result['error']], $result['status']);
    }

    return Helpers::json($response, ['domain' => domainToArray($result['domain'])]);
});

$app->post('/v1/domains/{name}/transfer', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    // a transfer-in request is a *claim*: the caller is not supposed to own the
    // domain yet, so canAccessDomain() would reject every legitimate request.
    // What must be blocked is claiming a domain a colleague already holds.
    if (domainHeldByAnotherUser($name, $user_id, $isAdmin)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['authinfo'])) {
        return Helpers::json($response, ['error' => 'authinfo is required'], 400);
    }

    try {
        $result = Helpers::withEppSession(function ($nic) use ($name, $params, $user_id) {
            $domain = new Domain($nic);
            if ( ! $domain->transfer($name, $params['authinfo'])) {
                return ['ok' => false, 'error' => $domain->getError()];
            }

            R::exec("
                INSERT INTO transfers (user_id, domain, registrant, techc, dns)
                VALUES (:user_id, :domain, :registrant, :techc, :dns)
            ", [
                ':user_id'    => $user_id,
                ':domain'     => $name,
                ':registrant' => $params['registrant'] ?? '',
                ':techc'      => serialize((array) ($params['tech'] ?? [])),
                ':dns'        => serialize((array) ($params['ns'] ?? [])),
            ]);
            return ['ok' => true];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Helpers::json($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Helpers::json($response, ['error' => $result['error']], 400);
    }

    return Helpers::json($response, ['requested' => true, 'domain' => $name], 201);
});

foreach (['approve', 'reject', 'cancel'] as $transferAction) {
    $app->post("/v1/domains/{name}/transfer/{$transferAction}", function (Request $request, Response $response, array $args) use ($transferAction): Response {
        ['id' => $user_id, 'isAdmin' => $isAdmin, 'debug' => $debug] = Helpers::actor($request);
        $name = $args['name'];
        $params = $request->getParsedBody() ?? [];
        $authinfo = $params['authinfo'] ?? '';
        $method = 'transfer' . ucfirst($transferAction);

        // approve/reject answer an incoming request for a domain we sponsor, so
        // the caller must own the `domains` row. cancel withdraws a request we
        // made ourselves, which only exists in `transfers` -- checking `domains`
        // alone would reject every legitimate cancel.
        if ( ! canAccessDomain($name, $user_id, $isAdmin, $transferAction === 'cancel')) {
            return domainForbidden($response, $name);
        }

        try {
            $result = Helpers::withEppSession(function ($nic) use ($name, $authinfo, $method) {
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
            return Helpers::json($response, ['error' => $e->getMessage()], 502);
        }

        if ( ! $result['ok']) {
            return Helpers::json($response, ['error' => $result['error']], 400);
        }

        return Helpers::json($response, [$transferAction => true, 'domain' => $name]);
    });
}

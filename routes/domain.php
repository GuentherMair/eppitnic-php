<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

require_once dirname(__FILE__).'/../Net/EPP/IT/Domain.php';

/**
 * serialize a Net_EPP_IT_Domain's relevant fields for a JSON response
 */
function domainToArray(Net_EPP_IT_Domain $domain): array {
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

$app->get('/v1/domains', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $params  = $request->getQueryParams();

    $nic = new Net_EPP_Client();
    $domain = new Net_EPP_IT_Domain($nic);
    $domains = $domain->listDomains(
        $user_id,
        $isAdmin,
        $params['registrant'] ?? null,
        ($params['active'] ?? '1') !== '0',
        isset($params['age']) ? (int) $params['age'] : 0
    );

    $response->getBody()->write(json_encode(['domains' => $domains]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/domains/expiring', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $days = (int) ($request->getQueryParams()['days'] ?? 30);

    $where = ['1 = 1'];
    $params = [':days' => $days];
    if ( ! $isAdmin) {
        $where[] = 'c.user_id = :user_id';
        $params[':user_id'] = $user_id;
    }

    $domains = R::getAll("
        SELECT d.*, c.handle, c.org, c.name, c.email, u.billing_id
        FROM users u, contacts c, domains d
        WHERE
            d.ex_date < NOW() + INTERVAL :days DAY AND
            d.active = 1 AND
            d.registrant = c.handle AND
            c.user_id = u.id AND
            " . implode(' AND ', $where) . "
        ORDER BY d.ex_date ASC", $params);

    $response->getBody()->write(json_encode(['domains' => $domains]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/domains/autocomplete', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
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

    $response->getBody()->write(json_encode(['domains' => array_slice($domains, 0, $limit)]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/domains/export', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;

    $where = ['1 = 1'];
    $params = [];
    if ( ! $isAdmin) {
        $where[] = 'd.user_id = :user_id';
        $params[':user_id'] = $user_id;
    }
    $records = R::getAll("
        SELECT
            d.active, d.domain, d.authinfo, d.cr_date, d.ex_date,
            c.handle, c.org, c.name, c.email,
            u.billing_id
        FROM
            users u, contacts c, domains d
        WHERE
            d.registrant = c.handle AND
            c.user_id = u.id AND
            " . implode(' AND ', $where) . "
        ORDER BY d.domain ASC", $params);

    $titles = ['Active', 'Domain', 'Auth-Info', 'Created', 'Expires', 'Registrant Handle', 'Registrant Org', 'Registrant Name', 'Registrant Email', 'Billing ID'];
    $fields = ['active', 'domain', 'authinfo', 'cr_date', 'ex_date', 'handle', 'org', 'name', 'email', 'billing_id'];
    $delimiter = ';';
    $enclosure = '"';
    $eol = "\n";

    $csv = $enclosure . implode($enclosure.$delimiter.$enclosure, $titles) . $enclosure . $eol;
    foreach ($records as $record) {
        $row = [];
        foreach ($fields as $field) {
            $row[] = $record[$field];
        }
        $csv .= $enclosure . implode($enclosure.$delimiter.$enclosure, $row) . $enclosure . $eol;
    }

    $response->getBody()->write($csv);
    return $response
        ->withHeader('Content-Type', 'text/csv; charset=utf-8')
        ->withHeader('Content-Disposition', 'attachment; filename="domains-export.csv"');
});

$app->get('/v1/domains/transfers', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $registrant = $request->getQueryParams()['registrant'] ?? '';

    $where = ['t.registrant = c.handle', 'c.user_id = u.id'];
    $bind = [];
    if ($registrant !== '') {
        $where[] = 't.registrant = :registrant';
        $bind[':registrant'] = $registrant;
    }
    if ( ! $isAdmin) {
        $where[] = 'c.user_id = :user_id';
        $bind[':user_id'] = $user_id;
    }

    $rows = R::getAll("
        SELECT
            t.id, t.domain, t.techc, t.dns, t.user_id AS transferUserID,
            c.name, c.email,
            u.id AS user_id, u.billing_id, u.email AS email_user
        FROM transfers t, contacts c, users u
        WHERE " . implode(' AND ', $where), $bind);

    $transfers = array_map(function ($row) {
        $row['techc'] = empty($row['techc']) ? [] : unserialize($row['techc']);
        $row['dns'] = empty($row['dns']) ? [] : unserialize($row['dns']);
        return $row;
    }, $rows);

    $response->getBody()->write(json_encode(['transfers' => $transfers]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $name = $args['name'];

    try {
        $domain = withEppSession(function ($nic) use ($name, $user_id, $isAdmin) {
            $domain = new Net_EPP_IT_Domain($nic);
            if ( ! $domain->fetch($name)) {
                return null;
            }
            $domain->loadDB($name, $user_id, $isAdmin);
            return $domain;
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ($domain === null) {
        $response->getBody()->write(json_encode(['error' => "Domain '{$name}' not found"]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['domain' => domainToArray($domain)]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/domains', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $params = $request->getParsedBody() ?? [];

    if ($err = requireFields($params, ['domain', 'registrant']) ?? maxLength($params, DOMAIN_FIELD_MAX_LENGTHS)) {
        $response->getBody()->write(json_encode(['error' => $err]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    if ( ! isValidDomainFormat($params['domain'])) {
        $response->getBody()->write(json_encode(['error' => "'{$params['domain']}' is not a valid .it domain name"]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
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
                $response->getBody()->write(json_encode(['error' => 'Daily operation quota exceeded']));
                return $response->withStatus(429)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
        }
    }

    try {
        $result = withEppSession(function ($nic) use ($params, $user_id) {
            $domain = new Net_EPP_IT_Domain($nic);
            $available = $domain->check($params['domain']);

            $domain->set('domain', $params['domain']);
            $domain->set('registrant', $params['registrant']);
            if ( ! empty($params['admin'])) $domain->set('admin', $params['admin']);
            foreach ((array) ($params['tech'] ?? []) as $tech) $domain->addTECH($tech);
            foreach ((array) ($params['ns'] ?? []) as $ns) $domain->addNS($ns['name'] ?? $ns, $ns['ip'] ?? null);
            $domain->set('authinfo', $params['authinfo'] ?? substr(md5(rand()), 0, 16));

            if ($available === TRUE) {
                if ( ! $domain->create()) {
                    return ['ok' => false, 'error' => $domain->getError()];
                }
            } else if ($available === FALSE) {
                if ( ! $domain->transfer($params['domain'], $domain->get('authinfo'))) {
                    return ['ok' => false, 'error' => $domain->getError()];
                }
            } else {
                return ['ok' => false, 'error' => $domain->getError()];
            }

            // a fresh registration is a DNS-sync 'create' event; a requested transfer-in
            // is NOT -- that only becomes real once PollProcessor sees it complete
            $domain->storeDB($user_id, $available === TRUE);

            return ['ok' => true, 'domain' => $domain];
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['domain' => domainToArray($result['domain'])]));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/domains/import', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $params = $request->getParsedBody() ?? [];

    $names = array_unique(array_filter(array_map('trim', (array) ($params['domains'] ?? []))));
    if (empty($names)) {
        $response->getBody()->write(json_encode(['error' => 'domains is required (array of domain names)']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $results = withEppSession(function ($nic) use ($names, $user_id) {
            $domain = new Net_EPP_IT_Domain($nic);
            $contact = new Net_EPP_IT_Contact($nic);
            $idnDecoder = new \Algo26\IdnaConvert\ToUnicode();

            $results = [];
            foreach ($names as $name) {
                $result = [
                    'step1_domain'     => 'unknown',
                    'step2_registrant' => 'unknown',
                    'step3_reg_store'  => 'unknown',
                    'step4_dom_store'  => 'unknown',
                ];

                // IT-NIC does not respond to queries for "xn--..." domain names!
                $name = $idnDecoder->convert(strtolower($name));

                if ( ! $domain->fetch($name)) {
                    $result['step1_domain'] = 'not found';
                    $domain->deleteDomainDB($name, $user_id, true);
                    $results[$name] = $result;
                    continue;
                }
                $result['step1_domain'] = 'found';

                if ( ! $contact->fetch($domain->get('registrant'))) {
                    $result['step2_registrant'] = 'not found';
                    $results[$name] = $result;
                    continue;
                }
                $result['step2_registrant'] = 'found';

                // if the registrant already exists locally, keep its current owner
                $registrant = R::getRow("SELECT user_id FROM contacts WHERE handle = ?", [$domain->get('registrant')]);
                $effectiveUserID = empty($registrant) ? $user_id : (int) $registrant['user_id'];
                $result['step3_reg_store'] = $contact->storeDB($effectiveUserID) ? 'stored' : 'not stored';

                if ($domain->storeDB($effectiveUserID)) {
                    $result['step4_dom_store'] = 'stored';
                    R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
                } else {
                    $result['step4_dom_store'] = 'not stored';
                }

                $results[$name] = $result;
            }
            return $results;
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['results' => $results]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->patch('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ($err = maxLength($params, DOMAIN_FIELD_MAX_LENGTHS)) {
        $response->getBody()->write(json_encode(['error' => $err]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $result = withEppSession(function ($nic) use ($name, $params, $user_id, $isAdmin) {
            $domain = new Net_EPP_IT_Domain($nic);
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
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus($result['status'])->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['domain' => domainToArray($result['domain'])]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/domains/{name}/registrant', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if (empty($params['registrant'])) {
        $response->getBody()->write(json_encode(['error' => 'registrant is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $result = withEppSession(function ($nic) use ($name, $params, $user_id, $isAdmin) {
            $domain = new Net_EPP_IT_Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }

            $domain->set('registrant', $params['registrant']);
            // updateRegistrant() requires authinfo to change alongside registrant --
            // rotate it (caller-supplied, or freshly generated) as part of the change
            $domain->set('authinfo', $params['authinfo'] ?? substr(md5(rand()), 0, 16));

            if ( ! $domain->updateRegistrant()) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            $domain->updateDB($name, $user_id, $isAdmin);
            return ['ok' => true, 'domain' => $domain];
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus($result['status'])->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['domain' => domainToArray($result['domain'])]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/domains/{name}/status', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if (empty($params['state'])) {
        $response->getBody()->write(json_encode(['error' => 'state is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    $action = $params['action'] ?? 'add';

    try {
        $result = withEppSession(function ($nic) use ($name, $params, $action) {
            $domain = new Net_EPP_IT_Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }
            if ( ! $domain->updateStatus($params['state'], $action)) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            return ['ok' => true, 'domain' => $domain];
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus($result['status'])->withHeader('Content-Type', 'application/json; charset=utf-8');
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
    changelogInsert('domains', $id, 'update', ['status' => $result['domain']->get('status')], $user_id);

    $response->getBody()->write(json_encode(['domain' => domainToArray($result['domain'])]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->delete('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $name = $args['name'];
    $params = $request->getQueryParams();
    $mode = $params['mode'] ?? 'now';

    if ($mode === 'expiry' || $mode === 'date') {
        $date = $mode === 'date' ? ($params['date'] ?? null) : null;
        if ($mode === 'date' && empty($date)) {
            $response->getBody()->write(json_encode(['error' => 'date is required when mode=date']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $row = R::getRow("SELECT id, ex_date FROM domains WHERE domain = :domain" . ($isAdmin ? '' : ' AND user_id = :user_id'), array_filter([
            ':domain' => $name,
            ':user_id' => $isAdmin ? null : $user_id,
        ], fn($v) => $v !== null));
        if (empty($row)) {
            $response->getBody()->write(json_encode(['error' => "Domain '{$name}' not found"]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
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

        $response->getBody()->write(json_encode(['scheduled' => true, 'domain' => $name, 'date' => $date ?: $row['ex_date']]));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $result = withEppSession(function ($nic) use ($name, $user_id, $isAdmin) {
            $domain = new Net_EPP_IT_Domain($nic);
            if ( ! $domain->delete($name)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $domain->deleteDomainDB($name, $user_id, $isAdmin);
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

    $response->getBody()->write(json_encode(['deleted' => true, 'domain' => $name]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/domains/{name}/restore', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $isAdmin = (int) $decoded->data->admin === 1;
    $name = $args['name'];

    try {
        $result = withEppSession(function ($nic) use ($name, $user_id, $isAdmin) {
            $domain = new Net_EPP_IT_Domain($nic);
            if ( ! $domain->restore($name)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $domain->restoreDomainDB($name, $user_id, $isAdmin);
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

    $response->getBody()->write(json_encode(['restored' => true, 'domain' => $name]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/domains/{name}/owner', function (Request $request, Response $response, array $args): Response {
    jwtRequireAdmin($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if (empty($params['user_id'])) {
        $response->getBody()->write(json_encode(['error' => 'user_id (the new owner) is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
    $newOwnerId = (int) $params['user_id'];

    $newOwner = R::getRow("SELECT id, techc FROM users WHERE id = ?", [$newOwnerId]);
    if (empty($newOwner)) {
        $response->getBody()->write(json_encode(['error' => "User id {$newOwnerId} not found"]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $result = withEppSession(function ($nic) use ($name, $newOwnerId, $newOwner) {
            $domain = new Net_EPP_IT_Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }

            // registrant and admin are always duplicated under the new owner
            $oldRegistrant = new Net_EPP_IT_Contact($nic);
            if ( ! $oldRegistrant->fetch($domain->get('registrant'))) {
                return ['ok' => false, 'status' => 400, 'error' => 'unable to fetch current registrant: ' . $oldRegistrant->getError()];
            }
            $newRegistrantHandle = duplicateContact($nic, $oldRegistrant, $newOwnerId);
            if ($newRegistrantHandle === false) {
                return ['ok' => false, 'status' => 400, 'error' => 'unable to duplicate registrant contact: ' . $oldRegistrant->getError()];
            }

            $newAdminHandle = null;
            $currentAdmin = $domain->get('admin');
            if ( ! empty($currentAdmin)) {
                $oldAdmin = new Net_EPP_IT_Contact($nic);
                if ( ! $oldAdmin->fetch($currentAdmin)) {
                    return ['ok' => false, 'status' => 400, 'error' => 'unable to fetch current admin contact: ' . $oldAdmin->getError()];
                }
                $newAdminHandle = duplicateContact($nic, $oldAdmin, $newOwnerId);
                if ($newAdminHandle === false) {
                    return ['ok' => false, 'status' => 400, 'error' => 'unable to duplicate admin contact: ' . $oldAdmin->getError()];
                }
            }

            // tech: use the new owner's own default tech contact (users.techc) if they
            // have one on file, otherwise duplicate the domain's current tech contact
            $newTechHandle = null;
            if ( ! empty($newOwner['techc'])) {
                $newTechHandle = trim($newOwner['techc']);
            } else {
                $currentTech = (array) $domain->get('tech');
                $firstTech = reset($currentTech);
                if ( ! empty($firstTech)) {
                    $oldTech = new Net_EPP_IT_Contact($nic);
                    if ($oldTech->fetch($firstTech)) {
                        $newTechHandle = duplicateContact($nic, $oldTech, $newOwnerId);
                    }
                }
            }

            // step 1: registrant change is its own EPP command, requiring authinfo to
            // change alongside it -- do this before anything else touches $domain
            $domain->set('registrant', $newRegistrantHandle);
            $domain->set('authinfo', substr(md5(rand()), 0, 16));
            if ( ! $domain->updateRegistrant()) {
                return ['ok' => false, 'status' => 400, 'error' => 'registrant change failed: ' . $domain->getError()];
            }

            // step 2: admin/tech changes -- a separate generic update(), since
            // updateRegistrant() ignores everything except registrant/authinfo/admin
            if ($newAdminHandle !== null) {
                $domain->set('admin', $newAdminHandle);
            }
            if ($newTechHandle !== null) {
                foreach ((array) $domain->get('tech') as $existingTech) {
                    $domain->remTECH($existingTech);
                }
                $domain->addTECH($newTechHandle);
            }
            if ($domain->get('changes') > 0) {
                if ( ! $domain->update()) {
                    return ['ok' => false, 'status' => 400, 'error' => 'admin/tech change failed: ' . $domain->getError()];
                }
            }

            // reassign local ownership
            R::exec("UPDATE domains SET user_id = ? WHERE domain = ?", [$newOwnerId, $name]);
            $id = (int) R::getCell("SELECT id FROM domains WHERE domain = ?", [$name]);
            changelogInsert('domains', $id, 'update', ['user_id' => $newOwnerId], $newOwnerId);

            return ['ok' => true, 'domain' => $domain];
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus($result['status'])->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['domain' => domainToArray($result['domain'])]));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->post('/v1/domains/{name}/transfer', function (Request $request, Response $response, array $args): Response {
    $decoded = jwtVerify($request);
    $user_id = (int) $decoded->data->id;
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if (empty($params['authinfo'])) {
        $response->getBody()->write(json_encode(['error' => 'authinfo is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    try {
        $result = withEppSession(function ($nic) use ($name, $params, $user_id) {
            $domain = new Net_EPP_IT_Domain($nic);
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
        });
    } catch (\RuntimeException $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(502)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    if ( ! $result['ok']) {
        $response->getBody()->write(json_encode(['error' => $result['error']]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $response->getBody()->write(json_encode(['requested' => true, 'domain' => $name]));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json; charset=utf-8');
});

foreach (['approve', 'reject', 'cancel'] as $transferAction) {
    $app->post("/v1/domains/{name}/transfer/{$transferAction}", function (Request $request, Response $response, array $args) use ($transferAction): Response {
        jwtVerify($request);
        $name = $args['name'];
        $params = $request->getParsedBody() ?? [];
        $authinfo = $params['authinfo'] ?? '';
        $method = 'transfer' . ucfirst($transferAction);

        try {
            $result = withEppSession(function ($nic) use ($name, $authinfo, $method) {
                $domain = new Net_EPP_IT_Domain($nic);
                if ( ! $domain->$method($name, $authinfo)) {
                    return ['ok' => false, 'error' => $domain->getError()];
                }
                if ($method !== 'transferCancel') {
                    R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
                }
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

        $response->getBody()->write(json_encode([$transferAction => true, 'domain' => $name]));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    });
}

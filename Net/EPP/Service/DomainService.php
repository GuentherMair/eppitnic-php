<?php

namespace Net\EPP\Service;

use Algo26\IdnaConvert\ToUnicode;
use Net\EPP\Client;
use Net\EPP\IT\Contact;
use Net\EPP\IT\Domain;
use RedBeanPHP\R;

/**
 * Domain operations that are more than one registry command, and that both the
 * REST API and the CLI need.
 *
 * These lived as closures inside route handlers, with the CLI scripts carrying
 * their own copy of the same sequence -- and the copies had already drifted.
 * Everything here takes an established session and returns data; deciding what
 * a caller is allowed to do, and how to report it, stays with the caller,
 * because a route answers that with an HTTP status and a command with an exit
 * code.
 *
 * @category    Net
 * @package     Net\EPP\Service\DomainService
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DomainService
{
    /**
     * Register a domain, or request a transfer of it if somebody already has it.
     *
     * The choice is the registry's to make, not the caller's: a <check> decides
     * which of the two commands is even possible, so asking for "create" on a
     * taken domain is answered by requesting its transfer rather than by an
     * error. That is what makes one entry point right for both.
     *
     * @param Client $nic a logged-in client
     * @param array $params domain, registrant, and optionally admin, tech[], ns[], authinfo
     * @param int $userId the local owner to record
     * @param bool $persist write the result to the local database
     * @return array{ok: bool, action?: string, domain?: Domain, error?: string}
     */
    public static function createOrTransfer(Client $nic, array $params, int $userId, bool $persist = true): array {
        $domain = new Domain($nic);
        $available = $domain->check($params['domain']);

        $domain->set('domain', $params['domain']);
        $domain->set('registrant', $params['registrant']);
        if ( ! empty($params['admin'])) {
            $domain->set('admin', $params['admin']);
        }
        foreach ((array) ($params['tech'] ?? []) as $tech) {
            $domain->addTECH($tech);
        }
        foreach ((array) ($params['ns'] ?? []) as $ns) {
            $domain->addNS($ns['name'] ?? $ns, $ns['ip'] ?? null);
        }
        $domain->set('authinfo', $params['authinfo'] ?? $domain->authinfo());

        if ($available === true) {
            if ( ! $domain->create()) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $action = 'created';
        } elseif ($available === false) {
            if ( ! $domain->transfer($params['domain'], $domain->get('authinfo'))) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $action = 'transfer-requested';
        } else {
            // -1/-2 from check(): the availability question was never answered,
            // so neither command can be chosen
            return ['ok' => false, 'error' => $domain->getError()];
        }

        if ($persist) {
            // a fresh registration is a DNS-sync 'create' event; a requested
            // transfer-in is NOT -- that only becomes real once PollProcessor
            // sees it complete
            $domain->storeDB($userId, $action === 'created');
        }

        return ['ok' => true, 'action' => $action, 'domain' => $domain];
    }

    /**
     * Copy domains the registry already holds into the local database, along
     * with their registrant contacts.
     *
     * @param Client $nic a logged-in client
     * @param string[] $names domains to import
     * @param int $userId the owner for rows that do not exist locally yet
     * @return array<string, array<string, string>> per domain, the outcome of each step
     */
    public static function import(Client $nic, array $names, int $userId): array {
        $idnDecoder = new ToUnicode();
        $results = [];

        foreach ($names as $name) {
            // key names are the documented response shape of
            // POST /v1/domains/import (API.md) -- awkward, but a contract
            $result = [
                'step1_domain'     => 'unknown',
                'step2_registrant' => 'unknown',
                'step3_reg_store'  => 'unknown',
                'step4_dom_store'  => 'unknown',
            ];

            // IT-NIC does not answer queries for "xn--..." names
            $name = $idnDecoder->convert(strtolower($name));

            // a fresh object per name: fetch() re-initialises, but a failed
            // fetch would otherwise leave the previous domain's data in place
            $domain = new Domain($nic);
            $contact = new Contact($nic);

            if ( ! $domain->fetch($name)) {
                $result['step1_domain'] = 'not found';
                // the registry does not have it, so neither should we
                $domain->deleteDomainDB($name, $userId, true);
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
            $effectiveUserId = empty($registrant) ? $userId : (int) $registrant['user_id'];

            $result['step3_reg_store'] = $contact->storeDB($effectiveUserId) ? 'stored' : 'not stored';

            if ($domain->storeDB($effectiveUserId)) {
                $result['step4_dom_store'] = 'stored';
                // whatever transfer request brought it here has completed
                R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
            } else {
                $result['step4_dom_store'] = 'not stored';
            }

            $results[$name] = $result;
        }

        return $results;
    }
}

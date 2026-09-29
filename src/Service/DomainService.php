<?php

namespace Eppitnic\Service;

use Algo26\IdnaConvert\ToUnicode;
use Eppitnic\Api\Access;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Scope;
use Eppitnic\Support\Warnings;
use RedBeanPHP\R;

/**
 * Domain operations taking more than one registry command, needed by the API
 * and the CLI alike -- previously a closure in each, already drifted. All take
 * a session and return data; reporting stays with the caller.
 *
 * @category    Net
 * @package     Eppitnic\Service\DomainService
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DomainService
{
    /**
     * Register a domain, or request its transfer if somebody already has it.
     * The registry chooses, not the caller: a <check> decides which command is
     * possible, so "create" on a taken domain requests a transfer instead.
     *
     * @param Client $nic a logged-in client
     * @param array $params domain, registrant, and optionally admin, tech[],
     *              ns[], authinfo
     * @param int $actorId the acting user, for history; the domain belongs
     *              to its registrant's reseller (Domain::storeDB())
     * @param bool $persist write the result to the local database
     * @return array{ok: bool, action?: string, domain?: Domain, error?: string, warnings?: string[]}
     */
    public static function createOrTransfer(Client $nic, array $params, int $actorId, bool $persist = true): array {
        $domain = new Domain($nic);
        $availability = $domain->check($params['domain']);
        if ( ! $availability->answered()) {
            // the availability question was never answered, so neither command
            // can be chosen -- create() would collide, transfer() would fail
            return ['ok' => false, 'error' => $availability->error()];
        }

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
        $warnings = [];

        if ($availability->available()) {
            // prepared first, so the registry's DNS check finds it answering;
            // never on a dry run, which persists nothing
            $zone = $persist ? PowerDnsZones::provision($params['domain'], array_keys((array) $domain->get('ns'))) : null;
            if ($zone !== null && $zone['warning'] !== null) {
                $warnings[] = $zone['warning'];
            }
            if ( ! $domain->create()) {
                if ($zone !== null) {
                    PowerDnsZones::undo($params['domain'], $zone, []);
                }
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $action = 'created';
        } else {
            if ( ! $domain->transfer($params['domain'], $domain->get('authinfo'))) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $action = 'transfer-requested';
        }

        if ($persist) {
            // a fresh registration is a DNS-sync 'create' event; a requested
            // transfer-in is NOT -- that only becomes real once PollProcessor
            // sees it complete
            if ( ! $domain->storeDB($actorId, $action === 'created')) {
                $warnings[] = Warnings::localWrite("domain '{$params['domain']}'", $domain->getError());
            }
        }

        return ['ok' => true, 'action' => $action, 'domain' => $domain, 'warnings' => $warnings];
    }

    /**
     * Copy domains the registry already holds into the local database, along
     * with their registrant contacts.
     *
     * @param Client $nic a logged-in client
     * @param string[] $names domains to import
     * @param Scope $scope who imports: a registrant not stored locally yet
     *              goes to their reseller; a non-admin never touches a domain
     *              or registrant another reseller holds
     * @return array<string, array{domain: string, registrant: string, contact_stored: string, domain_stored: string}>
     *         per domain, each step's outcome: 'found'/'not found'/'held by
     *         another reseller', 'stored'/'not stored', or 'skipped' if an
     *         earlier step stopped it
     */
    public static function import(Client $nic, array $names, Scope $scope): array {
        $idnDecoder = new ToUnicode();
        $results = [];

        foreach ($names as $name) {
            // A step never reached says 'skipped' rather than sharing a value
            // with one that ran and failed, so the first non-'skipped' failure
            // is where it stopped
            $result = [
                'domain'         => 'skipped',
                'registrant'     => 'skipped',
                'contact_stored' => 'skipped',
                'domain_stored'  => 'skipped',
            ];

            // IT-NIC does not answer queries for "xn--..." names
            $name = $idnDecoder->convert(strtolower($name));

            // a fresh object per name: fetch() re-initialises, but a failed
            // fetch would otherwise leave the previous domain's data in place
            $domain = new Domain($nic);
            $contact = new Contact($nic);

            // another reseller's domain is theirs to reconcile, not the caller's
            if (Access::domainHeldByAnotherReseller($name, $scope)) {
                $result['domain'] = 'held by another reseller';
                $results[$name] = $result;
                continue;
            }

            if ( ! $domain->fetch($name)) {
                $result['domain'] = 'not found';
                // the registry does not have it, so neither should we
                $domain->deleteDomainDB($name, $scope);
                $results[$name] = $result;
                continue;
            }
            $result['domain'] = 'found';

            if ( ! $contact->fetch($domain->get('registrant'))) {
                $result['registrant'] = 'not found';
                $results[$name] = $result;
                continue;
            }
            // the domain would follow its registrant into another reseller
            $holder = R::getCell('SELECT reseller_id FROM contacts WHERE handle = ?', [$domain->get('registrant')]);
            if ( ! $scope->isAdmin() && $holder !== null && (int) $holder !== $scope->resellerId) {
                $result['registrant'] = 'held by another reseller';
                $results[$name] = $result;
                continue;
            }
            $result['registrant'] = 'found';

            // a registrant already stored locally keeps its reseller, and the
            // domain follows it (Domain::storeDB())
            $result['contact_stored'] = $contact->storeDB($scope->resellerId, $scope->userId) ? 'stored' : 'not stored';

            if ($domain->storeDB($scope->userId)) {
                $result['domain_stored'] = 'stored';
                // whatever transfer request brought it here has completed
                R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
            } else {
                $result['domain_stored'] = 'not stored';
            }

            $results[$name] = $result;
        }

        return $results;
    }

    /**
     * Move a domain to another reseller with its own copies of the contacts:
     * both ownerships must move together. Not atomic -- the contacts exist
     * before the domain points at them, so a failure leaves them unused.
     *
     * @param Client $nic a logged-in client
     * @param string $name the domain to move
     * @param int $resellerId the reseller to move it to
     * @param int $actorId the acting user, for history
     * @param bool $persist reassign local ownership too
     * @return array{ok: bool, domain?: Domain, error?: string, status?: int}
     */
    public static function changeOwner(Client $nic, string $name, int $resellerId, int $actorId, bool $persist = true): array {
        $newOwner = R::getRow("SELECT id, techc FROM resellers WHERE id = ?", [$resellerId]);
        if (empty($newOwner)) {
            return ['ok' => false, 'status' => 404, 'error' => "Reseller {$resellerId} not found"];
        }

        $domain = new Domain($nic);
        if ( ! $domain->fetch($name)) {
            return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
        }

        // registrant and admin are always duplicated under the new owner
        $oldRegistrant = new Contact($nic);
        if ( ! $oldRegistrant->fetch($domain->get('registrant'))) {
            return ['ok' => false, 'status' => 400, 'error' => 'unable to fetch current registrant: ' . $oldRegistrant->getError()];
        }
        $newRegistrantHandle = $oldRegistrant->duplicate($nic, $resellerId, $actorId);
        if ($newRegistrantHandle === false) {
            return ['ok' => false, 'status' => 400, 'error' => 'unable to duplicate registrant contact: ' . $oldRegistrant->getError()];
        }

        $newAdminHandle = null;
        $currentAdmin = $domain->get('admin');
        if ( ! empty($currentAdmin)) {
            $oldAdmin = new Contact($nic);
            if ( ! $oldAdmin->fetch($currentAdmin)) {
                return ['ok' => false, 'status' => 400, 'error' => 'unable to fetch current admin contact: ' . $oldAdmin->getError()];
            }
            $newAdminHandle = $oldAdmin->duplicate($nic, $resellerId, $actorId);
            if ($newAdminHandle === false) {
                return ['ok' => false, 'status' => 400, 'error' => 'unable to duplicate admin contact: ' . $oldAdmin->getError()];
            }
        }

        // tech: the new reseller's own default tech contacts (resellers.techc)
        // if it has any on file, otherwise a duplicate of the domain's current one
        $newTechHandles = ResellerSettings::decodeTech($newOwner['techc']);
        if ($newTechHandles === []) {
            $currentTech = (array) $domain->get('tech');
            $firstTech = reset($currentTech);
            if ( ! empty($firstTech)) {
                $oldTech = new Contact($nic);
                if ($oldTech->fetch($firstTech)) {
                    $duplicate = $oldTech->duplicate($nic, $resellerId, $actorId);
                    $newTechHandles = $duplicate === false ? [] : [$duplicate];
                }
            }
        }

        // the registrant change is its own EPP command, requiring authinfo to
        // change alongside it -- before anything else touches $domain
        $domain->set('registrant', $newRegistrantHandle);
        $domain->set('authinfo', $domain->authinfo());
        if ( ! $domain->updateRegistrant()) {
            return ['ok' => false, 'status' => 400, 'error' => 'registrant change failed: ' . $domain->getError()];
        }

        // admin/tech go in a separate generic update(), since
        // updateRegistrant() ignores everything but registrant/authinfo/admin
        if ($newAdminHandle !== null) {
            $domain->set('admin', $newAdminHandle);
        }
        if ($newTechHandles !== []) {
            foreach ((array) $domain->get('tech') as $existingTech) {
                $domain->remTECH($existingTech);
            }
            foreach ($newTechHandles as $newTechHandle) {
                $domain->addTECH($newTechHandle);
            }
        }
        if ($domain->hasChanges() && ! $domain->update()) {
            return ['ok' => false, 'status' => 400, 'error' => 'admin/tech change failed: ' . $domain->getError()];
        }

        if ($persist) {
            R::exec("UPDATE domains SET reseller_id = ?, registrant = ? WHERE domain = ?", [$resellerId, $newRegistrantHandle, $name]);
            $id = (int) R::getCell("SELECT id FROM domains WHERE domain = ?", [$name]);
            History::record('domains', $id, 'update', ['reseller_id' => $resellerId, 'registrant' => $newRegistrantHandle], $actorId);
        }

        return ['ok' => true, 'domain' => $domain];
    }

    /**
     * Normalise the nameservers of a domain update: each entry is a name, or
     * an object {name, ip?} whose `ip` holds one or two glue addresses.
     *
     * @return array|string list of {name: string, ip: string[]|null} (null
     *         when the entry carries no `ip`), or the error
     */
    public static function nameserverEntries(mixed $nameservers): array|string {
        if ( ! is_array($nameservers)) {
            return 'ns must be a list of nameservers';
        }
        $entries = [];
        foreach ($nameservers as $i => $ns) {
            $name = is_array($ns) ? ($ns['name'] ?? null) : $ns;
            if ( ! is_string($name) || trim($name) === '') {
                return "ns[{$i}] needs a name";
            }
            $ip = is_array($ns) ? ($ns['ip'] ?? null) : null;
            if ($ip !== null) {
                $ip = is_array($ip) ? array_values($ip) : [$ip];
                foreach ($ip as $address) {
                    if ( ! is_string($address)) {
                        return "ns[{$i}].ip must be a list of addresses";
                    }
                }
            }
            $entries[] = ['name' => trim($name), 'ip' => $ip];
        }
        return $entries;
    }

    /**
     * Validate the DS records of a domain update: each needs keytag,
     * algorithm, digesttype and digest as non-empty strings or numbers.
     *
     * @return array|string the records with string values, or the error
     */
    public static function dnssecRecords(mixed $records): array|string {
        if ( ! is_array($records)) {
            return 'dnssec must be a list of DS records';
        }
        $entries = [];
        foreach ($records as $i => $ds) {
            if ( ! is_array($ds)) {
                return "dnssec[{$i}] must be an object";
            }
            $entry = [];
            foreach (['keytag', 'algorithm', 'digesttype', 'digest'] as $key) {
                $value = $ds[$key] ?? null;
                if ( ! is_scalar($value) || is_bool($value) || (string) $value === '') {
                    return "dnssec[{$i}].{$key} is required and must be a non-empty string or number";
                }
                $entry[$key] = (string) $value;
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Make the domain's DS set equal to $records (from dnssecRecords()).
     * Removals come first: the registry holds at most two, so a key rollover
     * has to free the slots before it fills them.
     *
     * @return string|null the error, or null when the set is as requested
     */
    public static function applyDnssec(Domain $domain, array $records): ?string {
        $current = (array) $domain->get('dnssec');
        $target = [];
        foreach ($records as $ds) {
            $target[$ds['digest']] = $ds;
        }

        foreach (array_diff_key($current, $target) as $digest => $unused) {
            if ($domain->remDNSSEC((string) $digest) === false) {
                return $domain->getError();
            }
        }
        foreach ($target as $digest => $ds) {
            $held = $current[$digest] ?? null;
            if ($held !== null
                && (string) $held['keytag'] === $ds['keytag']
                && (string) $held['algorithm'] === $ds['algorithm']
                && (string) $held['digesttype'] === $ds['digesttype']) {
                continue;
            }
            if ($domain->addDNSSEC($ds['keytag'], $ds['algorithm'], $ds['digesttype'], $ds['digest']) === false) {
                return $domain->getError();
            }
        }
        return null;
    }
}

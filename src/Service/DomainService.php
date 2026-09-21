<?php

namespace Eppitnic\Service;

use Algo26\IdnaConvert\ToUnicode;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\History;
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
     * @param int $userId the local owner to record
     * @param bool $persist write the result to the local database
     * @return array{ok: bool, action?: string, domain?: Domain, error?: string}
     */
    public static function createOrTransfer(Client $nic, array $params, int $userId, bool $persist = true): array {
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

        if ($availability->available()) {
            if ( ! $domain->create()) {
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
     * @return array<string, array{domain: string, registrant: string, contact_stored: string, domain_stored: string}>
     *         per domain, each step's outcome: 'found'/'not found',
     *         'stored'/'not stored', or 'skipped' if an earlier step stopped it
     */
    public static function import(Client $nic, array $names, int $userId): array {
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

            if ( ! $domain->fetch($name)) {
                $result['domain'] = 'not found';
                // the registry does not have it, so neither should we
                $domain->deleteDomainDB($name, $userId, true);
                $results[$name] = $result;
                continue;
            }
            $result['domain'] = 'found';

            if ( ! $contact->fetch($domain->get('registrant'))) {
                $result['registrant'] = 'not found';
                $results[$name] = $result;
                continue;
            }
            $result['registrant'] = 'found';

            // if the registrant already exists locally, keep its current owner
            $registrant = R::getRow("SELECT user_id FROM contacts WHERE handle = ?", [$domain->get('registrant')]);
            $effectiveUserId = empty($registrant) ? $userId : (int) $registrant['user_id'];

            $result['contact_stored'] = $contact->storeDB($effectiveUserId) ? 'stored' : 'not stored';

            if ($domain->storeDB($effectiveUserId)) {
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
     * Move a domain to another local user with its own copies of the contacts:
     * both ownerships must move together. Not atomic -- the contacts exist
     * before the domain points at them, so a failure leaves them unused.
     *
     * @param Client $nic a logged-in client
     * @param string $name the domain to move
     * @param int $newOwnerId the local user to move it to
     * @param bool $persist reassign local ownership too
     * @return array{ok: bool, domain?: Domain, error?: string, status?: int}
     */
    public static function changeOwner(Client $nic, string $name, int $newOwnerId, bool $persist = true): array {
        $newOwner = R::getRow("SELECT id, techc FROM users WHERE id = ?", [$newOwnerId]);
        if (empty($newOwner)) {
            return ['ok' => false, 'status' => 404, 'error' => "User id {$newOwnerId} not found"];
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
        $newRegistrantHandle = $oldRegistrant->duplicate($nic, $newOwnerId);
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
            $newAdminHandle = $oldAdmin->duplicate($nic, $newOwnerId);
            if ($newAdminHandle === false) {
                return ['ok' => false, 'status' => 400, 'error' => 'unable to duplicate admin contact: ' . $oldAdmin->getError()];
            }
        }

        // tech: the new owner's own default tech contacts (users.techc) if they
        // have any on file, otherwise a duplicate of the domain's current one
        $newTechHandles = UserSettings::decodeTech($newOwner['techc']);
        if ($newTechHandles === []) {
            $currentTech = (array) $domain->get('tech');
            $firstTech = reset($currentTech);
            if ( ! empty($firstTech)) {
                $oldTech = new Contact($nic);
                if ($oldTech->fetch($firstTech)) {
                    $duplicate = $oldTech->duplicate($nic, $newOwnerId);
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
            R::exec("UPDATE domains SET user_id = ? WHERE domain = ?", [$newOwnerId, $name]);
            $id = (int) R::getCell("SELECT id FROM domains WHERE domain = ?", [$name]);
            History::record('domains', $id, 'update', ['user_id' => $newOwnerId], $newOwnerId);
        }

        return ['ok' => true, 'domain' => $domain];
    }
}

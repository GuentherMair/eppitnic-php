<?php

namespace Eppitnic\Selftest\Scenario;

use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Selftest\ContactFactory;
use Eppitnic\Selftest\Run;
use Eppitnic\Selftest\Scenario;

/**
 * A domain from registration to deletion, with the contacts it needs.
 *
 * Six contacts, because the interesting operations are the ones that *change*
 * a domain: a second admin and a second tech contact to swap the first pair
 * for, and a second registrant to hand the domain to. A run that created only
 * what a registration needs could never exercise an update.
 *
 * Note the shape of the ending. The domain delete is the last thing that can
 * be verified; the contact deletes after it are attempts, not assertions --
 * and they split in two. The three the domain was still carrying when it was
 * deleted stay linked until it has finished its 30 days of redemptionPeriod
 * and pendingDelete, so those are expected to be refused and are recorded as
 * deferred rather than failed; `eppitnic selftest reap` clears them later. The
 * three the update and the registrant change had already swapped off it are
 * associated with nothing and delete immediately, which is why onDomain is
 * kept up to date rather than assumed.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Scenario\DomainLifecycle
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DomainLifecycle implements Scenario
{
    /** every contact handle this scenario made, in the order it made them */
    private array $made = [];

    /**
     * What the domain carries right now, by role.
     *
     * Kept up to date through the update and the registrant change, because
     * only what is still on the domain when it is deleted is held by the purge
     * -- a contact swapped off it earlier is associated with nothing and can
     * be removed straight away.
     *
     * @var array<string, string> role => handle
     */
    private array $onDomain = [];

    /**
     * @param string[] $nameservers at least two; the third, if given, is what
     *                 the update swaps the second for
     * @param string|null $domain register this name instead of a generated
     *                    one, so its zone can be prepared in advance
     * @param int $verificationWait seconds to leave the registry to check the
     *            delegation before reading it back; 0 not to wait
     */
    public function __construct(
        private readonly array $nameservers,
        private readonly ?string $domain = null,
        private readonly int $verificationWait = 0,
    ) {}

    public function name(): string {
        return 'Domain lifecycle';
    }

    public function execute(Run $run): void {
        $registrant = $this->contact($run, 'R', 1, true);
        $admin      = $this->contact($run, 'A', 1, false);
        $tech       = $this->contact($run, 'T', 1, false);

        if ($registrant === null || $admin === null || $tech === null) {
            $run->skip('domain create', '', 'the contacts a domain needs could not be created');
            return;
        }

        $name = $this->domain ?? $run->names->domain();

        if ($this->createIt($run, $name, $registrant, $admin, $tech)) {
            $this->awaitVerification($run, $name);
            $this->readItBack($run, $name, $registrant, $admin, $tech);
            $this->updateIt($run, $name, $tech);
            $this->changeRegistrant($run, $name);
            $this->deleteIt($run, $name);
        }

        $this->attemptContactCleanup($run);
    }

    // -----------------------------------------------------------------
    // the contacts it needs
    // -----------------------------------------------------------------

    /**
     * @return string|null the handle, or null if it could not be created
     */
    private function contact(Run $run, string $role, int $index, bool $isRegistrant): ?string {
        $handle = $run->names->handle($role, $index);
        $what = $isRegistrant ? 'registrant' : ($role === 'A' ? 'admin' : 'tech');

        $ok = $run->step("contact create ({$what})", $handle, function () use ($run, $handle, $isRegistrant, $what) {
            $contact = new Contact($run->nic);
            ContactFactory::fill($contact, $handle, $isRegistrant);

            $run->ensure($contact->create(), $contact);
            $run->noteContact($handle, $what);

            return $isRegistrant ? 'entity type 2, with a registration code' : 'entity type 0';
        });

        if ( ! $ok) {
            return null;
        }
        $this->made[] = $handle;

        return $handle;
    }

    // -----------------------------------------------------------------
    // create
    // -----------------------------------------------------------------

    private function createIt(Run $run, string $name, string $registrant, string $admin, string $tech): bool {
        $run->step('domain check', $name, function () use ($run, $name) {
            $domain = new Domain($run->nic);
            $answer = $domain->check($name);

            $run->expect($answer->answered(), 'the registry did not answer the check: ' . $answer->reason());
            $run->expect($answer->available() === true, 'the name is already taken: ' . $answer->reason());

            return 'free';
        });

        $nameservers = array_slice($this->nameservers, 0, 2);

        $created = $run->step('domain create', $name, function () use ($run, $name, $registrant, $admin, $tech, $nameservers) {
            $domain = new Domain($run->nic);
            $domain->set('domain', $name);
            $domain->set('registrant', $registrant);
            $domain->set('admin', $admin);
            $domain->addTECH($tech);
            foreach ($nameservers as $ns) {
                $domain->addNS($ns);
            }

            $run->ensure($domain->create(), $domain);
            $run->noteDomain($name);
            $this->onDomain = ['registrant' => $registrant, 'admin' => $admin, 'tech' => $tech];

            return 'authinfo ' . $domain->get('authinfo') . ', ns ' . implode(' ', $nameservers);
        });

        if ($created) {
            $run->step('domain check again', $name, function () use ($run, $name) {
                $domain = new Domain($run->nic);
                $answer = $domain->check($name);

                $run->expect($answer->answered(), 'the registry did not answer the check: ' . $answer->reason());
                $run->expect($answer->available() === false, 'the name is still free after being registered');

                return 'taken, as it should be';
            });
        }

        return $created;
    }

    // -----------------------------------------------------------------
    // read it back
    // -----------------------------------------------------------------

    private function readItBack(Run $run, string $name, string $registrant, string $admin, string $tech): void {
        $expected = array_slice($this->nameservers, 0, 2);

        $run->step('domain info', $name, function () use ($run, $name, $registrant, $admin, $tech, $expected) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            $run->expect(
                (string) $domain->get('registrant') === $registrant,
                "registrant came back as '" . $domain->get('registrant') . "'"
            );
            $run->expect(
                (string) $domain->get('admin') === $admin,
                "admin came back as '" . $domain->get('admin') . "'"
            );
            $run->expect(
                in_array($tech, (array) $domain->get('tech'), true),
                'the technical contact is not among those the registry holds'
            );

            return 'registrant, admin and tech match' . self::checkNameservers($run, $domain, $expected);
        });
    }

    // -----------------------------------------------------------------
    // update: a second admin, a second tech, a different nameserver
    // -----------------------------------------------------------------

    private function updateIt(Run $run, string $name, string $firstTech): void {
        $admin = $this->contact($run, 'A', 2, false);
        $tech  = $this->contact($run, 'T', 2, false);

        if ($admin === null || $tech === null) {
            $run->skip('domain update', $name, 'the replacement contacts could not be created');
            return;
        }

        // Where the nameservers should end up: the first kept, the second
        // swapped for the third. Stated as the whole target set rather than as
        // a remove/add pair, because the registry does not report a nameserver
        // until it has passed its DNS checks -- so fetch() often finds none,
        // and `remNS(second); addNS(third)` against nothing leaves exactly one
        // nameserver, which is refused as 9005 "Too few name servers".
        $swapped = count($this->nameservers) >= 3;
        $target = $swapped
            ? [$this->nameservers[0], $this->nameservers[2]]
            : array_slice($this->nameservers, 0, 2);

        $applied = $run->step('domain update', $name, function () use ($run, $name, $admin, $tech, $firstTech, $target, $swapped) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            $domain->set('admin', $admin);
            $domain->remTECH($firstTech);
            $domain->addTECH($tech);

            self::reconcileNameservers($domain, $target);

            $run->ensure($domain->update(), $domain);
            $this->onDomain['admin'] = $admin;
            $this->onDomain['tech'] = $tech;

            return $swapped
                ? 'admin and tech swapped, nameservers now ' . implode(' ', $target)
                : 'admin and tech swapped';
        });

        if ( ! $swapped) {
            $run->skip('nameserver swap', $name, 'only two nameservers were given; a third is needed to swap one');
        }
        if ( ! $applied) {
            return;
        }
        $this->awaitVerification($run, $name);

        $run->step('domain info after update', $name, function () use ($run, $name, $admin, $tech, $firstTech, $target) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            $run->expect(
                (string) $domain->get('admin') === $admin,
                "admin came back as '" . $domain->get('admin') . "'"
            );

            $held = (array) $domain->get('tech');
            $run->expect(in_array($tech, $held, true), 'the new technical contact is not there');
            $run->expect( ! in_array($firstTech, $held, true), 'the old technical contact is still there');

            return 'contacts swapped' . self::checkNameservers($run, $domain, $target);
        });
    }

    // -----------------------------------------------------------------
    // nameservers, which the registry may not admit to holding
    // -----------------------------------------------------------------

    /**
     * Leave the registry time to check the delegation before asking what it
     * holds.
     *
     * Only worth doing when a real domain was named. nic.it validates
     * nameservers out of band and does not report them until they pass, so
     * reading back immediately shows nothing whether or not the delegation is
     * good -- which makes the check meaningless rather than merely slow. With
     * a generated name the delegation can never resolve anyway, so there is
     * nothing to wait for and the run stays fast.
     */
    private function awaitVerification(Run $run, string $name): void {
        if ($this->verificationWait <= 0) {
            return;
        }

        $run->step('await dns verification', $name, function () {
            sleep($this->verificationWait);

            return 'gave the registry ' . $this->verificationWait
                . 's to check the delegation before reading it back';
        });
    }

    /**
     * Bring the domain's nameservers to exactly $target.
     *
     * A diff against what fetch() found is not enough: nic.it does not report
     * a nameserver until it has passed the registry's DNS checks (see the note
     * in Domain::fetch()), so a delegation that does not resolve reads back as
     * empty however the domain was created. Removing one name and adding
     * another against that empty set leaves a single nameserver, and the
     * registry refuses anything under two.
     *
     * @param string[] $target what the domain should carry afterwards
     */
    private static function reconcileNameservers(Domain $domain, array $target): void {
        foreach (array_keys((array) $domain->get('ns')) as $held) {
            if ( ! in_array($held, $target, true)) {
                $domain->remNS($held);
            }
        }
        // addNS() is idempotent for a name already present with the same
        // addresses, so this neither duplicates nor dirties anything
        foreach ($target as $nameserver) {
            $domain->addNS($nameserver);
        }
    }

    /**
     * Check the nameservers the registry admits to holding, if it admits to
     * any.
     *
     * Reporting none is not a failure. It is what nic.it answers for a
     * delegation its DNS checks have not passed, and the default names point
     * at example.it, which is reserved for documentation and answers nothing
     * -- so this is the ordinary case unless `--ns` named something real. What
     * would be a fault is the registry holding nameservers that are not the
     * ones asked for, and that is still checked.
     *
     * @param string[] $expected what was asked for
     * @return string what to append to the step's note
     */
    private static function checkNameservers(Run $run, Domain $domain, array $expected): string {
        $held = array_keys((array) $domain->get('ns'));

        if ($held === []) {
            return '; nameservers not reported (the registry has not validated the delegation)';
        }

        sort($held);
        sort($expected);
        $run->expect($held === $expected, 'nameservers came back as ' . implode(' ', $held));

        return '; nameservers ' . implode(' ', $held);
    }

    // -----------------------------------------------------------------
    // registrant change
    // -----------------------------------------------------------------

    /**
     * The registrant change is its own EPP command, and the registry refuses
     * one that does not rotate the authinfo along with it -- so this both
     * changes the registrant and proves the new authinfo took, which an
     * ordinary update could not.
     */
    private function changeRegistrant(Run $run, string $name): void {
        $registrant = $this->contact($run, 'R', 2, true);

        if ($registrant === null) {
            $run->skip('domain set-registrant', $name, 'the second registrant could not be created');
            return;
        }

        $authinfo = null;

        $applied = $run->step('domain set-registrant', $name, function () use ($run, $name, $registrant, &$authinfo) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            $domain->set('registrant', $registrant);
            $domain->set('authinfo', $authinfo = $domain->authinfo());

            $run->ensure($domain->updateRegistrant(), $domain);
            $this->onDomain['registrant'] = $registrant;

            return "registrant is now {$registrant}, authinfo rotated";
        });

        if ( ! $applied) {
            return;
        }

        $run->step('domain info after set-registrant', $name, function () use ($run, $name, $registrant, &$authinfo) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->fetch($name), $domain);

            $run->expect(
                (string) $domain->get('registrant') === $registrant,
                "registrant came back as '" . $domain->get('registrant') . "'"
            );
            $run->expect(
                (string) $domain->get('authinfo') === $authinfo,
                'the rotated authinfo is not what the registry holds'
            );

            return 'the domain belongs to the second registrant';
        });
    }

    // -----------------------------------------------------------------
    // delete, and the cleanup that cannot finish today
    // -----------------------------------------------------------------

    private function deleteIt(Run $run, string $name): void {
        $run->step('domain delete', $name, function () use ($run, $name) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->delete($name), $domain);
            $run->forgetDomain($name);

            // whatever it was carrying is now held until the registry purges
            // it; everything else this scenario made is free straight away
            $run->blockContacts(array_values($this->onDomain));

            return 'accepted; the registry now holds it in pendingDelete';
        });
    }

    /**
     * Try to delete every contact this scenario made.
     *
     * All of them are expected to be refused: each is attached to the domain
     * that was just deleted, and stays attached until it is purged. The
     * attempt is made anyway, because one that succeeds says something changed
     * at the registry that this code assumes has not.
     */
    private function attemptContactCleanup(Run $run): void {
        $held = array_values($this->onDomain);

        foreach ($this->made as $handle) {
            $why = in_array($handle, $held, true)
                ? 'still linked to the deleted domain; `selftest reap` clears it once the domain is purged'
                : 'not deleted; `selftest reap` will retry it';

            $run->deferrable(
                'contact delete',
                $handle,
                $why,
                function () use ($run, $handle) {
                    $contact = new Contact($run->nic);
                    $run->ensure($contact->delete($handle), $contact);
                    $run->forgetContact($handle);

                    return 'deleted -- it was already free';
                }
            );
        }
    }
}

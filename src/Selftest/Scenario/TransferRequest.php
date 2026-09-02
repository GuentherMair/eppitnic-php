<?php

namespace Eppitnic\Selftest\Scenario;

use Eppitnic\Epp\Domain;
use Eppitnic\Selftest\Run;
use Eppitnic\Selftest\Scenario;

/**
 * Claim a domain somebody else holds. Never part of a default run: it needs
 * another registrar's domain and that holder's authinfo, and cannot clean up --
 * a request is withdrawn with `domain transfer cancel`, not deleted.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Scenario\TransferRequest
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class TransferRequest implements Scenario
{
    /** what the registry calls a transfer that is waiting on the other side */
    private const PENDING = 'pending';

    public function __construct(
        private readonly string $domain,
        private readonly string $authinfo,
    ) {}

    public function name(): string {
        return 'Transfer request (transfer-in)';
    }

    public function execute(Run $run): void {
        if ( ! $this->confirmItExists($run)) {
            return;
        }

        $this->statusBefore($run);

        if ($this->request($run)) {
            $this->statusAfter($run);
        }
    }

    /**
     * A domain that can be transferred in is one that is *not* available. The
     * check costs one round trip and turns the commonest mistake -- a typo in
     * the name -- into a clear answer instead of an authorization error.
     */
    private function confirmItExists(Run $run): bool {
        return $run->step('domain check', $this->domain, function () use ($run) {
            $domain = new Domain($run->nic);
            $answer = $domain->check($this->domain);

            $run->expect($answer->answered(), 'the registry did not answer the check: ' . $answer->reason());
            $run->expect(
                $answer->available() === false,
                'the name is available, so there is nobody to transfer it from'
            );

            return 'registered, as a transfer target must be';
        });
    }

    /**
     * Before the request there should be no transfer pending, and the registry
     * says so by refusing the query. That refusal is the expected answer here,
     * which is why it is not a failure.
     */
    private function statusBefore(Run $run): void {
        $run->deferrable(
            'transfer status before',
            $this->domain,
            'no transfer is pending yet, which is the expected state',
            function () use ($run) {
                $domain = new Domain($run->nic);
                $run->ensure($domain->transferStatus($this->domain, $this->authinfo), $domain);

                return 'already ' . $domain->get('trStatus')
                    . ' (requested by ' . $domain->get('reID') . ')';
            }
        );
    }

    private function request(Run $run): bool {
        return $run->step('transfer request', $this->domain, function () use ($run) {
            $domain = new Domain($run->nic);

            // no new registrant: that would make it a trade, which is a
            // different operation with its own extension and its own price
            $run->ensure($domain->transfer($this->domain, $this->authinfo), $domain);

            return 'accepted; the losing registrar has been notified';
        });
    }

    private function statusAfter(Run $run): void {
        $run->step('transfer status after', $this->domain, function () use ($run) {
            $domain = new Domain($run->nic);
            $run->ensure($domain->transferStatus($this->domain, $this->authinfo), $domain);

            $status = (string) $domain->get('trStatus');
            $run->expect(
                str_starts_with(strtolower($status), self::PENDING),
                "the registry reports '{$status}', not a pending transfer"
            );

            return "{$status}; withdraw it with `eppitnic domain transfer cancel {$this->domain}`";
        });
    }
}

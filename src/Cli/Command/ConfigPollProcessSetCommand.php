<?php

namespace Eppitnic\Cli\Command;

/**
 * Set (or reset to its default) `poll_process`'s fields: enabled, frequency_minutes.
 * Disabling it stops the shared EPP password from auto-rotating on a
 * passwdReminder, alongside the queue drain and transfer reconciliation --
 * a real foot-gun, but the operator's call to make. See
 * AbstractCronjobSetCommand and CronjobSettings.
 */
final class ConfigPollProcessSetCommand extends AbstractCronjobSetCommand
{
    protected function job(): string {
        return 'poll_process';
    }

    public function describe(): string {
        return 'set or reset one poll_process.* field: enabled, frequency_minutes';
    }
}

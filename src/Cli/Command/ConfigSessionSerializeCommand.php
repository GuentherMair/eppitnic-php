<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;

/**
 * Turn locking of commands on the shared, kept-alive session on or off --
 * see SessionLock. A local settings write only: unlike `config keepalive`,
 * there is no registry session to open or close either way.
 */
final class ConfigSessionSerializeCommand extends Command
{
    public function describe(): string {
        return 'serialise commands on the shared registry session against each other (see `config keepalive`)';
    }

    public function arguments(): string {
        return '<on|off>';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $value = $this->arguments[0] ?? null;
        if ( ! in_array($value, ['on', 'off'], true)) {
            throw new UsageError("give 'on' or 'off'");
        }
        $desired = $value === 'on';

        $current = (bool) Config::get('session_serialize');
        if ($current === $desired) {
            $this->line("session_serialize is already {$value}");
            return 0;
        }

        if ( ! $this->confirm("Turn session_serialize {$value}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would turn session_serialize {$value}");
            return 0;
        }

        Config::set('session_serialize', $desired);

        $this->record("session_serialize turned {$value}", ['session_serialize' => $desired]);
        return 0;
    }
}

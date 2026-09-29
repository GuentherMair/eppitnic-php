<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\DebugFile;

/**
 * `config debugfile [<file> | delete]`: log every registry exchange, masked,
 * to a `.log` file in the var directory, or delete that file and stop --
 * see DebugFile for the rules.
 */
final class ConfigDebugfileCommand extends Command
{
    public function describe(): string {
        return 'show or start the registry debug log (a .log file in the var directory), or delete it';
    }

    public function arguments(): string {
        return '[<file> | delete]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $value = $this->arguments[0] ?? null;
        if ($value === null) {
            return $this->show();
        }
        if (count($this->arguments) > 1) {
            throw new UsageError('give one file name, or delete');
        }

        return $value === 'delete' ? $this->delete() : $this->start($value);
    }

    private function show(): int {
        $status = DebugFile::status();

        if ($status['path'] === '') {
            $this->record('the registry debug log is off', $status);
            return 0;
        }
        $state = match (true) {
            ! $status['writable'] => 'NOT WRITABLE -- nothing is being logged',
            $status['exists']     => "{$status['size']} bytes",
            default               => 'not created yet',
        };
        $this->record("{$status['path']} ({$state})", $status);
        return 0;
    }

    private function start(string $value): int {
        try {
            $path = DebugFile::resolve($value);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $current = DebugFile::get();
        if ($current === $path) {
            $this->line("already logging to {$path}");
            return 0;
        }
        if ($current !== '') {
            throw new UsageError("the debug log is recording to {$current} -- run 'config debugfile delete' first");
        }

        $this->warn(DebugFile::WARNING);
        if ( ! $this->confirm("Log registry traffic to {$path}?")) {
            $this->line('nothing done');
            return 0;
        }
        if ($this->isDryRun()) {
            $this->line("would log to {$path}");
            return 0;
        }

        try {
            $status = DebugFile::set($path, $this->userId());
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $this->record("logging registry traffic to {$path}", $status);
        return 0;
    }

    private function delete(): int {
        $current = DebugFile::get();
        if ($current === '') {
            $this->line('the registry debug log is off -- nothing to delete');
            return 0;
        }

        if ( ! $this->confirm("Delete {$current} and stop logging?")) {
            $this->line('nothing done');
            return 0;
        }
        if ($this->isDryRun()) {
            $this->line("would delete {$current} and stop logging");
            return 0;
        }

        try {
            $status = DebugFile::delete($this->userId());
        } catch (\RuntimeException $e) {
            $this->warn($e->getMessage());
            return OUTPUT_ERROR;
        }

        $this->record("deleted {$current}; the registry debug log is off", $status);
        return 0;
    }
}

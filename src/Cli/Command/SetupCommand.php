<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Setup\ConfigFile;
use Eppitnic\Setup\DatabaseCredentials;
use Eppitnic\Setup\Installer;

/**
 * Create config/config.php, install the schema, and create the first admin
 * user -- interactively, or scripted via --db-name= and friends.
 *
 * The one command bin/eppitnic runs before any database exists to talk to,
 * so it never calls $this->database() the way every other command does --
 * Setup\Installer connects for itself, on credentials this command has not
 * committed to yet.
 *
 * @category    Net
 * @package     Eppitnic\Cli\Command\SetupCommand
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SetupCommand extends Command
{
    public function describe(): string {
        return 'create config/config.php, install the schema, and create the first admin user';
    }

    public function options(): array {
        $opts = [];
        foreach (Installer::requirements() as $field) {
            $flag = str_replace('_', '-', $field['name']) . '=';
            $opts[$flag] = $field['label'] . ($field['required'] ? '' : ' (optional)');
        }
        return $opts;
    }

    public function run(): int {
        // Installer::isOpen() is the actual gate -- ConfigFile::path() here
        // is only for the message, kept the one predicate to change if this
        // is ever bounded by more than the file's absence.
        if ( ! Installer::isOpen()) {
            $this->warn("'" . ConfigFile::path() . "' already exists -- eppitnic is already set up.");
            return SETUP_ALREADY_DONE;
        }

        $input = [];
        foreach (Installer::requirements() as $field) {
            $flag = str_replace('_', '-', $field['name']);
            if ($this->hasOption($flag)) {
                $input[$field['name']] = (string) $this->option($flag);
                continue;
            }
            if ( ! stream_isatty(STDIN)) {
                if ($field['required']) {
                    throw new UsageError("--{$flag}=... is required (no terminal to prompt at)");
                }
                continue;
            }
            $input[$field['name']] = $field['secret']
                ? $this->promptHidden($field['label'])
                : $this->prompt($field['label'], $field['default']);
        }

        $this->line('Testing database connection...');
        try {
            Installer::verify(DatabaseCredentials::fromArray($input));
        } catch (\Throwable $e) {
            $this->warn('Connection failed: ' . $e->getMessage());
            return SETUP_FAILED;
        }

        $this->line('Connection OK. Installing...');
        try {
            $result = Installer::install($input);
        } catch (\Throwable $e) {
            $this->warn('Setup failed: ' . $e->getMessage());
            return SETUP_FAILED;
        }

        $this->record(
            "Setup complete. Schema version {$result['schema_version']}, admin user '{$result['admin']['username']}' created. "
                . "Run 'eppitnic session hello' to confirm the EPP credentials, if any were given.",
            $result
        );
        return 0;
    }
}

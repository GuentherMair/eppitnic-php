<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Service\AllowedOrigins;

/**
 * Show and edit `allowed_origins`, the browser origins the CORS middleware
 * lets through. Written through AllowedOrigins, which canonicalises each
 * entry and records the change to `history`. A local settings write only.
 */
final class ConfigAllowedOriginsCommand extends Command
{
    public function describe(): string {
        return 'show or edit allowed_origins, the browser origins allowed to call the API';
    }

    public function arguments(): string {
        return '[add <origin>... | remove <origin>... | clear]';
    }

    public function options(): array {
        return self::LOCAL_MUTATING_OPTIONS;
    }

    public function run(): int {
        $this->database();

        $current = AllowedOrigins::get();
        $action = $this->arguments[0] ?? null;
        $rest = array_slice($this->arguments, 1);

        if ($action === null) {
            return $this->show($current);
        }

        if ($action === 'clear') {
            if ($rest !== []) {
                throw new UsageError("'clear' takes no arguments");
            }
            return $this->write($current, [], $action);
        }

        return match ($action) {
            'add'    => $this->write($current, $this->added($current, $rest), $action),
            'remove' => $this->write($current, $this->removed($current, $rest), $action),
            default  => throw new UsageError("argument must be 'add', 'remove' or 'clear'"),
        };
    }

    /** @param string[] $current */
    private function show(array $current): int {
        if ($current === []) {
            $this->line('allowed_origins is empty -- browsers are refused wherever they send Origin, '
                . 'including same-origin writes; clients without it (curl, scripts) are unaffected');
            return 0;
        }

        foreach ($current as $origin) {
            $this->record($origin, ['origin' => $origin]);
        }
        return 0;
    }

    /**
     * @param string[] $current
     * @param string[] $given
     * @return string[]
     */
    private function added(array $current, array $given): array {
        $result = self::canonical($current);
        foreach ($this->origins($given) as $origin) {
            if (in_array($origin, $result, true)) {
                $this->line("{$origin} is already listed");
                continue;
            }
            $result[] = $origin;
        }
        return $result;
    }

    /**
     * @param string[] $current
     * @param string[] $given
     * @return string[]
     */
    private function removed(array $current, array $given): array {
        $result = self::canonical($current);
        foreach ($this->origins($given) as $origin) {
            $at = array_search($origin, $result, true);
            if ($at === false) {
                $this->line("{$origin} is not listed");
                continue;
            }
            unset($result[$at]);
        }
        return array_values($result);
    }

    /**
     * Entries already stored, comparable with the arguments. One that does not
     * parse is kept as-is: editing one entry does not discard another.
     *
     * @param string[] $current
     * @return string[]
     */
    private static function canonical(array $current): array {
        return array_map(fn(string $origin) => AllowedOrigins::canonical($origin) ?? $origin, $current);
    }

    /**
     * The arguments as canonical origins.
     *
     * @param string[] $given
     * @return string[]
     * @throws UsageError if none were given, or one is not an origin
     */
    private function origins(array $given): array {
        if ($given === []) {
            throw new UsageError('give at least one origin, e.g. https://epp.example.it');
        }
        try {
            return AllowedOrigins::normalize($given);
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }
    }

    /**
     * @param string[] $current as stored
     * @param string[] $desired what to write instead
     */
    private function write(array $current, array $desired, string $action): int {
        if ($desired === $current) {
            $this->line('allowed_origins is unchanged');
            return 0;
        }

        $summary = $desired === [] ? '(empty)' : implode(', ', $desired);

        // Removing and clearing only refuse more, so only adding gets the warning
        if ($action === 'add') {
            $this->warn('Pages served from these origins may call the API with a logged-in user\'s token.');
        }

        if ( ! $this->confirm("Set allowed_origins to {$summary}?")) {
            $this->line('nothing done');
            return 0;
        }

        if ($this->isDryRun()) {
            $this->line("would set allowed_origins: {$summary}");
            return 0;
        }

        $stored = AllowedOrigins::set($desired, $this->userId());

        $this->record("allowed_origins set to {$summary}", ['allowed_origins' => $stored]);
        return 0;
    }
}

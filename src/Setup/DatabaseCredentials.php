<?php

namespace Eppitnic\Setup;

/**
 * The six values config/config.php holds, as one object rather than scalars
 * threaded through Installer, ConfigFile and the PDO probe. Password is neither
 * trimmed nor defaulted: trust auth wants it empty, and whitespace may be real.
 *
 * @category    Net
 * @package     Eppitnic\Setup\DatabaseCredentials
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class DatabaseCredentials
{
    public function __construct(
        public readonly string $type = 'mysql',
        public readonly string $host = 'localhost',
        public readonly string $name = '',
        public readonly string $charset = 'utf8',
        public readonly string $user = '',
        public readonly string $password = '',
    ) {
        $missing = array_keys(array_filter([
            'db_type'    => $this->type === '',
            'db_host'    => $this->host === '',
            'db_name'    => $this->name === '',
            'db_charset' => $this->charset === '',
            'db_user'    => $this->user === '',
        ]));
        if ($missing !== []) {
            throw new \InvalidArgumentException('Missing required database field(s): ' . implode(', ', $missing));
        }
    }

    /**
     * @param array<string, mixed> $input snake_case keys, e.g. a decoded JSON
     *        request body or the CLI's --db-* options
     */
    public static function fromArray(array $input): self {
        return new self(
            type:     trim((string) ($input['db_type']    ?? 'mysql')) ?: 'mysql',
            host:     trim((string) ($input['db_host']    ?? 'localhost')) ?: 'localhost',
            name:     trim((string) ($input['db_name']    ?? '')),
            charset:  trim((string) ($input['db_charset'] ?? 'utf8')) ?: 'utf8',
            user:     trim((string) ($input['db_user']    ?? '')),
            password: (string) ($input['db_password'] ?? ''),
        );
    }

    /** the DSN both the raw PDO probe and RedBeanPHP's R::setup() accept identically */
    public function dsn(): string {
        return "{$this->type}:host={$this->host};dbname={$this->name};charset={$this->charset}";
    }

    /** @return array<string, string> the six DB_* constants, keyed as Config::connect() reads them */
    public function toDefines(): array {
        return [
            'DB_TYPE'     => $this->type,
            'DB_HOST'     => $this->host,
            'DB_NAME'     => $this->name,
            'DB_CHARSET'  => $this->charset,
            'DB_USER'     => $this->user,
            'DB_PASSWORD' => $this->password,
        ];
    }
}

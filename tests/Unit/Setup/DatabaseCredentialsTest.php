<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Setup\DatabaseCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validation and shaping for the six DB_* values, ahead of anything that
 * would actually try to connect with them.
 */
final class DatabaseCredentialsTest extends TestCase
{
    public function testDefaultsApplyWhenOmitted(): void {
        $creds = DatabaseCredentials::fromArray(['db_name' => 'eppitnic', 'db_user' => 'eppitnic']);

        $this->assertSame('mysql', $creds->type);
        $this->assertSame('localhost', $creds->host);
        $this->assertSame('utf8', $creds->charset);
        $this->assertSame('', $creds->password);
    }

    public function testPasswordSurvivesWhitespaceUnchanged(): void {
        $creds = DatabaseCredentials::fromArray([
            'db_name' => 'eppitnic', 'db_user' => 'eppitnic', 'db_password' => '  spaced  ',
        ]);

        $this->assertSame('  spaced  ', $creds->password);
    }

    public function testDsnFormat(): void {
        $creds = new DatabaseCredentials(type: 'mysql', host: 'db.example.com', name: 'eppitnic', charset: 'utf8', user: 'eppitnic');

        $this->assertSame('mysql:host=db.example.com;dbname=eppitnic;charset=utf8', $creds->dsn());
    }

    public function testToDefinesKeysAndValues(): void {
        $creds = new DatabaseCredentials(type: 'mysql', host: 'localhost', name: 'eppitnic', charset: 'utf8', user: 'eppitnic', password: 'secret');

        $this->assertSame([
            'DB_TYPE'     => 'mysql',
            'DB_HOST'     => 'localhost',
            'DB_NAME'     => 'eppitnic',
            'DB_CHARSET'  => 'utf8',
            'DB_USER'     => 'eppitnic',
            'DB_PASSWORD' => 'secret',
        ], $creds->toDefines());
    }

    public static function missingFieldProvider(): array {
        // db_type/db_host/db_charset can never come out empty: an empty string
        // falls back to the field's default, same as omitting it. Only
        // db_name and db_user have no such fallback
        return [
            'db_name missing' => [['db_user' => 'x'], 'db_name'],
            'db_user missing' => [['db_name' => 'x'], 'db_user'],
        ];
    }

    #[DataProvider('missingFieldProvider')]
    public function testEachRequiredFieldIsNamedWhenMissing(array $input, string $expectedField): void {
        try {
            DatabaseCredentials::fromArray($input);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($expectedField, $e->getMessage());
        }
    }

    public function testConstructorRejectsAnExplicitlyEmptyTypeHostOrCharset(): void {
        // fromArray() can't produce this state (see missingFieldProvider's
        // note), but the constructor itself still guards it -- a future
        // caller that bypasses fromArray() must not silently connect with ''.
        foreach (['type', 'host', 'charset'] as $field) {
            try {
                new DatabaseCredentials(...array_merge(
                    ['name' => 'x', 'user' => 'x'],
                    [$field => '']
                ));
                $this->fail("expected an InvalidArgumentException for empty {$field}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('db_' . $field, $e->getMessage());
            }
        }
    }

    public function testMultipleMissingFieldsAreAllNamed(): void {
        try {
            new DatabaseCredentials(type: '', host: '', name: '', charset: '', user: '');
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            foreach (['db_type', 'db_host', 'db_name', 'db_charset', 'db_user'] as $field) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
    }
}

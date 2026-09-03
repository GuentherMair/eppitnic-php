<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Support\Validate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules the `epp` fields must satisfy, from the registry's schemas rather
 * than a preference here. One place because there were nearly three: both
 * `config epp-*` verbs checked, and Setup\Installer did not.
 */
final class EppFieldValidationTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptable(): array {
        return [
            'IPv4 interface'            => ['interface', '203.0.113.5'],
            'Italian'                   => ['lang', 'it'],
            'English'                   => ['lang', 'en'],
            'prefix at the ceiling'     => ['cl_trid_prefix', str_repeat('A', 47)],
            'shortest username'         => ['username', 'AB-REG'],
            'ordinary username'         => ['username', 'MYCOMPANY-REG'],
            'shortest password'         => ['password', str_repeat('a', 6)],
            'password at the ceiling'   => ['password', str_repeat('a', 16)],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refused(): array {
        return [
            // the field is specifically an IPv4 address: curl's
            // CURLOPT_INTERFACE
            'IPv6 interface'          => ['interface', '2001:db8::1'],
            'not an address at all'   => ['interface', 'eth0'],
            'unsupported language'    => ['lang', 'fr'],
            'empty prefix'            => ['cl_trid_prefix', ''],
            // epp:trIDStringType caps the whole clTRID at 64, and
            // Client::set_clTRID() appends 17 characters to this
            'prefix one over'         => ['cl_trid_prefix', str_repeat('A', 48)],
            // eppcom:clIDType is 3 to 16
            'username one over'       => ['username', 'MYLONGCOMPANY-REG'],
            'username without -REG'   => ['username', 'MYCOMPANY'],
            // epp:pwType is 6 to 16, for both <pw> and <newPW>
            'password one under'      => ['password', str_repeat('a', 5)],
            'password one over'       => ['password', str_repeat('a', 17)],
        ];
    }

    #[DataProvider('acceptable')]
    public function testAcceptsAValidValue(string $field, string $value): void {
        $this->assertNull(Validate::eppField($field, $value), "{$field} should accept '{$value}'");
    }

    #[DataProvider('refused')]
    public function testRefusesAnInvalidValue(string $field, string $value): void {
        $error = Validate::eppField($field, $value);

        $this->assertNotNull($error, "{$field} should refuse '{$value}'");
        $this->assertStringContainsString($field, $error, 'the error should name the field it is about');
    }

    /**
     * Every one of these ends up in an XML element or a curl option, and a
     * value carrying a newline or a leading space fails somewhere far from
     * where it was entered.
     */
    #[DataProvider('whitespaceBearing')]
    public function testRefusesWhitespaceInAnyField(string $field, string $value): void {
        $this->assertNotNull(Validate::eppField($field, $value));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function whitespaceBearing(): array {
        return [
            'leading space'  => ['username', ' MYCOMPANY-REG'],
            'trailing space' => ['cl_trid_prefix', 'PREFIX '],
            'inner space'    => ['password', 'abc def'],
            'newline'        => ['lang', "it\n"],
        ];
    }

    /**
     * An unknown field is nobody's business here: each caller owns its own list
     * of what it will set, and inventing a rule for a name none accepts would
     * only be a second place to keep in step.
     */
    public function testUnknownFieldsAreNotJudged(): void {
        $this->assertNull(Validate::eppField('server', 'https://epp.pubtest.nic.it'));
    }
}

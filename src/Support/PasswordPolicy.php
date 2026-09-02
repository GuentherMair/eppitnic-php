<?php

namespace Eppitnic\Support;

/**
 * What a login password must look like -- there was no such rule, and four call
 * sites reached `password_hash()` without one. Stated as data, not a regex: a
 * rule that can only be checked cannot be explained to the person typing it.
 *
 * @category    Net
 * @package     Eppitnic\Support\PasswordPolicy
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /**
     * Each rule as `key => [description, test]`. One list, so that the
     * violations reported back and the advice shown up front cannot drift.
     *
     * @return array<string, array{0: string, 1: callable(string): bool}>
     */
    private static function rules(): array {
        return [
            'length'  => [
                'at least ' . self::MIN_LENGTH . ' characters',
                fn(string $p) => mb_strlen($p) >= self::MIN_LENGTH,
            ],
            'lower'   => ['a lower-case letter', fn(string $p) => (bool) preg_match('/\p{Ll}/u', $p)],
            'upper'   => ['an upper-case letter', fn(string $p) => (bool) preg_match('/\p{Lu}/u', $p)],
            'number'  => ['a digit',              fn(string $p) => (bool) preg_match('/\d/u', $p)],
            'special' => [
                'a character that is neither a letter nor a digit',
                fn(string $p) => (bool) preg_match('/[^\p{L}\d]/u', $p),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // checking
    // -----------------------------------------------------------------

    /**
     * @return string[] what the password is missing; empty when it is fine
     */
    public static function violations(string $password): array {
        $missing = [];

        foreach (self::rules() as $rule) {
            [$description, $test] = $rule;
            if ( ! $test($password)) {
                $missing[] = $description;
            }
        }

        return $missing;
    }

    public static function isAcceptable(string $password): bool {
        return self::violations($password) === [];
    }

    /**
     * @return string a sentence naming everything that is wrong, or '' if
     *                nothing is
     */
    public static function explain(string $password): string {
        $missing = self::violations($password);

        if ($missing === []) {
            return '';
        }
        $last = array_pop($missing);

        return 'The password needs ' . ($missing === [] ? $last : implode(', ', $missing) . ' and ' . $last) . '.';
    }

    // -----------------------------------------------------------------
    // explaining, before anything is typed
    // -----------------------------------------------------------------

    /**
     * The rules as data, for a form to render. Ordered as they are checked.
     *
     * @return array<int, array{key: string, description: string}>
     */
    public static function describe(): array {
        $described = [];

        foreach (self::rules() as $key => $rule) {
            $described[] = ['key' => $key, 'description' => $rule[0]];
        }

        return $described;
    }
}

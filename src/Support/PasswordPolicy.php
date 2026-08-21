<?php

namespace Eppitnic\Support;

/**
 * What a login password has to look like before it is accepted.
 *
 * There was no such rule anywhere: `password_hash()` is reached from four
 * places -- `POST /v1/users`, `PUT /v1/users/{id}`, `PUT /v1/changepassword/{id}`
 * and `Persistence\User::create()` -- and not one of them looked at what it was
 * given first. A single character would have been stored happily.
 *
 * The rule is taken from the only complexity this codebase already expresses:
 * PasswordGenerator::forRegistry() draws all four character classes, and
 * discards a candidate that misses one. What is deliberately *not* taken from
 * there is its length. Sixteen characters is EPP's ceiling for a registry
 * credential -- a constraint the protocol imposes on that one field, and one
 * that has no bearing on a password this application hashes itself.
 *
 * Stated as data rather than as a regex, because the installer has to show a
 * person what is expected of them before they type it, and a rule that can only
 * be checked cannot be explained.
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

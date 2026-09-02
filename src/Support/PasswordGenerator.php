<?php

namespace Eppitnic\Support;

/**
 * Generates passwords from a mixed character set. In Support, not Service: the
 * Epp layer needs it for authinfo and clTRIDs. EPP caps these at 16 characters,
 * so SAFE_CHARSET carries ~95 bits there where hex carried 64.
 *
 * @category    Net
 * @package     Eppitnic\Support\PasswordGenerator
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasswordGenerator
{
    /** everything that survives an EPP `token` round trip */
    public const FULL_CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$%!_-';

    /**
     * The same, less the characters that get misread when a credential is read
     * aloud or copied off a screen -- l/I, O/0 -- and less `$`, `%` and `!`,
     * which shells, CSV readers and connection strings all treat specially.
     */
    public const SAFE_CHARSET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ123456789@#_-';

    /**
     * High enough that a legitimate run never reaches it, low enough that a
     * mistake raises an exception instead of never returning.
     */
    private const MAX_ATTEMPTS = 1000;

    /** @var array<string, string> class name => the pattern that detects it */
    private const CLASS_PATTERNS = [
        'upper'   => '/[A-Z]/',
        'lower'   => '/[a-z]/',
        'number'  => '/[0-9]/',
        'special' => '/[^a-zA-Z0-9]/',
    ];

    /** @var string[] the classes this generator insists on */
    private readonly array $required;

    /**
     * @param int $length how many characters. 16 is the EPP ceiling for an
     *            authinfo or a registry password.
     * @param bool $safeCharset draw from SAFE_CHARSET rather than FULL_CHARSET
     * @param string|null $charset draw from this set instead of either built-in
     *                    one -- for a consumer with its own rules about what a
     *                    password may contain
     * @throws \InvalidArgumentException if the requirements cannot be met from
     *         the chosen set -- requiring a digit from a set with no digits, or
     *         four classes from three characters, would otherwise be a loop
     *         that never finishes
     */
    public function __construct(
        private readonly int $length = 16,
        bool $safeCharset = false,
        bool $requireUpper = true,
        bool $requireLower = true,
        bool $requireNumber = false,
        bool $requireSpecialChar = true,
        ?string $charset = null
    ) {
        $this->charset = $charset ?? ($safeCharset ? self::SAFE_CHARSET : self::FULL_CHARSET);

        if ($this->charset === '') {
            throw new \InvalidArgumentException('A password cannot be drawn from an empty character set');
        }

        $this->required = array_keys(array_filter([
            'upper'   => $requireUpper,
            'lower'   => $requireLower,
            'number'  => $requireNumber,
            'special' => $requireSpecialChar,
        ]));

        if ($length < count($this->required)) {
            throw new \InvalidArgumentException(
                "A {$length}-character password cannot hold " . count($this->required) . ' required character classes'
            );
        }
        foreach ($this->required as $class) {
            if (preg_match(self::CLASS_PATTERNS[$class], $this->charset) !== 1) {
                throw new \InvalidArgumentException("The character set holds no '{$class}' character to satisfy the requirement");
            }
        }
    }

    private readonly string $charset;

    /**
     * @return string a password meeting every required class
     */
    public function get(): string {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->draw();
            if ($this->satisfies($candidate)) {
                return $candidate;
            }
        }

        // unreachable with the constructor's checks passed; here so that a
        // future change which breaks that assumption says so
        throw new \RuntimeException(
            'No password meeting the requirements was produced in ' . self::MAX_ATTEMPTS . ' attempts'
        );
    }

    /**
     * A domain's authinfo: the transfer credential, and the one here a person
     * copies out and passes on -- which is what the safe set is for. 16
     * characters by choice, not by rule; pwAuthInfoType is unrestricted.
     */
    public static function forAuthinfo(): string {
        return self::forRegistry();
    }

    /**
     * The EPP credential: the safe set at pwType's 16-character ceiling, with
     * all four character classes. The registry's complexity policy is not
     * published, and a rotation refused for it would retry forever unseen.
     */
    public static function forRegistry(): string {
        return (new self(16, true, requireUpper: true, requireLower: true, requireNumber: true, requireSpecialChar: true))->get();
    }

    // ---------------------------------------------------------------
    // credentials that are not passwords -- here so every random credential
    // comes from one place. A password is short because something forces it
    // to be, and its character set buys that back; nothing below is short.
    // ---------------------------------------------------------------

    /**
     * A bearer token or other opaque identifier, as hex. Copied and pasted,
     * never read aloud, so a friendlier alphabet would buy nothing.
     *
     * @param int $bytes how much entropy, before hex doubles the length
     */
    public static function token(int $bytes = 32): string {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * A signing key, as base64. `jwt_psk` is never displayed or typed, so it
     * gets raw entropy in a compact encoding rather than a readable shape.
     *
     * @param int $bytes how much entropy
     */
    public static function signingKey(int $bytes = 32): string {
        return base64_encode(random_bytes($bytes));
    }

    private function draw(): string {
        $last = strlen($this->charset) - 1;
        $password = '';

        for ($i = 0; $i < $this->length; $i++) {
            $password .= $this->charset[random_int(0, $last)];
        }

        return $password;
    }

    private function satisfies(string $candidate): bool {
        foreach ($this->required as $class) {
            if (preg_match(self::CLASS_PATTERNS[$class], $candidate) !== 1) {
                return false;
            }
        }
        return true;
    }
}

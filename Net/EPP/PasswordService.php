<?php

namespace Net\EPP;

/**
 * Generates passwords from a mixed character set.
 *
 * The registry credential used to be `bin2hex(random_bytes(8))`. That is a
 * perfectly good 64 bits, but it spends 16 characters saying it -- hex carries
 * 4 bits per character where the sets below carry close to 6, and EPP caps
 * this kind of credential at 16 characters (`pwType`, min 6, max 16), so those
 * characters are the scarce thing. The same 16 characters drawn from
 * SAFE_CHARSET carry about 95 bits.
 *
 * Characters are drawn with random_int(), the CSPRNG: the obvious
 * `$charset[rand() % strlen($charset)]` fails twice over -- rand() is not
 * cryptographically secure, and the modulo favours the characters at the start
 * of the set whenever the set length does not divide the generator's range.
 *
 * A candidate that does not meet the required character classes is discarded
 * and another drawn, rather than being patched up by forcing a character of
 * each class into a fixed position and shuffling. Rejection sampling keeps the
 * result uniform across the passwords that satisfy the rules; patching does
 * not, and the bias it introduces is in exactly the place an attacker would
 * look.
 *
 * @category    Net
 * @package     Net\EPP\PasswordService
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasswordService
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
     * Enough attempts that a legitimate run never reaches the limit -- with
     * the constructor's checks passed, the odds of needing even ten are
     * negligible -- and few enough that a mistake surfaces as an exception
     * rather than a process that never returns.
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
     * The EPP credential this codebase generates: the safe set, at the
     * protocol's 16-character ceiling.
     *
     * All four character classes, not the constructor's default three. The
     * registry's complexity policy is not published, and a rotation refused for
     * having no digit would not lock the account out -- the old password stays
     * good and it retries tomorrow -- but it would retry forever until someone
     * read the log. Satisfying every plausible policy costs a fraction of a bit.
     */
    public static function forRegistry(): string {
        return (new self(16, true, requireUpper: true, requireLower: true, requireNumber: true, requireSpecialChar: true))->get();
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

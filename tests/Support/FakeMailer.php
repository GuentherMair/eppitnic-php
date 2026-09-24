<?php

namespace Eppitnic\Tests\Support;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Swapped in via `Notifier::useMailerFactory()` so a test can assert what
 * would have been sent (and, via $shouldFail, what a real SMTP failure
 * looks like) without ever opening a real connection.
 */
final class FakeMailer extends PHPMailer
{
    /** @var array<int, array{to: string, subject: string, body: string, host: string, username: string, password: string}> */
    public static array $sent = [];

    /** Makes the next send() throw, as a real SMTP failure would. */
    public static bool $shouldFail = false;

    public static function reset(): void {
        self::$sent = [];
        self::$shouldFail = false;
    }

    public function send(): bool {
        if (self::$shouldFail) {
            throw new MailException('simulated SMTP failure');
        }

        $to = $this->getToAddresses();
        self::$sent[] = [
            'to'       => $to[0][0] ?? '',
            'subject'  => $this->Subject,
            'body'     => $this->Body,
            'host'     => $this->Host,
            'username' => $this->Username,
            'password' => $this->Password,
        ];
        return true;
    }
}

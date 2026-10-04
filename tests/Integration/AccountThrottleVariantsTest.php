<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Domain\Role;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordResetService;

/**
 * S2: the database compares emails with utf8mb4_unicode_ci, which ignores accents and combining marks, so
 * "user@x" and "u\u{0301}ser@x" are the same account. The per-account limits must follow the account,
 * not the spelling the client typed, or varying the spelling gives a fresh bucket on every attempt.
 */
final class AccountThrottleVariantsTest extends IntegrationTestCase
{
    private const PASSWORD = 'Correct-Passw0rd';

    /** Same account for MariaDB, different string for PHP: a combining acute accent after the $n-th character. */
    private function variant(string $email, int $n): string
    {
        return mb_substr($email, 0, $n) . "\u{0301}" . mb_substr($email, $n);
    }

    /** "pass", "failed" (wrong password / refused, counted) or "throttled". */
    private function login(string $identifier, string $password, string $ip): string
    {
        try {
            app(AuthService::class)->attempt($identifier, $password, Role::STUDENT, false, new Request('POST', '/login', [], [], [], [], ['REMOTE_ADDR' => $ip]));

            return 'pass';
        } catch (ValidationException $e) {
            return ($e->errors['identifier'][0] ?? '') === t('auth.errors.failed') ? 'failed' : 'throttled';
        }
    }

    private function emailOf(int $userId): string
    {
        return (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$userId]);
    }

    public function testTheDatabaseTreatsTheVariantsAsTheSameAccount(): void
    {
        $email = $this->emailOf($this->user());

        self::assertNotSame($email, $this->variant($email, 3));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM users WHERE email = ?', [mb_strtolower($this->variant($email, 3))]), 'precondition for the tests below');
    }

    public function testSpellingVariantsDoNotGiveFreshLoginBucketsAcrossAddresses(): void
    {
        $id = $this->user(password: self::PASSWORD);
        $email = $this->emailOf($id);

        // 20 wrong guesses, each with another spelling and from another address: nothing is throttled yet.
        for ($i = 1; $i <= 20; $i++) {
            self::assertSame('failed', $this->login($this->variant($email, $i), 'Wrong-password-1', "198.51.100.$i"), "guess $i");
        }

        // The account has used its 20 attempts for the hour: even the right password is refused with the
        // ordinary "failed" message (no separate message that would reveal that the account exists).
        self::assertSame('failed', $this->login($this->variant($email, 21), self::PASSWORD, '198.51.100.99'));
    }

    public function testSpellingVariantsDoNotGiveFreshBucketsFromOneAddress(): void
    {
        $id = $this->user(password: self::PASSWORD);
        $email = $this->emailOf($id);

        for ($i = 1; $i <= 5; $i++) {
            self::assertSame('failed', $this->login($this->variant($email, $i), 'Wrong-password-1', '198.51.100.7'), "guess $i");
        }

        self::assertSame('failed', $this->login($this->variant($email, 6), self::PASSWORD, '198.51.100.7'), 'the 5-per-15-minutes limit for this account and address applies to every spelling');
    }

    public function testNormalLoginsAreNotAffected(): void
    {
        $id = $this->user(password: self::PASSWORD);
        $email = $this->emailOf($id);

        self::assertSame('failed', $this->login($email, 'Wrong-password-1', '198.51.100.8'));
        self::assertSame('failed', $this->login($this->variant($email, 2), 'Wrong-password-2', '198.51.100.8'));
        self::assertSame('pass', $this->login($email, self::PASSWORD, '198.51.100.8'), 'a correct password still logs in below the limits');
        self::assertSame('pass', $this->login($this->variant($email, 4), self::PASSWORD, '198.51.100.8'), 'and a correct login refunds its attempt');
    }

    public function testUnknownAccountsStillBehaveTheSame(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            self::assertSame($i <= 5 ? 'failed' : 'throttled', $this->login('nobody@test.local', 'Wrong-password-1', '198.51.100.9'), "attempt $i");
        }
    }

    public function testSpellingVariantsDoNotGiveFreshResetBucketsPerEmail(): void
    {
        $this->captureMail();
        $id = $this->user();
        $email = $this->emailOf($id);

        // 10 requests from 10 addresses with 10 spellings of the same address: at most 3 reset jobs per hour.
        for ($i = 1; $i <= 10; $i++) {
            app(PasswordResetService::class)->request(
                $this->variant($email, $i),
                new Request('POST', '/forgot-password', [], [], [], [], ['REMOTE_ADDR' => "198.51.100.$i"]),
            );
        }

        self::assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM mail_queue WHERE user_id = ?', [$id]), 'the per-address limit of 3 per hour follows the account');
    }
}

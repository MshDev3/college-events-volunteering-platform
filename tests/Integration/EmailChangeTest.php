<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Domain\User;
use App\Repositories\UserRepository;
use App\Services\Auth\EmailChangeService;
use App\Services\UserService;

/**
 * A new login email must be confirmed by a link sent to that address (O9). Until then nothing changes;
 * after confirmation other sessions and remembered devices end and the OLD address is warned (L-6).
 */
final class EmailChangeTest extends IntegrationTestCase
{
    private const PASSWORD = 'Correct-Passw0rd';

    private function requestChange(User $user, string $newEmail, string $password = self::PASSWORD): bool
    {
        return app(UserService::class)->updateProfile($user, [
            'full_name' => $user->fullName, 'email' => $newEmail, 'phone' => '0551234567',
            'preferred_locale' => 'en', 'email_password' => $password,
        ]);
    }

    /** @param \ArrayObject<int, \App\Core\Mail\MailMessage> $mail */
    private function tokenFrom(\ArrayObject $mail): string
    {
        self::assertNotEmpty($mail, 'a verification email was sent');
        $last = $mail[count($mail) - 1];
        self::assertSame(1, preg_match('#/profile/email/confirm/([a-f0-9]{64})#', $last->text, $m), 'the email contains the confirmation link');

        return $m[1];
    }

    private function emailOf(int $id): string
    {
        return (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$id]);
    }

    private function assertInvalidLink(callable $fn): void
    {
        try {
            $fn();
            self::fail('The link must be refused.');
        } catch (BusinessRuleException $e) {
            self::assertSame('profile.email_change.invalid', $e->key);
        }
    }

    public function testRequestEmailsTheNewAddressAndChangesNothingYet(): void
    {
        $mail = $this->captureMail();
        $id = $this->user(password: self::PASSWORD);
        $user = app(UserService::class)->find($id);
        $this->db->insert('auth_remember_tokens', ['user_id' => $id, 'selector' => bin2hex(random_bytes(12)), 'validator_hash' => str_repeat('a', 64), 'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        self::assertTrue($this->requestChange($user, "New.Address.$id@test.local"));

        self::assertSame($user->email, $this->emailOf($id), 'the login email is unchanged until the link is confirmed');
        self::assertSame($user->authVersion, (int) $this->db->value('SELECT auth_version FROM users WHERE id = ?', [$id]), 'no session ended yet');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id = ?', [$id]));
        self::assertCount(1, $mail);
        self::assertSame("new.address.$id@test.local", $mail[0]->to, 'the link goes to the NEW address');
        $token = $this->tokenFrom($mail);
        $row = $this->db->fetch('SELECT new_email, token_hash FROM email_change_requests WHERE user_id = ?', [$id]);
        self::assertSame("new.address.$id@test.local", $row['new_email']);
        self::assertSame(hash('sha256', $token), $row['token_hash'], 'only the hash of the token is stored');
        self::assertSame("new.address.$id@test.local", app(EmailChangeService::class)->pending($id)['new_email'] ?? null);
    }

    public function testConfirmationChangesTheEmailEndsOtherSessionsAndWarnsTheOldAddress(): void
    {
        $mail = $this->captureMail();
        $id = $this->user(password: self::PASSWORD);
        $user = app(UserService::class)->find($id);
        $this->requestChange($user, "moved.$id@test.local");
        $token = $this->tokenFrom($mail);
        $this->db->insert('auth_remember_tokens', ['user_id' => $id, 'selector' => bin2hex(random_bytes(12)), 'validator_hash' => str_repeat('b', 64), 'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        self::assertSame("moved.$id@test.local", app(EmailChangeService::class)->newEmailFor($user, $token), 'the confirmation page can show the new address');
        app(EmailChangeService::class)->confirm($user, $token);

        self::assertSame("moved.$id@test.local", $this->emailOf($id));
        self::assertSame($user->authVersion + 1, (int) $this->db->value('SELECT auth_version FROM users WHERE id = ?', [$id]), 'other sessions invalidated');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id = ?', [$id]), 'remembered devices revoked');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM email_change_requests WHERE user_id = ?', [$id]), 'the link is single-use');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.email_changed' AND subject_id = ?", [$id]));
        self::assertCount(2, $mail);
        self::assertSame($user->email, $mail[1]->to, 'the OLD address is told');
        self::assertStringContainsString(UserService::maskEmail("moved.$id@test.local"), $mail[1]->text, 'with the new address masked');
        self::assertStringNotContainsString("moved.$id@", $mail[1]->text);

        $this->assertInvalidLink(fn () => app(EmailChangeService::class)->confirm($user, $token));
    }

    public function testTheLinkWorksOnlyForItsOwnerAndBeforeItExpires(): void
    {
        $mail = $this->captureMail();
        $owner = app(UserService::class)->find($this->user(password: self::PASSWORD));
        $other = app(UserService::class)->find($this->user());
        $this->requestChange($owner, "owner.{$owner->id}@test.local");
        $token = $this->tokenFrom($mail);
        $service = app(EmailChangeService::class);

        self::assertNull($service->newEmailFor($other, $token));
        $this->assertInvalidLink(fn () => $service->confirm($other, $token));
        $this->assertInvalidLink(fn () => $service->confirm($owner, str_repeat('0', 64)));
        $this->assertInvalidLink(fn () => $service->confirm($owner, 'not-a-token'));
        self::assertSame($owner->email, $this->emailOf($owner->id));
        self::assertSame($other->email, $this->emailOf($other->id));

        $this->db->query('UPDATE email_change_requests SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id = ?', [$owner->id]);
        self::assertNull($service->newEmailFor($owner, $token));
        self::assertNull($service->pending($owner->id));
        $this->assertInvalidLink(fn () => $service->confirm($owner, $token));
        self::assertSame($owner->email, $this->emailOf($owner->id), 'an expired link changes nothing');
        self::assertSame(1, $service->purgeExpired());
    }

    public function testAnAddressTakenBeforeConfirmationIsRefused(): void
    {
        $mail = $this->captureMail();
        $user = app(UserService::class)->find($this->user(password: self::PASSWORD));
        $this->requestChange($user, "wanted.{$user->id}@test.local");
        $token = $this->tokenFrom($mail);
        $other = $this->user();
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', ["wanted.{$user->id}@test.local", $other]);

        try {
            app(EmailChangeService::class)->confirm($user, $token);
            self::fail('A taken address must be refused.');
        } catch (BusinessRuleException $e) {
            self::assertSame('profile.errors.email_taken', $e->key);
        }
        self::assertSame($user->email, $this->emailOf($user->id));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM email_change_requests WHERE user_id = ?', [$user->id]), 'the used link is gone');
    }

    public function testWrongPasswordSendsNothing(): void
    {
        $mail = $this->captureMail();
        $user = app(UserService::class)->find($this->user(password: self::PASSWORD));
        try {
            $this->requestChange($user, "attacker.{$user->id}@test.local", 'Wrong-Passw0rd');
            self::fail('The current password is required.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email_password', $e->errors);
        }
        self::assertCount(0, $mail);
        self::assertNull(app(EmailChangeService::class)->pending($user->id));
    }

    public function testANewRequestReplacesTheOldOneAndRequestsAreLimited(): void
    {
        $mail = $this->captureMail();
        $user = app(UserService::class)->find($this->user(password: self::PASSWORD));
        for ($i = 1; $i <= EmailChangeService::MAX_REQUESTS_PER_HOUR; $i++) {
            $this->requestChange($user, "try$i.{$user->id}@test.local");
        }
        $last = $this->tokenFrom($mail);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM email_change_requests WHERE user_id = ?', [$user->id]), 'one pending request per user');
        self::assertSame(hash('sha256', $last), $this->db->value('SELECT token_hash FROM email_change_requests WHERE user_id = ?', [$user->id]), 'the newest link replaced the older ones');

        try {
            $this->requestChange($user, "one-too-many.{$user->id}@test.local");
            self::fail('The number of verification emails is limited.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors);
        }
        self::assertCount(EmailChangeService::MAX_REQUESTS_PER_HOUR, $mail, 'no email for the refused request');
    }

    public function testPasswordChangeResetAndDeactivationCancelAPendingChange(): void
    {
        $mail = $this->captureMail();
        $service = app(EmailChangeService::class);
        $users = app(UserRepository::class);

        $a = app(UserService::class)->find($this->user(password: self::PASSWORD));
        $this->requestChange($a, "a.{$a->id}@test.local");
        app(UserService::class)->changePassword($a, ['current_password' => self::PASSWORD, 'password' => 'Brand-New-Passw0rd', 'password_confirmation' => 'Brand-New-Passw0rd'], new Request('POST', '/profile/password', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));
        self::assertNull($service->pending($a->id), 'password change');

        $b = app(UserService::class)->find($this->user(password: self::PASSWORD));
        $this->requestChange($b, "b.{$b->id}@test.local");
        $users->changePassword($b->id, password_hash('Reset-Passw0rd', PASSWORD_BCRYPT, ['cost' => 4])); // what a password reset does
        self::assertNull($service->pending($b->id), 'password reset');

        $c = app(UserService::class)->find($this->user(password: self::PASSWORD));
        $this->requestChange($c, "c.{$c->id}@test.local");
        $users->setActive($c->id, false);
        self::assertNull($service->pending($c->id), 'deactivation');
        self::assertCount(3, $mail);
    }

    public function testCancelledChangeCannotBeConfirmed(): void
    {
        $mail = $this->captureMail();
        $user = app(UserService::class)->find($this->user(password: self::PASSWORD));
        $this->requestChange($user, "cancel.{$user->id}@test.local");
        $token = $this->tokenFrom($mail);

        app(EmailChangeService::class)->cancel($user->id);
        $this->assertInvalidLink(fn () => app(EmailChangeService::class)->confirm($user, $token));
        self::assertSame($user->email, $this->emailOf($user->id));
    }

    public function testFailedDeliveryLeavesNoPendingRequest(): void
    {
        $this->captureMail(); // drops cached services so they are rebuilt with the failing mailer below
        app(Container::class)->instance(\App\Core\Mail\Mailer::class, new class implements \App\Core\Mail\Mailer {
            public function send(\App\Core\Mail\MailMessage $message): void
            {
                throw new \RuntimeException('SMTP unavailable');
            }
        });
        $user = app(UserService::class)->find($this->user(password: self::PASSWORD));
        try {
            $this->requestChange($user, "unreachable.{$user->id}@test.local");
            self::fail('A request whose email could not be sent must fail visibly.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors);
        }
        self::assertNull(app(EmailChangeService::class)->pending($user->id));
        self::assertSame($user->email, $this->emailOf($user->id));
    }

    public function testOtherProfileChangesSendNothing(): void
    {
        $mail = $this->captureMail();
        $users = app(UserService::class);
        $user = $users->find($this->user());
        $sent = $users->updateProfile($user, ['full_name' => 'Renamed Student', 'email' => $user->email, 'phone' => '0551234567', 'preferred_locale' => 'ar']);

        self::assertFalse($sent);
        self::assertCount(0, $mail);
        self::assertSame('Renamed Student', $this->db->value('SELECT full_name FROM users WHERE id = ?', [$user->id]));
        self::assertSame($user->authVersion, (int) $this->db->value('SELECT auth_version FROM users WHERE id = ?', [$user->id]));
    }

    public function testMaskEmail(): void
    {
        self::assertSame('m*******@college.edu', UserService::maskEmail('mohammed@college.edu'));
        self::assertSame('a***@x.io', UserService::maskEmail('ab@x.io'));
    }
}

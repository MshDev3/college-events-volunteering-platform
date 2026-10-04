<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ValidationException;
use App\Services\Auth\PasswordResetService;

final class PasswordResetTest extends IntegrationTestCase
{
    private function token(int $userId, string $expires = '+30 minutes', ?string $usedAt = null): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->insert('password_reset_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', strtotime($expires)),
            'used_at' => $usedAt,
        ]);

        return $token;
    }

    public function testValidTokenResetsOnceAndRevokesSessions(): void
    {
        $service = app(PasswordResetService::class);
        $user = $this->user();
        $this->db->insert('auth_remember_tokens', ['user_id' => $user, 'selector' => bin2hex(random_bytes(12)), 'validator_hash' => str_repeat('a', 64), 'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);
        $versionBefore = (int) $this->db->value('SELECT auth_version FROM users WHERE id = ?', [$user]);
        $token = $this->token($user);

        self::assertTrue($service->isValid($token));
        $service->reset($token, 'NewPassw0rd');

        $hash = (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$user]);
        self::assertTrue(password_verify('NewPassw0rd', $hash));
        self::assertStringNotContainsString('NewPassw0rd', $hash);
        self::assertSame($versionBefore + 1, (int) $this->db->value('SELECT auth_version FROM users WHERE id = ?', [$user]), 'other sessions invalidated');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id = ?', [$user]), 'remember-me revoked');

        self::assertFalse($service->isValid($token), 'single use');
        $this->expectException(ValidationException::class);
        $service->reset($token, 'AnotherPassw0rd');
    }

    public function testExpiredAndUsedTokensAreRejected(): void
    {
        $service = app(PasswordResetService::class);
        $user = $this->user();
        $hashBefore = (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$user]);

        foreach ([$this->token($user, '-1 minute'), $this->token($user, '+30 minutes', date('Y-m-d H:i:s'))] as $token) {
            self::assertFalse($service->isValid($token));
            try {
                $service->reset($token, 'NewPassw0rd');
                self::fail('Expired/used token must be rejected.');
            } catch (ValidationException) {
            }
        }
        self::assertSame($hashBefore, $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$user]));
        self::assertFalse($service->isValid('not-a-token'));
    }

    public function testPurgeRemovesExpiredAndUsedTokensOnly(): void
    {
        $service = app(PasswordResetService::class);
        $user = $this->user();
        $this->token($user, '-1 minute');
        $this->token($user, '+30 minutes', date('Y-m-d H:i:s'));
        $live = $this->token($user);

        self::assertSame(2, $service->purgeExpired());
        self::assertTrue($service->isValid($live));
    }
}

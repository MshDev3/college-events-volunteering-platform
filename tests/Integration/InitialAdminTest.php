<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ValidationException;
use App\Domain\Role;
use App\Services\UserService;

final class InitialAdminTest extends IntegrationTestCase
{
    private function input(string $email = 'first.admin@college.test', string $password = 'Str0ng-Admin-Pass'): array
    {
        return ['full_name' => 'First Admin', 'email' => $email, 'password' => $password, 'password_confirmation' => $password];
    }

    public function testCreatesTheFirstAdminWithAHashedPassword(): void
    {
        $this->user(Role::STUDENT);
        $admin = app(UserService::class)->createInitialAdmin($this->input());

        self::assertTrue($admin->isAdmin());
        $hash = (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$admin->id]);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.initial_admin_created'"));
    }

    public function testRefusedOnceAnActiveAdminExists(): void
    {
        $this->user(Role::ADMIN);
        $this->assertRule('users.errors.admin_exists', fn () => app(UserService::class)->createInitialAdmin($this->input()));
    }

    public function testWeakPasswordsAndExistingEmailsAreRejected(): void
    {
        $studentEmail = (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$this->user(Role::STUDENT)]);
        foreach ([$this->input(password: 'short'), $this->input($studentEmail)] as $input) {
            try {
                app(UserService::class)->createInitialAdmin($input);
                self::fail('Expected a validation error.');
            } catch (ValidationException) {
                self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM users WHERE role_id = ?', [Role::ADMIN->id()]));
            }
        }
    }
}

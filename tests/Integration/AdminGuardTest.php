<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\BusinessRuleException;
use App\Domain\Role;
use App\Services\UserService;

/**
 * S4: "the last active admin can never be demoted or deactivated" must hold when two admins act at the same time.
 * Without a lock, two admins demoting each other both see "2 active admins" and both succeed, leaving none.
 */
final class AdminGuardTest extends IntegrationTestCase
{
    private function activeAdmins(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [Role::ADMIN->id()]);
    }

    /** @return array{resource, array<int, resource>} */
    private function worker(string $action, int $actor, int $target, string $start): array
    {
        $env = getenv();
        $env['DB_DATABASE'] = (string) config('db.test_database');
        $proc = proc_open([PHP_BINARY, __DIR__ . '/workers/admin-worker.php', $action, (string) $actor, (string) $target, $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);

        return [$proc, $pipes];
    }

    private function outcome(array $worker): string
    {
        [$proc, $pipes] = $worker;
        $out = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        proc_close($proc);

        return $out;
    }

    public function testTheGuardStillWorksForOneAdmin(): void
    {
        $only = $this->user(Role::ADMIN);
        $other = $this->user(Role::ADMIN);
        $service = app(UserService::class);

        $service->changeRole($service->find($only), $other, 'STUDENT');
        self::assertSame(1, $this->activeAdmins());

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('users.errors.last_admin');
        $service->changeRole($service->find($other), $only, 'STUDENT'); // $other is a student now, but the rule is about the target
    }

    public function testTwoAdminsDemotingEachOtherNeverLeaveNoAdmin(): void
    {
        foreach (['demote', 'deactivate'] as $action) {
            for ($round = 0; $round < 6; $round++) {
                $this->db->query('DELETE FROM users');
                $a = $this->user(Role::ADMIN);
                $b = $this->user(Role::ADMIN);
                $start = sprintf('%.6F', microtime(true) + 1.5);
                $workers = [$this->worker($action, $a, $b, $start), $this->worker($action, $b, $a, $start)];
                $results = array_map(fn (array $w): string => $this->outcome($w), $workers);
                sort($results);

                self::assertSame(['ok', 'users.errors.last_admin'], $results, "$action, round $round");
                self::assertSame(1, $this->activeAdmins(), "$action, round $round: exactly one admin remains");
            }
        }
    }

    public function testDemotionTakesTheAdminLockBeforeItCounts(): void
    {
        // Deterministic version of the race: while this process holds the lock that serialises changes to the admin
        // set (the ADMIN row of `roles`, see UserRepository::lockAdminSetAndCount()), a demotion must wait for it.
        // Without that lock the demotion counts admins with a plain read and finishes at once.
        $a = $this->user(Role::ADMIN);
        $b = $this->user(Role::ADMIN);
        $c = $this->user(Role::ADMIN);
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM roles WHERE id = ? FOR UPDATE')->execute([Role::ADMIN->id()]);

            $worker = $this->worker('demote', $a, $b, sprintf('%.6F', microtime(true) + 0.5));
            usleep(2_500_000); // generous: the worker needs ~1 s to boot, connect and reach the guard
            self::assertTrue(proc_get_status($worker[0])['running'], 'the demotion must wait for the admin lock');
        } finally {
            $pdo->commit();
        }

        self::assertSame('ok', $this->outcome($worker), 'once the lock is released the demotion goes through (three admins existed)');
        self::assertSame(2, $this->activeAdmins());
        self::assertSame($c, (int) $this->db->value('SELECT id FROM users WHERE id = ? AND role_id = ?', [$c, Role::ADMIN->id()]), 'the bystander admin is untouched');
    }
}

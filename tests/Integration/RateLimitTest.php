<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ValidationException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Domain\Role;
use App\Services\Auth\AuthService;

/**
 * M-1: the login limits must hold under parallel requests, and across IP addresses.
 */
final class RateLimitTest extends IntegrationTestCase
{
    private function request(string $ip): Request
    {
        return new Request('POST', '/login', [], [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    /** Outcome of one login attempt: "pass", "failed" (wrong password, counted) or "throttled". */
    private function login(string $identifier, string $password, string $ip): string
    {
        try {
            app(AuthService::class)->attempt($identifier, $password, Role::STUDENT, false, $this->request($ip));

            return 'pass';
        } catch (ValidationException $e) {
            return ($e->errors['identifier'][0] ?? '') === t('auth.errors.failed') ? 'failed' : 'throttled';
        }
    }

    private function email(int $userId): string
    {
        return (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$userId]);
    }

    /**
     * Start $count PHP processes that each make one attempt at the same instant.
     * @param list<list<string>> $argsPerWorker
     * @return array<string, int> outcome => count
     */
    private function parallel(array $argsPerWorker): array
    {
        $start = sprintf('%.6F', microtime(true) + 2.0); // time for every process to boot and connect
        $env = getenv() + ['DB_DATABASE' => ''];
        $env['DB_DATABASE'] = (string) config('db.test_database');
        $procs = [];
        foreach ($argsPerWorker as $args) {
            $cmd = array_merge([PHP_BINARY, __DIR__ . '/workers/rate-limit-worker.php'], $args, [$start]);
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $outcomes = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = trim((string) stream_get_contents($pipes[1]));
            $err = trim((string) stream_get_contents($pipes[2]));
            proc_close($proc);
            self::assertContains($out, ['pass', 'failed', 'throttled'], "worker output: $out $err");
            $outcomes[$out] = ($outcomes[$out] ?? 0) + 1;
        }

        return $outcomes;
    }

    public function testAttemptCountsAtomicallyAndRelease(): void
    {
        $limiter = app(RateLimiter::class);
        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = $limiter->attempt('unit-key', 3, 60);
        }
        self::assertSame([true, true, true, false], $results);
        $limiter->release('unit-key');
        $limiter->release('unit-key');
        self::assertTrue($limiter->attempt('unit-key', 3, 60), 'released attempts are refunded');
        self::assertSame(3, $limiter->hit('other-key', 60) + $limiter->hit('other-key', 60));
    }

    public function testParallelLimiterAttemptsNeverExceedTheLimit(): void
    {
        $outcomes = $this->parallel(array_fill(0, 16, ['limiter', 'parallel-key', '5']));
        self::assertSame(5, $outcomes['pass'] ?? 0, json_encode($outcomes));
        self::assertSame(11, $outcomes['throttled'] ?? 0);
    }

    public function testParallelWrongPasswordsForOneAccountAreCappedAtFive(): void
    {
        $email = $this->email($this->user(Role::STUDENT, 'Correct-Passw0rd'));
        $outcomes = $this->parallel(array_fill(0, 12, ['login', $email, '203.0.113.7']));

        self::assertSame(5, $outcomes['failed'] ?? 0, 'only 5 password checks may run: ' . json_encode($outcomes));
        self::assertSame(7, $outcomes['throttled'] ?? 0);
        self::assertSame('throttled', $this->login($email, 'Correct-Passw0rd', '203.0.113.7'), 'still locked, even with the right password');
    }

    public function testFiveFailuresLockTheAccountFromThatIp(): void
    {
        $email = $this->email($this->user(Role::STUDENT, 'Correct-Passw0rd'));
        for ($i = 0; $i < 5; $i++) {
            self::assertSame('failed', $this->login($email, 'wrong-' . $i, '198.51.100.1'));
        }
        self::assertSame('throttled', $this->login($email, 'wrong-x', '198.51.100.1'));
        self::assertSame('pass', $this->login($email, 'Correct-Passw0rd', '198.51.100.2'), 'another IP is not locked by the per-IP rule');
    }

    public function testDistributedGuessingAgainstOneAccountIsLimitedAcrossIps(): void
    {
        $email = $this->email($this->user(Role::STUDENT, 'Correct-Passw0rd'));
        $max = (int) config('auth.login_max_attempts_per_account');
        for ($i = 0; $i < $max; $i++) {
            self::assertSame('failed', $this->login($email, 'wrong', "192.0.2.$i"), "attempt $i from a fresh IP");
        }
        self::assertSame('throttled', $this->login($email, 'wrong', '192.0.2.250'), 'the next guess from a new IP is refused');
    }

    public function testCorrectPasswordDoesNotCountAgainstTheIpLimit(): void
    {
        $email = $this->email($this->user(Role::STUDENT, 'Correct-Passw0rd'));
        $perIp = (int) config('auth.login_max_attempts_per_ip');
        for ($i = 0; $i < $perIp + 5; $i++) {
            self::assertSame('pass', $this->login($email, 'Correct-Passw0rd', '198.51.100.9'), "login $i (shared campus IP)");
        }
    }
}

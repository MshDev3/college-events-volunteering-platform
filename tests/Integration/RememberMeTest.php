<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Request;
use App\Services\Auth\RememberMeService;

/**
 * L-4: parallel requests with one remember-me cookie must not look like theft,
 * while a genuinely replayed old cookie still revokes everything.
 */
final class RememberMeTest extends IntegrationTestCase
{
    /** Insert a token and return its cookie value "selector:validator". */
    private function token(int $userId): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $this->db->query(
            'INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 30 DAY)',
            [$userId, $selector, hash('sha256', $validator)],
        );

        return "$selector:$validator";
    }

    private function consume(string $cookie): ?int
    {
        $service = app(RememberMeService::class);

        return $service->consume(new Request('GET', '/', [], [], [], [$service->cookieName() => $cookie], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function tokens(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id = ?', [$userId]);
    }

    public function testRotationGraceAndTheftDetection(): void
    {
        $user = $this->user();
        $this->token($user); // a second device of the same user
        $old = $this->token($user);
        $selector = explode(':', $old)[0];
        $hashBefore = $this->db->value('SELECT validator_hash FROM auth_remember_tokens WHERE selector = ?', [$selector]);

        self::assertSame($user, $this->consume($old), 'valid cookie logs in');
        $row = $this->db->fetch('SELECT validator_hash, previous_hash FROM auth_remember_tokens WHERE selector = ?', [$selector]);
        self::assertNotSame($hashBefore, $row['validator_hash'], 'validator rotated');
        self::assertSame($hashBefore, $row['previous_hash']);

        self::assertSame($user, $this->consume($old), 'the just-rotated cookie is still accepted within the grace window');
        self::assertSame($row['validator_hash'], $this->db->value('SELECT validator_hash FROM auth_remember_tokens WHERE selector = ?', [$selector]), 'no second rotation');
        self::assertSame(2, $this->tokens($user));

        $this->db->query('UPDATE auth_remember_tokens SET rotated_at = NOW() - INTERVAL 1 HOUR WHERE selector = ?', [$selector]);
        self::assertNull($this->consume($old), 'the old cookie replayed later is rejected');
        self::assertSame(0, $this->tokens($user), 'and every token of the user is revoked');
    }

    public function testAWrongValidatorIsNeverAcceptedAsGrace(): void
    {
        $user = $this->user();
        [$selector] = explode(':', $this->token($user));
        self::assertNull($this->consume($selector . ':' . str_repeat('0', 64)));
        self::assertSame(0, $this->tokens($user));
    }

    /**
     * A browser restoring several tabs sends the same cookie in overlapping requests; some arrive just
     * after another one already rotated the validator. Half start together, half 100–300 ms later.
     */
    public function testOverlappingRequestsWithOneCookieDoNotRevoke(): void
    {
        $user = $this->user();
        $cookie = $this->token($user);
        $env = getenv();
        $env['DB_DATABASE'] = (string) config('db.test_database');
        $start = microtime(true) + 1.5;

        $procs = [];
        foreach ([0, 0, 0, 0.1, 0.2, 0.3] as $delay) {
            $proc = proc_open([PHP_BINARY, __DIR__ . '/workers/remember-worker.php', $cookie, sprintf('%.6F', $start + $delay)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $procs[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $results[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
            proc_close($proc);
        }

        self::assertSame(array_fill(0, 6, (string) $user), $results, 'all six parallel requests are logged in');
        self::assertSame(1, $this->tokens($user), 'the token survives');
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiter stored in the `rate_limits` table,
 * so limits hold across processes and survive session resets.
 *
 * Guard actions with attempt(): it counts first, atomically, then decides, so parallel requests
 * cannot all slip through a "check, then increment" gap. release() refunds an attempt that
 * should not count (e.g. a login with the correct password).
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Record one attempt and return whether the caller is still within $max for the current window.
     * The increment and the count this caller sees come from one atomic statement (row lock on the
     * bucket), so of N simultaneous callers at most $max get true.
     */
    public function attempt(string $key, int $max, int $decaySeconds): bool
    {
        return $this->hit($key, $decaySeconds) <= $max;
    }

    /** Refund one attempt in the current window (never below zero). */
    public function release(string $key): void
    {
        $this->db->query(
            'UPDATE rate_limits SET hits = IF(hits > 0, hits - 1, 0) WHERE bucket = ? AND reset_at > NOW()',
            [$this->bucket($key)],
        );
    }

    /** Read-only check. On its own it does not stop parallel requests: guard actions with attempt(). */
    public function tooManyAttempts(string $key, int $max): bool
    {
        $row = $this->db->fetch(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND reset_at > NOW()',
            [$this->bucket($key)],
        );

        return $row !== null && (int) $row['hits'] >= $max;
    }

    /**
     * Record one attempt. Returns the hit count produced by THIS statement — not a later re-read that
     * could include other requests: a new row is 1, an updated row reports its value via LAST_INSERT_ID(expr).
     */
    public function hit(string $key, int $decaySeconds): int
    {
        $stmt = $this->db->query(
            'INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, NOW() + INTERVAL ? SECOND)
             ON DUPLICATE KEY UPDATE
               hits = LAST_INSERT_ID(IF(reset_at > NOW(), hits + 1, 1)),
               reset_at = IF(reset_at > NOW(), reset_at, VALUES(reset_at))',
            [$this->bucket($key), $decaySeconds],
        );

        // Affected rows: 1 = a new row was inserted, 2 = an existing row was updated.
        return $stmt->rowCount() === 1 ? 1 : (int) $this->db->value('SELECT LAST_INSERT_ID()');
    }

    public function availableIn(string $key): int
    {
        $seconds = $this->db->value(
            'SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), reset_at), 0) FROM rate_limits WHERE bucket = ?',
            [$this->bucket($key)],
        );

        return (int) ($seconds ?? 0);
    }

    public function clear(string $key): void
    {
        $this->db->query('DELETE FROM rate_limits WHERE bucket = ?', [$this->bucket($key)]);
    }

    /** Keys may contain emails/IPs; store only a hash. */
    private function bucket(string $key): string
    {
        return hash('sha256', $key);
    }
}

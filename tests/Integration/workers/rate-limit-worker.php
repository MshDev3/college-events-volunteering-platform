<?php

declare(strict_types=1);

/**
 * Child process for RateLimitTest: waits for a shared start time, then makes ONE attempt, so many
 * processes hit the database at the same moment. Prints "pass", "failed" or "throttled".
 *
 *   php rate-limit-worker.php limiter <key> <max> <start-microtime>
 *   php rate-limit-worker.php login <identifier> <ip> <start-microtime>
 *
 * DB_DATABASE is supplied by the parent through the environment (the test database).
 */

use App\Core\Exceptions\ValidationException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Domain\Role;
use App\Services\Auth\AuthService;

require dirname(__DIR__, 3) . '/app/bootstrap.php';

[, $mode, $subject, $arg, $start] = $argv;
if (config('db.database') !== config('db.test_database')) {
    fwrite(STDERR, 'Worker must run on the test database.');
    exit(2);
}
app(App\Core\Database::class)->value('SELECT 1'); // connect before the start line

while (microtime(true) < (float) $start) {
    usleep(500);
}

if ($mode === 'limiter') {
    echo app(RateLimiter::class)->attempt($subject, (int) $arg, 60) ? 'pass' : 'throttled';
    exit(0);
}

try {
    app(AuthService::class)->attempt($subject, 'Wrong-password-1', Role::STUDENT, false, new Request('POST', '/login', [], [], [], [], ['REMOTE_ADDR' => $arg]));
    echo 'pass';
} catch (ValidationException $e) {
    echo ($e->errors['identifier'][0] ?? '') === t('auth.errors.failed') ? 'failed' : 'throttled';
}

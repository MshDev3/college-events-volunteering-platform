<?php

declare(strict_types=1);

/**
 * Child process for RememberMeTest: waits for a shared start time, then presents ONE remember-me
 * cookie. Prints the authenticated user id, or "null".
 *
 *   php remember-worker.php <selector:validator> <start-microtime>
 */

use App\Core\Request;
use App\Services\Auth\RememberMeService;

require dirname(__DIR__, 3) . '/app/bootstrap.php';

[, $cookie, $start] = $argv;
if (config('db.database') !== config('db.test_database')) {
    fwrite(STDERR, 'Worker must run on the test database.');
    exit(2);
}
$service = app(RememberMeService::class);
app(App\Core\Database::class)->value('SELECT 1');

while (microtime(true) < (float) $start) {
    usleep(500);
}

$userId = $service->consume(new Request('GET', '/', [], [], [], [$service->cookieName() => $cookie], ['REMOTE_ADDR' => '127.0.0.1']));
echo $userId === null ? 'null' : (string) $userId;

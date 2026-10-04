<?php

declare(strict_types=1);

/**
 * Child process for AdminGuardTest: waits for a shared start time, then performs ONE admin action as the
 * given admin on the given user. Prints "ok" or the business-rule key that refused it.
 *
 *   php admin-worker.php <demote|deactivate> <actor-id> <target-id> <start-microtime>
 */

use App\Core\Exceptions\BusinessRuleException;
use App\Services\UserService;

require dirname(__DIR__, 3) . '/app/bootstrap.php';

[, $action, $actorId, $targetId, $start] = $argv;
if (config('db.database') !== config('db.test_database')) {
    fwrite(STDERR, 'Worker must run on the test database.');
    exit(2);
}
$service = app(UserService::class);
$actor = $service->find((int) $actorId);
app(App\Core\Database::class)->value('SELECT 1');

while (microtime(true) < (float) $start) {
    usleep(500);
}

try {
    match ($action) {
        'demote' => $service->changeRole($actor, (int) $targetId, 'STUDENT'),
        'deactivate' => $service->setActive($actor, (int) $targetId, false),
    };
    echo 'ok';
} catch (BusinessRuleException $e) {
    echo $e->key;
}

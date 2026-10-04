<?php

declare(strict_types=1);

/**
 * Child process for ActivityGuardsTest: waits for a shared start time, then performs ONE admin action on an
 * event. Prints "ok" or the business-rule key that refused it.
 *
 *   php activity-worker.php delete <event-id> <admin-id> <start-microtime>
 */

use App\Core\Exceptions\BusinessRuleException;
use App\Services\EventService;

require dirname(__DIR__, 3) . '/app/bootstrap.php';

[, $action, $id, $adminId, $start] = $argv;
if (config('db.database') !== config('db.test_database')) {
    fwrite(STDERR, 'Worker must run on the test database.');
    exit(2);
}
$service = app(EventService::class);
app(App\Core\Database::class)->value('SELECT 1');

while (microtime(true) < (float) $start) {
    usleep(500);
}

try {
    match ($action) {
        'delete' => $service->delete((int) $adminId, (int) $id),
    };
    echo 'ok';
} catch (BusinessRuleException $e) {
    echo $e->key;
}

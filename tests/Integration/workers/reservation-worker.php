<?php

declare(strict_types=1);

/**
 * Child process for ReservationTransitionTest: waits for a shared start time, then performs ONE
 * admin decision on a reservation. Prints "ok" or the business-rule key that refused it.
 *
 *   php reservation-worker.php <approve|reject|cancel> <reservation-id> <admin-id> <start-microtime>
 */

use App\Core\Exceptions\BusinessRuleException;
use App\Services\ReservationService;

require dirname(__DIR__, 3) . '/app/bootstrap.php';

[, $action, $id, $adminId, $start] = $argv;
if (config('db.database') !== config('db.test_database')) {
    fwrite(STDERR, 'Worker must run on the test database.');
    exit(2);
}
$service = app(ReservationService::class);
app(App\Core\Database::class)->value('SELECT 1');

while (microtime(true) < (float) $start) {
    usleep(500);
}

try {
    match ($action) {
        'approve' => $service->approve((int) $adminId, (int) $id, null),
        'reject' => $service->reject((int) $adminId, (int) $id, 'Parallel rejection'),
        'cancel' => $service->cancel((int) $adminId, (int) $id, true, 'Parallel cancellation'),
    };
    echo 'ok';
} catch (BusinessRuleException $e) {
    echo $e->key;
}

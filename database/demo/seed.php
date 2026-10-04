<?php

declare(strict_types=1);

/**
 * LOCAL DEMO DATA ONLY — never run on a server, never ship this folder in a production package.
 *
 *   php database/demo/seed.php
 *
 * Inserts demo accounts, events, volunteering, reservations and feedback into DB_DATABASE.
 * Refuses unless APP_ENV=local. Passwords come from DEMO_ADMIN_PASSWORD / DEMO_STUDENT_PASSWORD
 * in .env (must be strong) or are generated and printed once.
 */

use App\Core\Database;
use App\Services\Auth\PasswordHasher;
use Database\Demo\DemoSeeder;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (config('app.env') !== 'local') {
    fwrite(STDERR, 'Refusing to seed demo data: APP_ENV is not "local". Demo accounts must never exist on a server.' . PHP_EOL);
    exit(1);
}

require __DIR__ . '/DemoSeeder.php';

try {
    (new DemoSeeder(app(Database::class), app(PasswordHasher::class), require __DIR__ . '/credentials.php'))
        ->run(static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        });
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

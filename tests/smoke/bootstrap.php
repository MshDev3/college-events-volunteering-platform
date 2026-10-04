<?php

declare(strict_types=1);

/**
 * Shared setup for the HTTP smoke/E2E scripts. They run against the local site with the demo data seeded.
 * Demo credentials come from .env (DEMO_*), never from the repository.
 */

require __DIR__ . '/HttpClient.php';
$app = require dirname(__DIR__, 2) . '/app/bootstrap.php';

// These scripts write to and clean up DB_DATABASE (including every rate-limit row): never on a server.
if (config('app.env') !== 'local') {
    fwrite(STDERR, "Refusing to run: the smoke tests modify DB_DATABASE and require APP_ENV=local.\n");
    exit(2);
}

$demo = require dirname(__DIR__, 2) . '/database/demo/credentials.php';
if ($demo['admin_password'] === '' || $demo['student_password'] === '') {
    fwrite(STDERR, "Set DEMO_ADMIN_PASSWORD and DEMO_STUDENT_PASSWORD in .env (the passwords used by `php database/demo/seed.php`).\n");
    exit(2);
}

define('ADMIN_EMAIL', (string) $demo['admin_email']);
define('ADMIN_PASSWORD', (string) $demo['admin_password']);
define('STUDENT_EMAIL', (string) $demo['student_email']);
define('STUDENT_PASSWORD', (string) $demo['student_password']);
define('BASE_URL', rtrim($argv[1] ?? (string) config('app.url'), '/'));

return $app;

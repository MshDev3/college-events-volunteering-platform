<?php

declare(strict_types=1);

/**
 * Demo account settings from .env (DEMO_*). LOCAL DEVELOPMENT ONLY — used by database/demo/seed.php
 * and the HTTP smoke tests. The application itself never reads these variables.
 *
 * @return array{admin_email:string, admin_password:string, student_email:string, student_password:string}
 */

$read = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);

    return $value === false || $value === null ? $default : (string) $value;
};

return [
    'admin_email' => $read('DEMO_ADMIN_EMAIL', 'admin@college.test'),
    'admin_password' => $read('DEMO_ADMIN_PASSWORD'),
    'student_email' => $read('DEMO_STUDENT_EMAIL', 'student@college.test'),
    'student_password' => $read('DEMO_STUDENT_PASSWORD'),
];

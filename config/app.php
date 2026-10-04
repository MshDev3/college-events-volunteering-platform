<?php

declare(strict_types=1);

/**
 * Central configuration. Values come from .env; nothing secret is hard-coded here.
 * Access with config('app.name'), config('db.host'), ...
 */

// .env values (loaded into $_ENV), else real environment variables (CI, containers, a one-off shell
// override such as DB_MIGRATE_PASSWORD=... php bin/console migrate), else the default.
$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : $default);
$bool = static fn (string $key, bool $default = false): bool =>
    filter_var($env($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);

return [
    'app' => [
        'name' => $env('APP_NAME', 'TVTC Campus'),
        'env' => $env('APP_ENV', 'production'),
        'debug' => $bool('APP_DEBUG'),
        'url' => rtrim((string) $env('APP_URL', 'http://localhost'), '/'),
        'timezone' => $env('APP_TIMEZONE', 'Asia/Riyadh'),
        'default_locale' => $env('APP_DEFAULT_LOCALE', 'ar'),
        'locales' => ['ar', 'en'],
        'rtl_locales' => ['ar'],
        // Proxies allowed to set X-Forwarded-Proto / X-Forwarded-For. Empty = trust nobody.
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) $env('TRUSTED_PROXIES', ''))))),
    ],
    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => (int) $env('DB_PORT', 3306),
        // No defaults for the database or account: an unconfigured install must fail, not fall back to root.
        'database' => (string) $env('DB_DATABASE', ''),
        // Runtime account: SELECT, INSERT, UPDATE, DELETE only (database/setup/create_app_user.sql).
        'username' => (string) $env('DB_USERNAME', ''),
        'password' => (string) $env('DB_PASSWORD', ''),
        // Schema account used only by `bin/console migrate` (CREATE/ALTER/DROP/INDEX...).
        // Empty = migrations use DB_USERNAME, which then needs those privileges itself.
        'migrate_username' => (string) $env('DB_MIGRATE_USERNAME', ''),
        'migrate_password' => (string) $env('DB_MIGRATE_PASSWORD', ''),
        'legacy_database' => $env('LEGACY_DB_DATABASE', 'project'),
        'test_database' => (string) $env('DB_TEST_DATABASE', ''),
    ],
    'session' => [
        'name' => $env('SESSION_NAME', 'tvtc_session'),
        'idle_minutes' => (int) $env('SESSION_IDLE_MINUTES', 120),
        'absolute_minutes' => (int) $env('SESSION_ABSOLUTE_MINUTES', 720),
    ],
    'auth' => [
        'remember_days' => (int) $env('REMEMBER_ME_DAYS', 30),
        'remember_cookie' => 'tvtc_remember',
        'reset_minutes' => (int) $env('PASSWORD_RESET_MINUTES', 60),
        // Lifetime of the link that confirms a new login email.
        'email_change_minutes' => (int) $env('EMAIL_CHANGE_MINUTES', 60),
        // Login throttling (failed attempts; a correct password refunds its attempt):
        // per identifier+IP and per IP within login_decay_seconds, and per identifier across ALL IPs
        // within login_account_decay_seconds (stops distributed guessing against one account).
        'login_max_attempts' => 5,
        'login_max_attempts_per_ip' => 30,
        'login_decay_seconds' => 900,
        'login_max_attempts_per_account' => 20,
        'login_account_decay_seconds' => 3600,
        'reset_max_per_email' => 3,
        'reset_max_per_ip' => 10,
        'reset_decay_seconds' => 3600,
        // Registration throttling per client IP. Generous enough for a campus network behind one NAT
        // (a whole class registering), strict enough to stop scripted mass sign-ups and email/ID probing.
        'register_max_attempts_per_ip' => 60,      // form submissions (incl. failed validation) per 15 min
        'register_attempt_decay_seconds' => 900,
        'register_max_accounts_per_ip' => 30,      // accounts actually created per hour
        'register_account_decay_seconds' => 3600,
    ],
    'mail' => [
        'driver' => $env('MAIL_DRIVER', 'log'),
        // Password-reset emails are queued: after_response = sent right after the page is delivered;
        // cron = only by `php bin/console mail:work` (run it every minute).
        'queue' => $env('MAIL_QUEUE', 'after_response'),
        'host' => $env('MAIL_HOST', ''),
        'port' => (int) $env('MAIL_PORT', 587),
        'encryption' => $env('MAIL_ENCRYPTION', 'tls'),
        'username' => $env('MAIL_USERNAME', ''),
        'password' => $env('MAIL_PASSWORD', ''),
        'from_address' => $env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
        'from_name' => $env('MAIL_FROM_NAME', 'TVTC Campus'),
    ],
    'uploads' => [
        'max_bytes' => (int) $env('UPLOAD_MAX_MB', 5) * 1024 * 1024,
        'attachment_mimes' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ],
        'image_mimes' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
    ],
    'pagination' => [
        'default' => 10,
        'min' => 5,
        'max' => 50,
    ],
];

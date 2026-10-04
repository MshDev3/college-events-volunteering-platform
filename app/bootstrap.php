<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Mail\LogMailer;
use App\Core\Mail\Mailer;
use App\Core\Mail\SmtpMailer;
use App\Core\Router;
use App\Core\Translator;
use App\Core\View;
use App\Http\Middleware;

/**
 * Builds the application: environment, configuration, services and routes.
 * Returns the App kernel (used by public/index.php, bin/console and tests).
 */

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

/**
 * Stop before anything else runs when the installation is misconfigured. The reason goes to the
 * PHP error log (and the terminal for CLI); visitors only see a generic 503.
 */
$failConfiguration = static function (string $reason): never {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Configuration error: $reason" . PHP_EOL);
        exit(1);
    }
    error_log("TVTC Campus configuration error: $reason");
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Service not configured.\nالخدمة غير مهيأة.";
    exit;
};

// Configuration comes from .env, or from real environment variables (CI, containers) when there is no file.
if (!is_file($basePath . '/.env') && getenv('DB_USERNAME') === false && !isset($_ENV['DB_USERNAME']) && !isset($_SERVER['DB_USERNAME'])) {
    $failConfiguration('Missing .env: copy .env.example to .env and fill in the values (see README.md).');
}
Dotenv\Dotenv::createImmutable($basePath)->safeLoad();

$config = new Config(require $basePath . '/config/app.php');

if ((string) $config->get('db.username') === '' || (string) $config->get('db.database') === '') {
    $failConfiguration('DB_DATABASE and DB_USERNAME must be set (see .env.example).');
}
// The web application must not run with the server's superuser outside a developer's machine.
if ($config->get('app.env') !== 'local' && strcasecmp((string) $config->get('db.username'), 'root') === 0) {
    $failConfiguration('DB_USERNAME=root is not allowed when APP_ENV is not "local". Create a restricted account: database/setup/create_app_user.sql');
}

date_default_timezone_set((string) $config->get('app.timezone'));
mb_internal_encoding('UTF-8');

// Errors are logged, never printed: the kernel renders safe error pages.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Stack traces end up in storage/logs: keep call arguments (passwords, tokens, emails) out of them.
// #[\SensitiveParameter] covers the known secrets even if a server setting turns this back on.
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$container = new Container();
$app = new App($container, $basePath);

$container->instance(Container::class, $container);
$container->instance(App::class, $app);
$container->instance(Config::class, $config);

$container->set(Database::class, static fn () => Database::connect(
    (array) $config->get('db'),
    null,
    (string) $config->get('app.timezone'),
));
$container->set(Translator::class, static fn () => new Translator(
    $basePath . '/locales',
    (array) $config->get('app.locales'),
    (string) $config->get('app.default_locale'),
));
$container->set(View::class, static fn () => new View($basePath . '/views'));
$container->set(Logger::class, static fn () => new Logger($basePath . '/storage/logs'));
$container->set(LogMailer::class, static fn () => new LogMailer($basePath . '/storage/mail'));
$container->set(Mailer::class, static fn (Container $c) => $config->get('mail.driver') === 'smtp'
    ? new SmtpMailer((array) $config->get('mail'))
    : $c->get(LogMailer::class));

$container->set(Router::class, static function (Container $c) use ($basePath): Router {
    $router = new Router($c);
    $router->aliasMiddleware([
        'session' => Middleware\StartSession::class,
        'locale' => Middleware\SetLocale::class,
        'authenticate' => Middleware\LoadAuthenticatedUser::class,
        'csrf' => Middleware\VerifyCsrfToken::class,
        'auth' => Middleware\RequireAuth::class,
        'guest' => Middleware\RequireGuest::class,
        'role' => Middleware\RequireRole::class,
        'local' => Middleware\LocalOnly::class,
        'layout' => Middleware\ShareLayoutData::class,
        'admin.nav' => Middleware\ShareAdminNav::class,
    ]);
    (require $basePath . '/config/routes.php')($router);

    return $router;
});

// Order matters: the user is loaded before the locale is resolved (it may use their saved language).
$app->setGlobalMiddleware(['session', 'authenticate', 'locale', 'layout', 'csrf']);

return $app;

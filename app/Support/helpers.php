<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Translator;
use App\Domain\User;
use App\Services\Auth\CurrentUser;
use App\Support\DateFormatter;

/**
 * Global helpers for templates and controllers. Kept small and side-effect free.
 */

/**
 * @template T of object
 * @param class-string<T>|null $id
 * @return ($id is null ? App : T)
 */
function app(?string $id = null): object
{
    $app = App::instance();

    return $id === null ? $app : $app->container()->get($id);
}

function config(string $key, mixed $default = null): mixed
{
    return app(Config::class)->get($key, $default);
}

/** HTML-escape any value for output. */
function e(mixed $value): string
{
    if ($value instanceof \BackedEnum) {
        $value = $value->value;
    }

    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string, string|int|float|null> $params */
function t(string $key, array $params = []): string
{
    return app(Translator::class)->get($key, $params);
}

/** Pluralized translation. @param array<string, string|int|float|null> $params */
function tc(string $key, int|float $count, array $params = []): string
{
    return app(Translator::class)->choice($key, $count, $params);
}

function locale(): string
{
    return app(Translator::class)->locale();
}

function is_rtl(): bool
{
    return in_array(locale(), (array) config('app.rtl_locales', ['ar']), true);
}

function dir_attr(): string
{
    return is_rtl() ? 'rtl' : 'ltr';
}

/** Pick the localized column of a row: localized($event, 'title') → title_ar / title_en. @param array<string, mixed> $row */
function localized(array $row, string $field): string
{
    $primary = $row[$field . '_' . locale()] ?? null;
    if (is_string($primary) && $primary !== '') {
        return $primary;
    }
    foreach ((array) config('app.locales') as $other) {
        if (is_string($row[$field . '_' . $other] ?? null) && $row[$field . '_' . $other] !== '') {
            return $row[$field . '_' . $other];
        }
    }

    return '';
}

function base_path_url(): string
{
    return rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');
}

/** App URL for a path ("/events/3"), with optional query parameters. @param array<string, mixed> $query */
function url(string $path = '/', array $query = []): string
{
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
    $qs = $query === [] ? '' : '?' . http_build_query($query);

    return base_path_url() . '/' . ltrim($path, '/') . $qs;
}

/** Absolute URL (for emails). Uses APP_URL, never the Host header. */
function absolute_url(string $path = '/'): string
{
    return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
}

/**
 * Safe mailto: link (RFC 6068). The address is percent-encoded around its last "@", so a stored
 * address such as "a?bcc=x@evil.com" or "a&cc=…" can never add recipients, headers or a body.
 * Returns a raw URL: escape it with e() in HTML attributes.
 */
function mailto_href(string $email, ?string $subject = null): string
{
    $at = strrpos($email, '@');
    $address = $at === false
        ? rawurlencode($email)
        : rawurlencode(substr($email, 0, $at)) . '@' . rawurlencode(substr($email, $at + 1));

    return 'mailto:' . $address . ($subject !== null ? '?subject=' . rawurlencode($subject) : '');
}

/** Versioned asset URL (cache-busted by file mtime). */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = dirname(__DIR__, 2) . '/public/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '';

    return url($path) . ($version !== '' ? '?v=' . $version : '');
}

/**
 * Cover image for an event / volunteer opportunity: its own upload, else the default for its
 * type (events) or category (volunteering). Never another record's image (e.g. the venue's).
 * @param array<string, mixed> $item row carrying image_path and type_code
 */
function activity_image(array $item, string $kind): string
{
    if (!empty($item['image_path'])) {
        return (string) $item['image_path'];
    }
    // Each type/category has numbered photos named after its code with hyphens
    // (event_management → event-management-1.jpg, -2.jpg…); "other" and codes without photos use general-N.jpg.
    // The record id picks one (id % count): stable for a record, different for its neighbours.
    // See public/assets/img/defaults/CREDITS.md.
    $folder = 'assets/img/defaults/' . ($kind === 'event' ? 'events' : 'volunteering');
    $name = str_replace('_', '-', preg_replace('/[^a-z0-9_]/', '', (string) ($item['type_code'] ?? '')) ?? '');
    $photos = $name !== '' && $name !== 'other' ? default_photos($folder, $name) : [];
    if ($photos === []) {
        $photos = default_photos($folder, 'general');
    }

    return $photos === [] ? "$folder/general-1.jpg" : $photos[(int) ($item['id'] ?? 0) % count($photos)];
}

/**
 * Bundled default photos "<name>-<n>.jpg" in a public folder, in numeric order (cached per request).
 * @return list<string> web paths
 */
function default_photos(string $folder, string $name): array
{
    static $cache = [];
    $key = "$folder/$name";
    if (!isset($cache[$key])) {
        $files = glob(dirname(__DIR__, 2) . "/public/$folder/$name-*.jpg") ?: [];
        $files = array_values(array_filter($files, static fn (string $f): bool => preg_match('/-\d+\.jpg$/', $f) === 1));
        natsort($files);
        $cache[$key] = array_values(array_map(static fn (string $f): string => "$folder/" . basename($f), $files));
    }

    return $cache[$key];
}

/** Path scope for cookies: the app's base path. */
function cookie_path(): string
{
    $path = base_path_url();

    return $path === '' ? '/' : $path;
}

function csrf_token(): string
{
    return app(Csrf::class)->token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . e(csrf_token()) . '">';
}

function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

/** Previously submitted input (after a validation error). */
function old(string $key, mixed $default = ''): mixed
{
    $old = app(Session::class)->getFlash('_old_input', []);

    return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
}

/** First validation error for a field, or null. */
function error(string $key): ?string
{
    $errors = app(Session::class)->getFlash('_errors', []);

    return is_array($errors) && isset($errors[$key][0]) ? (string) $errors[$key][0] : null;
}

function has_errors(): bool
{
    $errors = app(Session::class)->getFlash('_errors', []);

    return is_array($errors) && $errors !== [];
}

function auth_user(): ?User
{
    return app(CurrentUser::class)->user();
}

/** Is the current request path equal to / under $path? Used for active navigation. */
function nav_active(string $path, bool $exact = false): bool
{
    $current = app()->request()?->path ?? '/';
    if ($exact || $path === '/') {
        return $current === $path;
    }

    return $current === $path || str_starts_with($current, rtrim($path, '/') . '/');
}

function dates(): DateFormatter
{
    return app(DateFormatter::class);
}

/** Locale-aware number formatting (Western digits in both languages, as is common in KSA web UIs). */
function fmt_number(int|float|string|null $value, int $decimals = 0): string
{
    $value = (float) ($value ?? 0);
    if ($decimals > 0 && floor($value) === $value) {
        $decimals = 0;
    }

    return number_format($value, $decimals, '.', ',');
}

/**
 * value => translated label for a status/type enum, for <select> options.
 * @param list<\App\Domain\Status&\BackedEnum> $cases
 * @return array<string, string>
 */
function enum_options(array $cases): array
{
    $options = [];
    foreach ($cases as $case) {
        $options[(string) $case->value] = t($case->labelKey());
    }

    return $options;
}

/** Current query string merged with overrides (for filters & pagination links). @param array<string, mixed> $overrides */
function query_with(array $overrides): string
{
    $request = app()->request();
    $query = array_merge($request?->allQuery() ?? [], $overrides);
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');

    return url($request?->path ?? '/', $query);
}

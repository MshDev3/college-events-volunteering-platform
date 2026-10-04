<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened wrapper around PHP native sessions with flash-data support.
 * Session cookie: HttpOnly, SameSite=Lax, Secure on HTTPS, expires with the browser.
 */
final class Session
{
    private bool $started = false;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * PHP ini settings applied before the session starts.
     * @return array<string, string>
     */
    public static function iniSettings(int $absoluteMinutes, int $phpVersionId = PHP_VERSION_ID): array
    {
        $settings = [
            'session.use_strict_mode' => '1',
            'session.use_only_cookies' => '1',
            'session.use_trans_sid' => '0',
        ];
        // Long random IDs. PHP 8.4 deprecates these two settings (the error handler would turn that into an exception)
        // and uses its own, larger default there, so they are only set on older versions.
        if ($phpVersionId < 80400) {
            $settings['session.sid_length'] = '48';
            $settings['session.sid_bits_per_character'] = '6';
        }
        $settings['session.gc_maxlifetime'] = (string) ($absoluteMinutes * 60);

        return $settings;
    }
    public function start(Request $request): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        foreach (self::iniSettings((int) $this->config->get('session.absolute_minutes', 720)) as $name => $value) {
            ini_set($name, $value);
        }

        session_name((string) $this->config->get('session.name', 'app_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => cookie_path(),
            'secure' => $request->cookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;
        $this->ageFlashData();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    /** New session id, keep data. Call on every privilege change (login, logout). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Wipe all data and issue a fresh id (the old id becomes useless). */
    public function invalidate(): void
    {
        $_SESSION = [];
        $this->regenerate();
    }

    // ---- Flash data (available on the next request only) --------------------

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash']['new'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash']['old'][$key] ?? $_SESSION['_flash']['new'][$key] ?? $default;
    }

    private function ageFlashData(): void
    {
        $_SESSION['_flash'] = [
            'old' => $_SESSION['_flash']['new'] ?? [],
            'new' => [],
        ];
    }
}

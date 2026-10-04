<?php

declare(strict_types=1);

namespace App\Core;

/** Immutable view of the incoming HTTP request. */
final class Request
{
    /** @var array<string, string> Route parameters, set by the router. */
    private array $params = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $cookies,
        private readonly array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        // HTML forms can only send GET/POST; allow a hidden _method override for PUT/DELETE.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $basePath = (string) parse_url((string) config('app.url'), PHP_URL_PATH);
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
            $uriPath = substr($uriPath, strlen($basePath));
        }
        $path = '/' . trim(rawurldecode($uriPath), '/');

        return new self($method, $path, $_GET, $_POST, $_FILES, $_COOKIE, $_SERVER);
    }

    /**
     * Read from POST body, falling back to the query string. Scalars only: the application has no
     * array fields, so "field[]=x" yields $default instead of an array nobody expects (L-9).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? null;

        return is_scalar($value) ? $value : $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** Query-string value; scalars only (see input()). */
    public function query(string $key, mixed $default = null): mixed
    {
        $value = $this->query[$key] ?? null;

        return is_scalar($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    /** Every scalar input (arrays dropped, see input()). @return array<string, mixed> */
    public function all(): array
    {
        return array_filter(array_merge($this->query, $this->body), 'is_scalar');
    }

    /**
     * Only the requested keys, trimmed strings.
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $this->body[$key] ?? $this->query[$key] ?? null;
            $out[$key] = is_string($value) ? trim($value) : $value;
        }

        return $out;
    }

    public function boolean(string $key): bool
    {
        return filter_var($this->input($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * For flags that change state (activate / deactivate): true or false only when the client really sent one of the
     * usual boolean spellings, null when the field is missing, empty or garbled. boolean() would turn all of those into
     * false, which for "active" means deactivating the account.
     */
    public function explicitBoolean(string $key): ?bool
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return null;
        }
        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    public function cookie(string $key): ?string
    {
        $value = $this->cookies[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Client IP. X-Forwarded-For is client-controlled, so it is only consulted when the direct peer
     * (REMOTE_ADDR) is a proxy listed in TRUSTED_PROXIES; the right-most untrusted address wins.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->fromTrustedProxy()) {
            return $remote;
        }
        $chain = array_map('trim', explode(',', (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '')));
        $trusted = (array) config('app.trusted_proxies', []);
        foreach (array_reverse($chain) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false && !in_array($candidate, $trusted, true)) {
                return $candidate;
            }
        }

        return $remote;
    }

    public function isLoopback(): bool
    {
        $ip = $this->ip();

        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    private function fromTrustedProxy(): bool
    {
        $trusted = (array) config('app.trusted_proxies', []);

        return $trusted !== [] && in_array((string) ($this->server['REMOTE_ADDR'] ?? ''), $trusted, true);
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /** Did this request arrive over HTTPS (directly, or via a trusted TLS-terminating proxy)? */
    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ($this->fromTrustedProxy()) {
            $proto = strtolower(trim(explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

            return $proto === 'https';
        }

        return false;
    }

    /** Cookies are Secure when the request is HTTPS, or whenever the site is configured with an https:// APP_URL. */
    public function cookieSecure(): bool
    {
        return $this->isSecure() || str_starts_with((string) config('app.url'), 'https://');
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('Accept'), 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function isGet(): bool
    {
        return $this->method === 'GET' || $this->method === 'HEAD';
    }

    /** Full path including the query string, relative to the app root (for ?next= redirects). */
    public function fullPath(): string
    {
        $qs = http_build_query($this->query);

        return $this->path . ($qs !== '' ? '?' . $qs : '');
    }

    /** @param array<string, string> $params */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;

        return $clone;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }
}

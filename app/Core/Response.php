<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private const REASONS = [
        200 => 'OK', 201 => 'Created', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        409 => 'Conflict', 419 => 'Page Expired', 422 => 'Unprocessable Content', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 503 => 'Service Unavailable',
    ];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    public function __construct(private string $body = '', private int $status = 200)
    {
        $this->headers['Content-Type'] = 'text/html; charset=UTF-8';
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /** Consistent JSON envelope: {success, data} or {success, message, errors}. */
    public static function json(array $payload, int $status = 200): self
    {
        $response = new self(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
        );
        $response->headers['Content-Type'] = 'application/json; charset=UTF-8';

        return $response;
    }

    public static function success(mixed $data = null, int $status = 200): self
    {
        return self::json(['success' => true, 'data' => $data ?? new \stdClass()], $status);
    }

    /** @param array<string, list<string>> $errors */
    public static function failure(string $message, int $status = 400, array $errors = []): self
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return self::json($payload, $status);
    }

    /**
     * The path itself when it is a plain app-relative path ("/events/3?page=2"), else null.
     * For redirect targets that come from users or stored data: rejects "//host", absolute URLs, backslashes
     * and control characters (browsers drop a tab or newline, so "/<TAB>/evil.com" would become "//evil.com").
     */
    public static function localPath(string $path): ?string
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1) {
            return null;
        }

        return $path;
    }

    /** Redirect to an app-relative path ("/login") or an absolute same-app URL. */
    public static function redirect(string $to, int $status = 303): self
    {
        $response = new self('', $status);
        if (str_starts_with($to, 'http')) {
            $response->headers['Location'] = $to;
        } else {
            // Last line of defence: a relative target with control characters or backslashes is never sent.
            $response->headers['Location'] = url(preg_match('/[\x00-\x1f\x7f\\\\]/', $to) === 1 ? '/' : $to);
        }

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string, mixed> $options setcookie() options */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            // An explicit reason phrase is required: Apache turns unknown codes like 419 into 500 otherwise.
            $protocol = (string) ($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
            header(sprintf('%s %d %s', $protocol, $this->status, self::REASONS[$this->status] ?? 'Status'), true, $this->status);
            // Don't advertise the PHP version even when php.ini still has expose_php=On (see SECURITY.md).
            header_remove('X-Powered-By');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            // Lets the client know the body is complete even while PHP keeps running deferred work
            // (App::terminate()). Skipped when PHP compresses the output, which changes the length.
            if (!isset($this->headers['Content-Length']) && !filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
                header('Content-Length: ' . strlen($this->body));
            }
            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }
        echo $this->body;
    }
}

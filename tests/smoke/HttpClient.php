<?php

declare(strict_types=1);

/** Tiny cookie-keeping HTTP client for smoke/E2E scripts (no redirects followed automatically). */
final class HttpClient
{
    private string $cookieFile;

    public function __construct(private readonly string $base)
    {
        $this->cookieFile = (string) tempnam(sys_get_temp_dir(), 'ck');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** @param array<string, mixed> $data @return array{status:int, body:string, headers:array<string,string>} */
    public function post(string $path, array $data, bool $withToken = true, ?string $referer = null): array
    {
        if ($withToken && !isset($data['_token'])) {
            $data['_token'] = $this->csrfToken($referer ?? $path);
        }

        return $this->request('POST', $path, $data, $referer);
    }

    public function csrfToken(string $fromPath = '/contact'): string
    {
        $page = $this->get(parse_url($fromPath, PHP_URL_PATH) === '/login' ? '/login' : '/contact');
        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $page['body'], $m) !== 1) {
            throw new RuntimeException('CSRF token not found');
        }

        return $m[1];
    }

    public function login(string $identifier, string $password, string $role, bool $remember = false): array
    {
        $token = $this->csrfToken('/login');
        $res = $this->request('POST', '/login', [
            '_token' => $token, 'identifier' => $identifier, 'password' => $password, 'role' => $role,
        ] + ($remember ? ['remember' => '1'] : []), $this->base . '/login');
        if ($res['status'] !== 303 || str_contains($res['headers']['location'] ?? '', '/login')) {
            throw new RuntimeException("Login failed for $identifier (status {$res['status']}, location " . ($res['headers']['location'] ?? '-') . ')');
        }

        return $res;
    }

    public function cookie(string $name): ?string
    {
        foreach (file($this->cookieFile) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) === 7 && $parts[5] === $name) {
                return $parts[6];
            }
        }

        return null;
    }

    /** Replace the cookie jar with a single cookie scoped to the site's base path (e.g. a forged/replayed cookie). */
    public function setOnlyCookie(string $name, string $value): void
    {
        $host = (string) parse_url($this->base, PHP_URL_HOST);
        $path = rtrim((string) parse_url($this->base, PHP_URL_PATH), '/') ?: '/';
        file_put_contents($this->cookieFile, "$host\tFALSE\t$path\tFALSE\t0\t$name\t$value\n");
    }

    /** Keep only the named cookies (simulate the browser closing: session cookie gone, remember cookie kept). @param list<string> $keep */
    public function keepOnlyCookies(array $keep): void
    {
        $lines = file($this->cookieFile) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) !== 7 || in_array($parts[5], $keep, true)) {
                $out[] = $line;
            }
        }
        file_put_contents($this->cookieFile, implode('', $out));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $extraHeaders raw request header lines, e.g. 'Accept: application/json'
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public function request(string $method, string $path, array $data = [], ?string $referer = null, array $extraHeaders = []): array
    {
        $ch = curl_init($this->base . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($referer !== null) {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        }
        if ($extraHeaders !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }
}

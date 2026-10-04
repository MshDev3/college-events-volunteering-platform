<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use Throwable;

/**
 * HTTP kernel: runs global middleware + router, and turns every exception into a
 * safe response (translated page or JSON envelope). Internals are logged, never shown
 * unless APP_DEBUG=true in a local environment.
 */
final class App
{
    private static ?self $instance = null;

    private ?Request $request = null;

    /** @var list<string> middleware run for every request, in order */
    private array $globalMiddleware = [];

    public function __construct(private readonly Container $container, private readonly string $basePath)
    {
        self::$instance = $this;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application has not been bootstrapped.');
        }

        return self::$instance;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    /** @param list<string> $middleware */
    public function setGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    /**
     * After Response::send(): hand the complete response to the client, then run deferred work
     * (e.g. queued emails) so its duration never shows in response times.
     */
    public function terminate(): void
    {
        $deferred = $this->container->get(Deferred::class);
        if ($deferred->isEmpty()) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();       // PHP-FPM: closes the connection now
        } else {
            while (ob_get_level() > 0) {    // mod_php: Content-Length is set, so the client has the whole body
                ob_end_flush();
            }
            flush();
        }
        $deferred->run($this->container->get(Logger::class));
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $router = $this->container->get(Router::class);

        try {
            $response = $router->runPipeline(
                $request,
                $this->globalMiddleware,
                function (Request $req) use ($router): Response {
                    $this->request = $req;

                    return $router->dispatch($req);
                },
            );
        } catch (Throwable $e) {
            $response = $this->renderException($request, $e);
        }

        $this->container->get(CookieJar::class)->applyTo($response);

        return $this->withSecurityHeaders($response);
    }

    public function renderException(Request $request, Throwable $e): Response
    {
        if ($e instanceof ValidationException) {
            if ($request->wantsJson()) {
                return Response::failure(t('common.errors.validation'), 422, $e->errors);
            }
            $session = $this->container->get(Session::class);
            $session->flash('_errors', $e->errors);
            $session->flash('_old_input', $this->safeOldInput($request));
            $session->flash('error', t('common.errors.validation'));

            return Response::redirect($this->backUrl($request));
        }

        if ($e instanceof BusinessRuleException) {
            $message = t($e->key, $e->params);
            if ($request->wantsJson()) {
                return Response::failure($message, 409);
            }
            $session = $this->container->get(Session::class);
            $session->flash('error', $message);
            $session->flash('_old_input', $this->safeOldInput($request));

            return Response::redirect($this->backUrl($request));
        }

        $status = $e instanceof HttpException ? $e->status : 500;
        if ($status >= 500) {
            $this->container->get(Logger::class)->error($e::class . ': ' . $e->getMessage(), [
                'file' => $e->getFile() . ':' . $e->getLine(),
                'path' => $request->method . ' ' . $request->path,
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
            ]);
        }

        $messageKey = $e instanceof HttpException && $e->messageKey !== null
            ? $e->messageKey
            : 'common.errors.http_' . (in_array($status, [403, 404, 405, 419, 429], true) ? $status : 500);

        if ($request->wantsJson()) {
            return Response::failure(t($messageKey), $status);
        }

        $debug = (bool) config('app.debug') && config('app.env') === 'local' && $status >= 500;
        try {
            $html = $this->container->get(View::class)->render('pages/errors/error', [
                'status' => $status,
                'message' => t($messageKey),
                'debug' => $debug ? $e::class . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString() : null,
            ]);
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Error</title><p>Something went wrong. / حدث خطأ ما.</p>';
        }

        return Response::html($html, $status);
    }

    /**
     * Referer if it points inside this app (prevents open redirects). Without a usable Referer — e.g. the
     * reset-password page sends none (Referrer-Policy: no-referrer) — a form posted to a path that is also
     * a page (/reset-password/{token}, /register, /contact...) goes back to that page; otherwise home.
     */
    public function backUrl(Request $request): string
    {
        $referer = (string) $request->header('Referer');
        $appUrl = (string) config('app.url');
        if ($referer !== '' && str_starts_with($referer, $appUrl . '/')) {
            return $referer;
        }
        if ($referer === $appUrl) {
            return $referer;
        }
        if (!$request->isGet() && $this->container->get(Router::class)->hasGetRoute($request->path)) {
            return url($request->path);
        }

        return url('/');
    }

    /** Never flash passwords or tokens back into forms. @return array<string, mixed> */
    private function safeOldInput(Request $request): array
    {
        $input = $request->all();
        foreach (array_keys($input) as $key) {
            if (str_contains((string) $key, 'password') || $key === '_token' || $key === 'token') {
                unset($input[$key]);
            }
        }

        return $input;
    }

    private function withSecurityHeaders(Response $response): Response
    {
        $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', $response->header('Referrer-Policy') ?? 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($response->header('Content-Security-Policy') === null) {
            $response->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self' data:; style-src 'self' https://fonts.googleapis.com; "
                . "font-src 'self' https://fonts.gstatic.com data:; script-src 'self'; form-action 'self'; "
                . "frame-src https://www.google.com; frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
            );
        }
        if ($response->header('Cache-Control') === null) {
            $response->withHeader('Cache-Control', 'no-store, private');
        }
        if (self::sendsHsts($this->request, (string) config('app.env'))) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * HSTS only on a request that really arrived over HTTPS (directly, or via a TRUSTED_PROXIES proxy),
     * and never in local development: browsers remember it for the whole host (e.g. every site on
     * https://localhost) for a year.
     */
    public static function sendsHsts(?Request $request, string $env): bool
    {
        return $env !== 'local' && $request !== null && $request->isSecure();
    }
}

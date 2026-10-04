<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\CookieJar;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Translator;
use App\Core\View;
use App\Services\Auth\CurrentUser;

/**
 * Locale resolution, first match wins:
 *   1. ?lang=xx (also persisted)   2. "lang" cookie   3. user's saved preference
 *   4. Accept-Language header      5. APP_DEFAULT_LOCALE
 */
final class SetLocale implements Middleware
{
    public const COOKIE = 'lang';

    public function __construct(
        private readonly Translator $translator,
        private readonly CurrentUser $current,
        private readonly CookieJar $cookies,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $fromQuery = (string) $request->query('lang', '');
        $locale = match (true) {
            $this->translator->supports($fromQuery) => $fromQuery,
            $this->translator->supports((string) $request->cookie(self::COOKIE)) => (string) $request->cookie(self::COOKIE),
            $this->current->user() !== null && $this->translator->supports($this->current->user()->preferredLocale)
                => $this->current->user()->preferredLocale,
            default => $this->fromHeader($request) ?? $this->translator->locale(),
        };

        if ($fromQuery !== '' && $this->translator->supports($fromQuery)) {
            $this->cookies->queue(self::COOKIE, $fromQuery, time() + 365 * 86400, $request->cookieSecure());
        }

        $this->translator->setLocale($locale);
        $this->view->share('locale', $locale);
        $this->view->share('dir', dir_attr());

        return $next($request);
    }

    private function fromHeader(Request $request): ?string
    {
        $header = strtolower((string) $request->header('Accept-Language'));
        foreach (explode(',', $header) as $part) {
            $code = substr(trim($part), 0, 2);
            if ($this->translator->supports($code)) {
                return $code;
            }
        }

        return null;
    }
}

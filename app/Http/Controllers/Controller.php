<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Domain\User;
use App\Services\Auth\CurrentUser;

/** Thin controllers: read the request, call a service, pick a response. No SQL here. */
abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(app(View::class)->render($template, $data), $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function back(Request $request): Response
    {
        return Response::redirect(App::instance()->backUrl($request));
    }

    /** Flash a translated success message. @param array<string, string|int|float> $params */
    protected function success(string $key, array $params = []): void
    {
        app(Session::class)->flash('success', t($key, $params));
    }

    protected function user(): User
    {
        return app(CurrentUser::class)->require();
    }

    protected function optionalUser(): ?User
    {
        return app(CurrentUser::class)->user();
    }

    /** Filter values from the query string (trimmed strings only). @param list<string> $keys @return array<string, string> */
    protected function filters(Request $request, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $request->query($key);
            $out[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $out;
    }
}

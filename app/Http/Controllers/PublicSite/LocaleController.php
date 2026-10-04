<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\App;
use App\Core\CookieJar;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Translator;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Services\UserService;

/** GET /lang/{ar|en}: persist the choice (cookie + user preference) and go back to the same page. */
final class LocaleController extends Controller
{
    public function __construct(
        private readonly Translator $translator,
        private readonly CookieJar $cookies,
        private readonly UserService $users,
    ) {
    }

    public function switch(Request $request, string $code): Response
    {
        if (!$this->translator->supports($code)) {
            throw new HttpException(404);
        }
        $this->cookies->queue(SetLocale::COOKIE, $code, time() + 365 * 86400, $request->cookieSecure());
        $user = $this->optionalUser();
        if ($user !== null) {
            $this->users->setLocale($user, $code);
        }

        return Response::redirect(App::instance()->backUrl($request));
    }
}

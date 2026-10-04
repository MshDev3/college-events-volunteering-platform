<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Exceptions\HttpException;
use App\Core\Mail\LogMailer;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;

/**
 * Development mailbox (APP_ENV=local only, enforced by the `local` middleware):
 * lets you open password-reset emails without an SMTP server.
 */
final class DevMailController extends Controller
{
    public function __construct(private readonly LogMailer $mailer)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view('pages/dev/mail', ['messages' => $this->mailer->all()]);
    }

    public function show(Request $request, string $mailId): Response
    {
        $html = $this->mailer->html($mailId) ?? throw new HttpException(404);

        // Shown raw (it is our own template); sandboxed by CSP: no scripts can run.
        return Response::html($html)->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:");
    }
}

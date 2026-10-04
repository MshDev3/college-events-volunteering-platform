<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\ContactService;

final class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contact)
    {
    }

    public function show(Request $request): Response
    {
        // Contact details come from the shared `contactInfo` layout data.
        return $this->view('pages/public/contact');
    }

    public function submit(Request $request): Response
    {
        $this->contact->submit(
            $request->only(['name', 'email', 'subject', 'message', 'website']),
            $this->optionalUser()?->id,
            $request,
        );
        $this->success('contact.flash.sent');

        return $this->redirect('/contact');
    }
}

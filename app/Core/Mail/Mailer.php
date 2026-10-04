<?php

declare(strict_types=1);

namespace App\Core\Mail;

interface Mailer
{
    /** @throws \RuntimeException when the message could not be delivered */
    public function send(MailMessage $message): void;
}

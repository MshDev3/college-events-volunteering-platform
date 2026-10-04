<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Mail\Mailer;
use App\Core\Mail\MailMessage;
use App\Core\Translator;
use App\Core\View;

/** Renders localized email templates (views/emails/*) and hands them to the mail driver. */
final class EmailService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly View $view,
        private readonly Translator $translator,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Render in the recipient's language, then restore the request locale.
     * Returns false (and logs) on delivery failure; callers decide whether that is fatal.
     *
     * @param array<string, mixed> $data
     */
    public function send(string $to, string $locale, string $template, string $subjectKey, array $data = []): bool
    {
        $previous = $this->translator->locale();
        $this->translator->setLocale($locale);
        try {
            $subject = $this->translator->get($subjectKey, array_map('strval', array_filter($data, 'is_scalar')));
            $html = $this->view->render('emails/' . $template, $data + ['subject' => $subject]);
            $text = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/h\d|\/tr)>/i', "\n", $html) ?? ''), ENT_QUOTES, 'UTF-8'));
            $this->mailer->send(new MailMessage($to, $subject, $html, (string) preg_replace("/\n{3,}/", "\n\n", $text), $locale));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Email delivery failed', ['template' => $template, 'error' => $e->getMessage()]);

            return false;
        } finally {
            $this->translator->setLocale($previous);
        }
    }
}

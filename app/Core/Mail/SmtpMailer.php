<?php

declare(strict_types=1);

namespace App\Core\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/** Production mail driver using PHPMailer over SMTP. */
final class SmtpMailer implements Mailer
{
    /** @param array<string, mixed> $config config('mail') */
    public function __construct(private readonly array $config)
    {
    }

    public function send(MailMessage $message): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $this->config['host'];
        $mail->Port = (int) $this->config['port'];
        $mail->SMTPAuth = $this->config['username'] !== '';
        $mail->Username = (string) $this->config['username'];
        $mail->Password = (string) $this->config['password'];
        $mail->SMTPSecure = match ($this->config['encryption']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom((string) $this->config['from_address'], (string) $this->config['from_name']);
        $mail->addAddress($message->to);
        $mail->isHTML(true);
        $mail->Subject = $message->subject;
        $mail->Body = $message->html;
        $mail->AltBody = $message->text;

        try {
            $mail->send();
        } catch (\Throwable $e) {
            throw new \RuntimeException('SMTP delivery failed: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Development mail driver: writes each message to storage/mail as an .html file
 * (viewable at /_dev/mail when APP_ENV=local) plus a JSON sidecar with metadata.
 * Nothing leaves the machine.
 */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $directory)
    {
    }

    public function send(MailMessage $message): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Mail directory is not writable.');
        }
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        file_put_contents($this->directory . "/$id.html", $message->html);
        file_put_contents($this->directory . "/$id.json", json_encode([
            'to' => $message->to,
            'subject' => $message->subject,
            'locale' => $message->locale,
            'text' => $message->text,
            'sent_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array{id:string,to:string,subject:string,sent_at:string}> newest first */
    public function all(): array
    {
        $items = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);
            if (is_array($meta)) {
                $items[] = ['id' => basename($file, '.json')] + $meta;
            }
        }
        usort($items, static fn ($a, $b) => strcmp($b['id'], $a['id']));

        return $items;
    }

    public function html(string $id): ?string
    {
        if (preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $id) !== 1) {
            return null;
        }
        $file = $this->directory . "/$id.html";

        return is_file($file) ? (string) file_get_contents($file) : null;
    }
}

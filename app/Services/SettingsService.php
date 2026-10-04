<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Admin-editable site settings (contact details, address, About-page photo). */
final class SettingsService
{
    /** Keys an admin may edit, with their validation rules. */
    public const EDITABLE = [
        'contact_email' => 'required|email|max:190',
        'contact_email_2' => 'nullable|email|max:190',
        'contact_phone' => 'required|phone',
        'contact_phone_2' => 'nullable|phone',
        // Optional: an empty address hides the address line on the footer and the contact page.
        'address_ar' => 'nullable|string|max:255',
        'address_en' => 'nullable|string|max:255',
    ];

    /** Shown on the About page until an admin uploads a real photo. */
    public const ABOUT_IMAGE_DEFAULT = 'assets/img/defaults/about.jpg';

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->all()[$key] ?? $default;
    }

    public function localized(string $key): string
    {
        return $this->get($key . '_' . locale()) ?: $this->get($key . '_ar');
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->values === null) {
            $this->values = [];
            try {
                foreach ($this->db->fetchAll('SELECT `key`, value FROM settings') as $row) {
                    $this->values[(string) $row['key']] = (string) $row['value'];
                }
            } catch (\PDOException) {
                // Settings are cosmetic; never break a page (e.g. the error page) because of them.
            }
        }

        return $this->values;
    }

    /**
     * Public contact details in the current language, ready for the footer / contact page.
     * @return array{address:string, phones:list<string>, emails:list<string>}
     */
    public function contactInfo(): array
    {
        return [
            'address' => $this->localized('address'),
            'phones' => array_values(array_filter([$this->get('contact_phone'), $this->get('contact_phone_2')])),
            'emails' => array_values(array_filter([$this->get('contact_email'), $this->get('contact_email_2')])),
        ];
    }

    /** Web path of the About-page photo (uploaded one, else the bundled default). */
    public function aboutImage(): string
    {
        return $this->get('about_image') ?: self::ABOUT_IMAGE_DEFAULT;
    }

    /** Stores the About-page photo path (null = back to the default) and returns the previous path. */
    public function setAboutImage(?string $path): string
    {
        $previous = $this->get('about_image');
        $this->db->query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['about_image', (string) $path],
        );
        $this->values = null;

        return $previous;
    }

    /** @param array<string, string|null> $data */
    public function update(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!array_key_exists($key, self::EDITABLE)) {
                continue;
            }
            $this->db->query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$key, (string) ($value ?? '')],
            );
        }
        $this->values = null;
    }
}

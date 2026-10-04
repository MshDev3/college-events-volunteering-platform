<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Translator;

/**
 * In-app notifications. A notification stores a type + parameters, not rendered text,
 * so it is displayed in whatever language the reader currently uses.
 *
 * Bilingual parameters are passed as pairs (title_ar / title_en); rendering exposes
 * them as :title in the reader's language.
 */
final class NotificationService
{
    public const EVENT_REGISTERED = 'event_registered';
    public const EVENT_UNREGISTERED = 'event_unregistered';
    public const EVENT_CANCELLED = 'event_cancelled';
    public const EVENT_UPDATED = 'event_updated';
    public const VOLUNTEER_REGISTERED = 'volunteer_registered';
    public const VOLUNTEER_COMPLETED = 'volunteer_completed';
    public const VOLUNTEER_CANCELLED = 'volunteer_cancelled';
    public const OPPORTUNITY_CANCELLED = 'opportunity_cancelled';
    public const OPPORTUNITY_UPDATED = 'opportunity_updated';
    public const RESERVATION_SUBMITTED = 'reservation_submitted';
    public const RESERVATION_APPROVED = 'reservation_approved';
    public const RESERVATION_REJECTED = 'reservation_rejected';
    public const RESERVATION_CANCELLED = 'reservation_cancelled';
    public const FEEDBACK_REPLIED = 'feedback_replied';
    public const FEEDBACK_STATUS = 'feedback_status';
    public const PASSWORD_CHANGED = 'password_changed';
    public const WELCOME = 'welcome';

    public function __construct(private readonly Database $db, private readonly Translator $translator)
    {
    }

    /** @param array<string, scalar|null> $params */
    public function notify(int $userId, string $type, array $params = [], ?string $link = null): void
    {
        $this->db->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'data' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'link' => $link,
        ]);
    }

    /**
     * Notify many users at once (e.g. everyone registered to a cancelled event).
     * @param list<int> $userIds
     * @param array<string, scalar|null> $params
     */
    public function notifyMany(array $userIds, string $type, array $params = [], ?string $link = null): void
    {
        foreach (array_unique($userIds) as $userId) {
            $this->notify($userId, $type, $params, $link);
        }
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** @return array{items:list<array<string,mixed>>, total:int} */
    public function forUser(int $userId, int $limit, int $offset = 0, bool $unreadOnly = false): array
    {
        $cond = $unreadOnly ? ' AND read_at IS NULL' : '';
        $total = (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ?$cond", [$userId]);
        $rows = $this->db->fetchAll(
            "SELECT id, type, data, link, read_at, created_at FROM notifications
             WHERE user_id = ?$cond ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            [$userId, $limit, $offset],
        );

        return ['items' => array_map(fn (array $r): array => $this->present($r), $rows), 'total' => $total];
    }

    public function markRead(int $userId, int $id): ?string
    {
        $link = $this->db->value('SELECT link FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
        $this->db->query('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?', [$id, $userId]);

        return $link === null ? null : (string) $link;
    }

    public function markAllRead(int $userId): void
    {
        $this->db->query('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $data = json_decode((string) $row['data'], true);
        $params = $this->localizeParams(is_array($data) ? $data : []);
        $type = (string) $row['type'];

        $body = $this->translator->get("notifications.types.$type.body", $params);
        // Optional free-text explanations are separate sentences, added only when present
        // (no dangling "Reason:" or trailing space when an admin leaves them empty).
        foreach (['reason', 'note'] as $extra) {
            $text = trim((string) ($params[$extra] ?? ''));
            if ($text !== '') {
                $body .= ' ' . $this->translator->get("notifications.$extra", [$extra => $text]);
            }
        }

        return [
            'id' => (int) $row['id'],
            'type' => $type,
            'title' => $this->translator->get("notifications.types.$type.title", $params),
            'body' => $body,
            'icon' => $this->translator->get("notifications.types.$type.icon"),
            'link' => $row['link'],
            'is_read' => $row['read_at'] !== null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @param array<string, mixed> $params @return array<string, string> */
    private function localizeParams(array $params): array
    {
        $locale = $this->translator->locale();
        $out = [];
        foreach ($params as $key => $value) {
            $out[$key] = is_scalar($value) ? (string) $value : '';
            if (preg_match('/^(.*)_(ar|en)$/', (string) $key, $m) === 1) {
                $base = $m[1];
                $preferred = $params[$base . '_' . $locale] ?? null;
                $out[$base] = is_scalar($preferred) && $preferred !== '' ? (string) $preferred : (string) $value;
            }
        }
        // Status values are rendered through their translation keys.
        if (isset($out['status_key'])) {
            $out['status'] = $this->translator->get($out['status_key']);
        }

        return $out;
    }
}

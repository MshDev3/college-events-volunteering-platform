<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Domain\ContactStatus;

final class ContactRepository
{
    use Concerns\FilterClauses;

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('contact_messages', $data);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM contact_messages WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, ContactStatus $status): void
    {
        $this->db->update('contact_messages', ['status' => $status->value], ['id' => $id]);
    }

    /**
     * @param array{status?:string, q?:string, from?:string, to?:string} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        $where = ['1=1'];
        $params = [];
        $status = ContactStatus::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status->value;
        }
        $this->addSearch($where, $params, $filters['q'] ?? '', ['name', 'email', 'subject', 'message']);
        $this->addDateRange($where, $params, $filters, 'created_at', 'created_at');
        $w = implode(' AND ', $where);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM contact_messages WHERE $w", $params);
        $items = $this->db->fetchAll(
            "SELECT id, name, email, subject, status, created_at FROM contact_messages WHERE $w
             ORDER BY FIELD(status, 'UNREAD') DESC, created_at DESC, id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }

    public function countUnread(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM contact_messages WHERE status = 'UNREAD'");
    }
}

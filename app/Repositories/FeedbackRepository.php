<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class FeedbackRepository
{
    use Concerns\FilterClauses;

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('feedback', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('feedback', $data, ['id' => $id]);
    }

    /** @param array<string, mixed> $data */
    public function addAttachment(array $data): int
    {
        return $this->db->insert('feedback_attachments', $data);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT f.*, c.name_ar AS category_name_ar, c.name_en AS category_name_en,
                    COALESCE(u.full_name, f.submitter_name) AS author_name,
                    COALESCE(u.email, f.submitter_email) AS author_email, u.student_id,
                    rb.full_name AS replied_by_name
             FROM feedback f
             JOIN feedback_categories c ON c.id = f.category_id
             LEFT JOIN users u ON u.id = f.user_id
             LEFT JOIN users rb ON rb.id = f.replied_by
             WHERE f.id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function attachments(int $feedbackId): array
    {
        return $this->db->fetchAll('SELECT * FROM feedback_attachments WHERE feedback_id = ? ORDER BY id', [$feedbackId]);
    }

    /** @return array<string, mixed>|null */
    public function attachment(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT a.*, f.user_id FROM feedback_attachments a JOIN feedback f ON f.id = a.feedback_id WHERE a.id = ?',
            [$id],
        );
    }

    /**
     * @param array{type?:string, status?:string, category?:int|string|null, from?:string, to?:string, q?:string, archived?:string, user?:int} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        $where = ['1=1'];
        $params = [];
        if (isset($filters['user'])) {
            $where[] = 'f.user_id = ?';
            $params[] = (int) $filters['user'];
        } else {
            $where[] = ($filters['archived'] ?? '') === '1' ? 'f.archived_at IS NOT NULL' : 'f.archived_at IS NULL';
        }
        if (in_array($filters['type'] ?? '', ['SUGGESTION', 'COMPLAINT'], true)) {
            $where[] = 'f.type = ?';
            $params[] = $filters['type'];
        }
        if (in_array($filters['status'] ?? '', ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'], true)) {
            $where[] = 'f.status = ?';
            $params[] = $filters['status'];
        }
        $category = filter_var($filters['category'] ?? null, FILTER_VALIDATE_INT);
        if ($category !== false && $category !== null) {
            $where[] = 'f.category_id = ?';
            $params[] = $category;
        }
        $this->addDateRange($where, $params, $filters, 'f.created_at', 'f.created_at');
        $this->addSearch($where, $params, $filters['q'] ?? '', ['f.subject', 'f.message', 'u.full_name', 'u.email']);
        $w = implode(' AND ', $where);
        $from = 'FROM feedback f JOIN feedback_categories c ON c.id = f.category_id LEFT JOIN users u ON u.id = f.user_id';
        $total = (int) $this->db->value("SELECT COUNT(*) $from WHERE $w", $params);
        $items = $this->db->fetchAll(
            "SELECT f.id, f.type, f.subject, f.status, f.created_at, f.updated_at, f.archived_at, f.admin_reply,
                    c.name_ar AS category_name_ar, c.name_en AS category_name_en,
                    COALESCE(u.full_name, f.submitter_name) AS author_name, COALESCE(u.email, f.submitter_email) AS author_email
             $from WHERE $w ORDER BY f.created_at DESC, f.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }

    public function countOpenComplaints(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM feedback WHERE type = 'COMPLAINT' AND status IN ('OPEN','IN_PROGRESS') AND archived_at IS NULL");
    }

    public function countOpen(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM feedback WHERE status = 'OPEN' AND archived_at IS NULL");
    }
}

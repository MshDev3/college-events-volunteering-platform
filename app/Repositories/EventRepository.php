<?php

declare(strict_types=1);

namespace App\Repositories;

final class EventRepository extends ActivityRepository
{
    protected function table(): string
    {
        return 'events';
    }

    protected function registrationTable(): string
    {
        return 'event_registrations';
    }

    protected function foreignKey(): string
    {
        return 'event_id';
    }

    protected function lookupTable(): string
    {
        return 'event_types';
    }

    protected function lookupColumn(): string
    {
        return 'event_type_id';
    }

    public function seatStatuses(): array
    {
        return ['REGISTERED', 'ATTENDED'];
    }

    /**
     * A student's event registrations filtered by the event's derived status (for the tabs).
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function forStudent(int $userId, ?\App\Domain\ActivityStatus $status, int $limit, int $offset): array
    {
        $where = 'r.user_id = ?';
        $params = [$userId];
        if ($status === \App\Domain\ActivityStatus::CANCELLED) {
            // Cancelled tab: the event was cancelled OR the student cancelled their seat.
            $where .= " AND (a.cancelled_at IS NOT NULL OR r.status = 'CANCELLED')";
        } elseif ($status !== null) {
            $where .= " AND r.status <> 'CANCELLED' AND " . $status->sqlCondition('a');
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM event_registrations r JOIN events a ON a.id = r.event_id WHERE $where", $params);
        $items = $this->db->fetchAll(
            'SELECT a.id, a.title_ar, a.title_en, a.location_ar, a.location_en, a.start_datetime, a.end_datetime,
                    a.capacity, a.cancelled_at, a.image_path, ' . \App\Domain\ActivityStatus::sql('a') . " AS status,
                    r.status AS registration_status, r.registered_at, r.id AS registration_id
             FROM event_registrations r JOIN events a ON a.id = r.event_id
             WHERE $where ORDER BY a.start_datetime " . ($status === \App\Domain\ActivityStatus::UPCOMING ? 'ASC' : 'DESC') . ' LIMIT ? OFFSET ?',
            [...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }

    /** Counts per tab for a student. @return array<string, int> */
    public function studentTabCounts(int $userId): array
    {
        $row = $this->db->fetch(
            "SELECT
               SUM(r.status <> 'CANCELLED' AND a.cancelled_at IS NULL AND NOW() < a.start_datetime) AS upcoming,
               SUM(r.status <> 'CANCELLED' AND a.cancelled_at IS NULL AND NOW() BETWEEN a.start_datetime AND a.end_datetime) AS ongoing,
               SUM(r.status <> 'CANCELLED' AND a.cancelled_at IS NULL AND NOW() > a.end_datetime) AS completed,
               SUM(a.cancelled_at IS NOT NULL OR r.status = 'CANCELLED') AS cancelled
             FROM event_registrations r JOIN events a ON a.id = r.event_id WHERE r.user_id = ?",
            [$userId],
        ) ?? [];

        return array_map(static fn ($v): int => (int) $v, $row);
    }

    public function totalActiveRegistrations(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM event_registrations WHERE status <> 'CANCELLED'");
    }

    /**
     * All registrations across events (admin cross-search).
     * @param array{q?:string, event?:int|string|null, status?:string} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function searchRegistrations(array $filters, int $limit, int $offset): array
    {
        $where = ['1=1'];
        $params = [];
        $this->addSearch($where, $params, $filters['q'] ?? '', ['u.full_name', 'u.email', 'u.student_id', 'a.title_ar', 'a.title_en']);
        if (in_array($filters['status'] ?? '', ['REGISTERED', 'ATTENDED', 'CANCELLED'], true)) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM event_registrations r JOIN users u ON u.id = r.user_id JOIN events a ON a.id = r.event_id WHERE $w", $params);
        $items = $this->db->fetchAll(
            "SELECT r.id, r.status, r.registered_at, u.full_name, u.email, u.student_id,
                    a.id AS event_id, a.title_ar, a.title_en, a.start_datetime, a.end_datetime
             FROM event_registrations r JOIN users u ON u.id = r.user_id JOIN events a ON a.id = r.event_id
             WHERE $w ORDER BY r.registered_at DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }
}

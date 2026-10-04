<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Domain\ReservationStatus;

final class ReservationRepository
{
    use Concerns\FilterClauses;

    public function __construct(private readonly Database $db)
    {
    }

    // ---- Facilities ---------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function facilities(bool $activeOnly = true): array
    {
        return $this->db->fetchAll('SELECT * FROM facilities' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY id');
    }

    /** @return array<string, mixed>|null */
    public function facility(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM facilities WHERE id = ?', [$id]);
    }

    /** Lock the facility row so concurrent approvals for it are serialized. */
    public function lockFacility(int $id): void
    {
        $this->db->query('SELECT id FROM facilities WHERE id = ? FOR UPDATE', [$id]);
    }

    // ---- Reservations -------------------------------------------------------

    /** Overlap test: a.start < b.end AND a.end > b.start. Only APPROVED bookings block time. */
    public function hasApprovedOverlap(int $facilityId, string $start, string $end, ?int $exceptId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM facility_reservations
                WHERE facility_id = ? AND status = 'APPROVED' AND start_datetime < ? AND end_datetime > ?";
        $params = [$facilityId, $end, $start];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) $this->db->value($sql, $params) > 0;
    }

    public function userHasOverlap(int $userId, int $facilityId, string $start, string $end): bool
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM facility_reservations
             WHERE user_id = ? AND facility_id = ? AND status IN ('PENDING','APPROVED') AND start_datetime < ? AND end_datetime > ?",
            [$userId, $facilityId, $end, $start],
        ) > 0;
    }

    /** Pending requests that overlap an approved booking (shown as warnings to admins). */
    public function pendingConflictsWith(int $reservationId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM facility_reservations p JOIN facility_reservations r
               ON r.facility_id = p.facility_id AND r.id <> p.id AND r.status = 'APPROVED'
              AND r.start_datetime < p.end_datetime AND r.end_datetime > p.start_datetime
             WHERE p.id = ?",
            [$reservationId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch($this->selectSql() . ' WHERE r.id = ?', [$id]);
    }

    /**
     * Lock ONE reservation row and return its current state (a locking read sees the latest committed
     * values, not the transaction's snapshot). No joins: locking joined user/facility rows as well
     * deadlocked against concurrent status updates, whose foreign-key checks lock those rows.
     * @return array{id:int, facility_id:int, user_id:int, status:string, start_datetime:string, end_datetime:string}|null
     */
    public function lockForDecision(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, facility_id, user_id, status, start_datetime, end_datetime FROM facility_reservations WHERE id = ? FOR UPDATE',
            [$id],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('facility_reservations', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('facility_reservations', $data, ['id' => $id]);
    }

    /**
     * Status change that only applies if the row is STILL in one of $from at the moment of the UPDATE
     * (and an APPROVED booking has not started yet). Returns false when a concurrent action got there
     * first, so a rejection or cancellation can never overwrite an approval made in the meantime.
     * @param list<string> $from @param array<string, mixed> $data column => value (column names from code only)
     */
    public function transition(int $id, array $from, array $data): bool
    {
        $in = implode(', ', array_fill(0, count($from), '?'));
        $set = implode(', ', array_map(static fn (string $column): string => "`$column` = ?", array_keys($data)));

        return $this->db->query(
            "UPDATE facility_reservations SET $set
             WHERE id = ? AND status IN ($in) AND (status <> 'APPROVED' OR start_datetime > NOW())",
            [...array_values($data), $id, ...$from],
        )->rowCount() === 1;
    }

    /**
     * @param array{status?:string, facility?:int|string|null, from?:string, to?:string, q?:string, user?:int} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        $where = ['1=1'];
        $params = [];
        if (isset($filters['user'])) {
            $where[] = 'r.user_id = ?';
            $params[] = (int) $filters['user'];
        }
        $status = ReservationStatus::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $where[] = '(' . $status->sqlCondition('r') . ')';
        }
        $facility = filter_var($filters['facility'] ?? null, FILTER_VALIDATE_INT);
        if ($facility !== false && $facility !== null) {
            $where[] = 'r.facility_id = ?';
            $params[] = $facility;
        }
        $this->addDateRange($where, $params, $filters, 'r.end_datetime', 'r.start_datetime');
        $this->addSearch($where, $params, $filters['q'] ?? '', ['u.full_name', 'u.email', 'u.student_id', 'r.purpose']);
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM facility_reservations r JOIN users u ON u.id = r.user_id WHERE $w", $params);
        $items = $this->db->fetchAll(
            $this->selectSql() . " WHERE $w ORDER BY FIELD(r.status, 'PENDING') DESC, r.start_datetime DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }

    /** Approved bookings of a facility from now on (public availability view). @return list<array<string, mixed>> */
    public function upcomingApproved(int $facilityId, int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT start_datetime, end_datetime FROM facility_reservations
             WHERE facility_id = ? AND status = 'APPROVED' AND end_datetime >= NOW()
             ORDER BY start_datetime LIMIT ?",
            [$facilityId, $limit],
        );
    }

    public function countPending(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM facility_reservations WHERE status = 'PENDING'");
    }

    private function selectSql(): string
    {
        return 'SELECT r.*, ' . ReservationStatus::sql('r') . ' AS display_status,
                f.name_ar AS facility_name_ar, f.name_en AS facility_name_en, f.code AS facility_code,
                u.full_name, u.email, u.student_id, d.full_name AS decided_by_name
                FROM facility_reservations r
                JOIN facilities f ON f.id = r.facility_id
                JOIN users u ON u.id = r.user_id
                LEFT JOIN users d ON d.id = r.decided_by';
    }
}

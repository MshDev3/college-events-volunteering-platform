<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Domain\ActivityStatus;

/**
 * Shared query logic for events and volunteer opportunities (same shape: bilingual title,
 * time window, capacity, cancellation, registrations). Subclasses provide table metadata.
 *
 * Every row returned carries:
 *   status            derived ActivityStatus value (see ActivityStatus::sql)
 *   registered_count  registrations that occupy a seat
 *   type_name_ar/en   lookup label (event type / volunteer category)
 *   my_status         the given user's registration status, if any
 */
abstract class ActivityRepository
{
    use Concerns\FilterClauses;

    public function __construct(protected readonly Database $db)
    {
    }

    abstract protected function table(): string;

    abstract protected function registrationTable(): string;

    /** Foreign-key column in the registration table. */
    abstract protected function foreignKey(): string;

    abstract protected function lookupTable(): string;

    /** Column on the activity table pointing to the lookup. */
    abstract protected function lookupColumn(): string;

    /** Registration statuses that occupy a seat. @return list<string> */
    abstract public function seatStatuses(): array;

    /** Extra lookup columns to select (e.g. the category icon). */
    protected function lookupExtraColumns(): string
    {
        return '';
    }

    /**
     * @param array{q?:string, lookup?:int|string|null, status?:string, from?:string, to?:string, location?:string} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function paginate(array $filters, int $limit, int $offset, ?int $userId = null, bool $adminOrder = false): array
    {
        [$where, $params] = $this->filters($filters);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM `{$this->table()}` a WHERE $where", $params);

        $order = $adminOrder
            ? 'a.start_datetime DESC, a.id DESC'
            : "FIELD(status, 'ONGOING', 'UPCOMING', 'COMPLETED', 'CANCELLED'),
               CASE WHEN a.end_datetime >= NOW() THEN a.start_datetime END ASC,
               a.start_datetime DESC";

        $items = $this->db->fetchAll(
            $this->selectSql($userId) . " WHERE $where ORDER BY $order LIMIT ? OFFSET ?",
            [...$this->userParams($userId), ...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, ?int $userId = null): ?array
    {
        return $this->db->fetch($this->selectSql($userId) . ' WHERE a.id = ?', [...$this->userParams($userId), $id]);
    }

    /** Next activities that still accept registrations. @return list<array<string, mixed>> */
    public function upcoming(int $limit, ?int $userId = null): array
    {
        return $this->db->fetchAll(
            $this->selectSql($userId) . ' WHERE ' . ActivityStatus::UPCOMING->sqlCondition('a') . ' ORDER BY a.start_datetime ASC LIMIT ?',
            [...$this->userParams($userId), $limit],
        );
    }

    /** Lock the activity row for the duration of the transaction (serializes seat allocation). @return array<string, mixed>|null */
    public function lockForUpdate(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT a.*, ' . ActivityStatus::sql('a') . " AS status FROM `{$this->table()}` a WHERE a.id = ? FOR UPDATE",
            [$id],
        );
    }

    public function seatsTaken(int $id): int
    {
        $in = $this->statusList();

        return (int) $this->db->value(
            "SELECT COUNT(*) FROM `{$this->registrationTable()}` WHERE `{$this->foreignKey()}` = ? AND status IN ($in)",
            [$id],
        );
    }

    /** @return array<string, mixed>|null */
    public function registration(int $activityId, int $userId, bool $lock = false): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM `{$this->registrationTable()}` WHERE `{$this->foreignKey()}` = ? AND user_id = ?" . ($lock ? ' FOR UPDATE' : ''),
            [$activityId, $userId],
        );
    }

    /** A registration row together with its activity's schedule/cancellation. @return array<string, mixed>|null */
    public function registrationWithActivity(int $registrationId): ?array
    {
        return $this->db->fetch(
            "SELECT r.*, a.start_datetime, a.end_datetime, a.cancelled_at
             FROM `{$this->registrationTable()}` r JOIN `{$this->table()}` a ON a.id = r.`{$this->foreignKey()}`
             WHERE r.id = ?",
            [$registrationId],
        );
    }

    /** User ids with a seat (for notifications on cancel/update). @return list<int> */
    public function registeredUserIds(int $activityId): array
    {
        $in = $this->statusList();

        return array_map('intval', array_column($this->db->fetchAll(
            "SELECT user_id FROM `{$this->registrationTable()}` WHERE `{$this->foreignKey()}` = ? AND status IN ($in)",
            [$activityId],
        ), 'user_id'));
    }

    /**
     * Registrations of one activity (admin view).
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function registrations(int $activityId, int $limit, int $offset, string $q = ''): array
    {
        $clauses = ["r.`{$this->foreignKey()}` = ?"];
        $params = [$activityId];
        $this->addSearch($clauses, $params, $q, ['u.full_name', 'u.email', 'u.student_id']);
        $where = implode(' AND ', $clauses);
        $total = (int) $this->db->value(
            "SELECT COUNT(*) FROM `{$this->registrationTable()}` r JOIN users u ON u.id = r.user_id WHERE $where",
            $params,
        );
        $items = $this->db->fetchAll(
            "SELECT r.*, u.full_name, u.email, u.student_id, u.phone
             FROM `{$this->registrationTable()}` r JOIN users u ON u.id = r.user_id
             WHERE $where ORDER BY FIELD(r.status, 'REGISTERED', 'ATTENDED', 'COMPLETED', 'CANCELLED'), u.full_name
             LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }

    public function countByStatus(ActivityStatus $status): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM `{$this->table()}` a WHERE " . $status->sqlCondition('a'));
    }

    public function countAll(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM `{$this->table()}`");
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->table(), $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update($this->table(), $data, ['id' => $id]);
    }

    /** Cancel only if not cancelled yet: false when a concurrent request already did (no duplicate notifications). */
    public function markCancelled(int $id, ?string $reason): bool
    {
        return $this->db->query(
            "UPDATE `{$this->table()}` SET cancelled_at = NOW(), cancel_reason = ? WHERE id = ? AND cancelled_at IS NULL",
            [$reason, $id],
        )->rowCount() === 1;
    }

    /**
     * Registration status change that applies only while the row is STILL in one of $from.
     * @param list<string> $from @param array<string, mixed> $data column => value (column names from code only)
     */
    public function transitionRegistration(int $registrationId, array $from, array $data): bool
    {
        $in = implode(', ', array_fill(0, count($from), '?'));
        $set = implode(', ', array_map(static fn (string $column): string => "`$column` = ?", array_keys($data)));

        return $this->db->query(
            "UPDATE `{$this->registrationTable()}` SET $set WHERE id = ? AND status IN ($in)",
            [...array_values($data), $registrationId, ...$from],
        )->rowCount() === 1;
    }

    public function delete(int $id): void
    {
        $this->db->query("DELETE FROM `{$this->table()}` WHERE id = ?", [$id]);
    }

    protected function selectSql(?int $userId): string
    {
        $in = $this->statusList();
        $my = $userId !== null
            ? ", (SELECT m.status FROM `{$this->registrationTable()}` m WHERE m.`{$this->foreignKey()}` = a.id AND m.user_id = ?) AS my_status"
            : ', NULL AS my_status';

        return 'SELECT a.*, ' . ActivityStatus::sql('a') . " AS status,
                (SELECT COUNT(*) FROM `{$this->registrationTable()}` r WHERE r.`{$this->foreignKey()}` = a.id AND r.status IN ($in)) AS registered_count,
                l.code AS type_code, l.name_ar AS type_name_ar, l.name_en AS type_name_en{$this->lookupExtraColumns()}
                $my
                FROM `{$this->table()}` a
                JOIN `{$this->lookupTable()}` l ON l.id = a.`{$this->lookupColumn()}`";
    }

    /** @return list<int> */
    private function userParams(?int $userId): array
    {
        return $userId !== null ? [$userId] : [];
    }

    private function statusList(): string
    {
        return implode(',', array_map(static fn (string $s): string => "'$s'", $this->seatStatuses()));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private function filters(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        $this->addSearch($where, $params, $filters['q'] ?? '', ['a.title_ar', 'a.title_en', 'a.description_ar', 'a.description_en']);
        $this->addSearch($where, $params, $filters['location'] ?? '', ['a.location_ar', 'a.location_en']);
        $lookup = filter_var($filters['lookup'] ?? null, FILTER_VALIDATE_INT);
        if ($lookup !== false && $lookup !== null) {
            $where[] = "a.`{$this->lookupColumn()}` = ?";
            $params[] = $lookup;
        }
        $status = ActivityStatus::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $where[] = '(' . $status->sqlCondition('a') . ')';
        }
        // Activities overlapping the chosen days.
        $this->addDateRange($where, $params, $filters, 'a.end_datetime', 'a.start_datetime');

        return [implode(' AND ', $where), $params];
    }
}

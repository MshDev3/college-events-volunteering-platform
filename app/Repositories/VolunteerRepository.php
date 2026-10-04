<?php

declare(strict_types=1);

namespace App\Repositories;

final class VolunteerRepository extends ActivityRepository
{
    protected function table(): string
    {
        return 'volunteer_opportunities';
    }

    protected function registrationTable(): string
    {
        return 'volunteer_registrations';
    }

    protected function foreignKey(): string
    {
        return 'opportunity_id';
    }

    protected function lookupTable(): string
    {
        return 'volunteer_categories';
    }

    protected function lookupColumn(): string
    {
        return 'category_id';
    }

    protected function lookupExtraColumns(): string
    {
        return ', l.icon AS type_icon';
    }

    public function seatStatuses(): array
    {
        return ['REGISTERED', 'ATTENDED', 'COMPLETED'];
    }

    /** Hours are counted only for registrations an admin marked COMPLETED. */
    public function hoursFor(int $userId): float
    {
        return (float) $this->db->value(
            "SELECT COALESCE(SUM(hours_awarded), 0) FROM volunteer_registrations WHERE user_id = ? AND status = 'COMPLETED'",
            [$userId],
        );
    }

    public function totalAwardedHours(): float
    {
        return (float) $this->db->value("SELECT COALESCE(SUM(hours_awarded), 0) FROM volunteer_registrations WHERE status = 'COMPLETED'");
    }

    /** @return array{items:list<array<string,mixed>>, total:int} */
    public function forStudent(int $userId, ?string $registrationStatus, int $limit, int $offset): array
    {
        $where = 'r.user_id = ?';
        $params = [$userId];
        if ($registrationStatus !== null) {
            $where .= ' AND r.status = ?';
            $params[] = $registrationStatus;
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM volunteer_registrations r WHERE $where", $params);
        $items = $this->db->fetchAll(
            'SELECT a.id, a.title_ar, a.title_en, a.location_ar, a.location_en, a.start_datetime, a.end_datetime,
                    a.volunteer_hours, a.cancelled_at, ' . \App\Domain\ActivityStatus::sql('a') . " AS status,
                    r.id AS registration_id, r.status AS registration_status, r.hours_awarded, r.completed_at, r.created_at
             FROM volunteer_registrations r JOIN volunteer_opportunities a ON a.id = r.opportunity_id
             WHERE $where ORDER BY a.start_datetime DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findRegistration(int $registrationId): ?array
    {
        return $this->db->fetch(
            'SELECT r.*, a.title_ar, a.title_en, a.volunteer_hours, a.start_datetime, a.end_datetime, a.cancelled_at
             FROM volunteer_registrations r JOIN volunteer_opportunities a ON a.id = r.opportunity_id WHERE r.id = ?',
            [$registrationId],
        );
    }
}

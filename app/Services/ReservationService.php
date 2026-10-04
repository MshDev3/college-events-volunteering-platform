<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Paginator;
use App\Core\RateLimiter;
use App\Core\Validator;
use App\Repositories\ReservationRepository;

/**
 * College facility reservations.
 *
 * PENDING → APPROVED | REJECTED | CANCELLED;  APPROVED → CANCELLED;  APPROVED + past = COMPLETED (derived).
 * Only APPROVED bookings block a time slot. Conflicts are checked when a student requests
 * and re-checked, under a facility row lock, when an admin approves.
 */
final class ReservationService
{
    public const MAX_DURATION_HOURS = 12;
    public const MAX_DAYS_AHEAD = 180;
    /** New booking requests per student per hour. */
    public const MAX_REQUESTS_PER_HOUR = 10;

    public function __construct(
        private readonly Database $db,
        private readonly ReservationRepository $repo,
        private readonly Validator $validator,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly UploadService $uploads,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function facilities(bool $activeOnly = true): array
    {
        return $this->repo->facilities($activeOnly);
    }

    /** @return array<string, mixed> */
    public function facility(int $id, bool $activeOnly = true): array
    {
        $facility = $this->repo->facility($id);
        if ($facility === null || ($activeOnly && !(bool) $facility['is_active'])) {
            throw new HttpException(404);
        }

        return $facility;
    }

    /** @return list<array<string, mixed>> */
    public function upcomingBookings(int $facilityId): array
    {
        return $this->repo->upcomingApproved($facilityId);
    }

    /** @param array<string, mixed> $input */
    public function request(int $userId, array $input): int
    {
        $ids = implode(',', array_column($this->repo->facilities(), 'id'));
        $data = $this->validator->validate($input, [
            'facility_id' => 'required|integer|in:' . $ids,
            'date' => 'required|date',
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'purpose' => 'required|string|min:5|max:200',
            'expected_attendees' => 'nullable|integer|min:1|max:10000',
            'notes' => 'nullable|string|max:1000',
        ]);

        $start = $data['date'] . ' ' . $data['start_time'] . ':00';
        $end = $data['date'] . ' ' . $data['end_time'] . ':00';
        $startTs = strtotime($start);
        $endTs = strtotime($end);

        if ($endTs <= $startTs) {
            throw \App\Core\Exceptions\ValidationException::withMessage('end_time', t('reservations.errors.end_before_start'));
        }
        if ($startTs <= time()) {
            throw \App\Core\Exceptions\ValidationException::withMessage('date', t('reservations.errors.in_past'));
        }
        if ($startTs > time() + self::MAX_DAYS_AHEAD * 86400) {
            throw \App\Core\Exceptions\ValidationException::withMessage('date', t('reservations.errors.too_far', ['days' => self::MAX_DAYS_AHEAD]));
        }
        if ($endTs - $startTs > self::MAX_DURATION_HOURS * 3600) {
            throw \App\Core\Exceptions\ValidationException::withMessage('end_time', t('reservations.errors.too_long', ['hours' => self::MAX_DURATION_HOURS]));
        }

        $facility = $this->facility((int) $data['facility_id']);
        if ($facility['capacity'] !== null && $data['expected_attendees'] !== null && (int) $data['expected_attendees'] > (int) $facility['capacity']) {
            throw \App\Core\Exceptions\ValidationException::withMessage('expected_attendees', t('reservations.errors.over_capacity', ['capacity' => (int) $facility['capacity']]));
        }
        if ($this->repo->hasApprovedOverlap((int) $facility['id'], $start, $end)) {
            throw new BusinessRuleException('reservations.errors.conflict');
        }
        if ($this->repo->userHasOverlap($userId, (int) $facility['id'], $start, $end)) {
            throw new BusinessRuleException('reservations.errors.duplicate');
        }

        if (!$this->limiter->attempt('reservation:' . $userId, self::MAX_REQUESTS_PER_HOUR, 3600)) {
            throw \App\Core\Exceptions\ValidationException::withMessage('purpose', t('reservations.errors.too_many'));
        }

        $id = $this->repo->create([
            'facility_id' => (int) $facility['id'],
            'user_id' => $userId,
            'purpose' => $data['purpose'],
            'notes' => $data['notes'],
            'start_datetime' => $start,
            'end_datetime' => $end,
            'expected_attendees' => $data['expected_attendees'],
        ]);
        $this->notifications->notify($userId, NotificationService::RESERVATION_SUBMITTED, $this->params($facility), '/student/reservations');

        return $id;
    }

    public function approve(int $adminId, int $id, ?string $note): void
    {
        $note = $this->validator->validate(['admin_note' => $note], ['admin_note' => 'nullable|string|max:1000'])['admin_note'];
        // Read OUTSIDE the transaction: under REPEATABLE READ the first plain read fixes the snapshot, and it
        // must come after the facility lock, or the overlap check below could miss an approval committed
        // while we waited for that lock.
        $existing = $this->repo->find($id) ?? throw new HttpException(404);
        $this->db->transaction(function () use ($adminId, $id, $note, $existing): void {
            $this->repo->lockFacility((int) $existing['facility_id']);
            $reservation = $this->repo->lockForDecision($id) ?? throw new HttpException(404);

            if ($reservation['status'] !== 'PENDING') {
                throw new BusinessRuleException('reservations.errors.not_pending');
            }
            if (strtotime((string) $reservation['start_datetime']) <= time()) {
                throw new BusinessRuleException('reservations.errors.already_started');
            }
            if ($this->repo->hasApprovedOverlap((int) $reservation['facility_id'], (string) $reservation['start_datetime'], (string) $reservation['end_datetime'], $id)) {
                throw new BusinessRuleException('reservations.errors.conflict_on_approve');
            }
            if (!$this->repo->transition($id, ['PENDING'], $this->decision('APPROVED', $adminId, $note))) {
                throw new BusinessRuleException('reservations.errors.not_pending');
            }
        });

        $this->audit->log($adminId, 'reservation.approved', 'reservation', $id);
        $this->notifications->notify((int) $existing['user_id'], NotificationService::RESERVATION_APPROVED, $this->params($existing, 'facility_name'), '/student/reservations');
    }

    public function reject(int $adminId, int $id, string $note): void
    {
        $data = $this->validator->validate(['admin_note' => $note], ['admin_note' => 'required|string|min:3|max:1000']);
        $reservation = $this->repo->find($id) ?? throw new HttpException(404);
        // Conditional UPDATE: fails if the request was approved/cancelled after we read it.
        if ($reservation['status'] !== 'PENDING'
            || !$this->decide($reservation, ['PENDING'], $this->decision('REJECTED', $adminId, (string) $data['admin_note']))) {
            throw new BusinessRuleException('reservations.errors.not_pending');
        }
        $this->audit->log($adminId, 'reservation.rejected', 'reservation', $id);
        $this->notifications->notify(
            (int) $reservation['user_id'],
            NotificationService::RESERVATION_REJECTED,
            $this->params($reservation, 'facility_name') + ['note' => (string) $data['admin_note']],
            '/student/reservations',
        );
    }

    /** Admin cancels any pending/future approved booking; a student only their own. */
    public function cancel(int $actorId, int $id, bool $asAdmin, ?string $note = null): void
    {
        $note = $this->validator->validate(['admin_note' => $note], ['admin_note' => 'nullable|string|max:1000'])['admin_note'];
        $reservation = $this->repo->find($id) ?? throw new HttpException(404);
        if (!$asAdmin && (int) $reservation['user_id'] !== $actorId) {
            throw new HttpException(404); // do not reveal other users' reservations
        }
        if (!self::canCancel($reservation)) {
            throw new BusinessRuleException('reservations.errors.cannot_cancel');
        }

        $update = ['status' => 'CANCELLED'];
        if ($asAdmin) {
            $update += ['decided_by' => $actorId, 'decided_at' => date('Y-m-d H:i:s'), 'admin_note' => $note ?: $reservation['admin_note']];
        }
        // Only from the status we checked: a concurrent approval/rejection makes this a no-op + error.
        if (!$this->decide($reservation, [(string) $reservation['status']], $update)) {
            throw new BusinessRuleException('reservations.errors.cannot_cancel');
        }

        if ($asAdmin) {
            $this->audit->log($actorId, 'reservation.cancelled', 'reservation', $id);
            $this->notifications->notify(
                (int) $reservation['user_id'],
                NotificationService::RESERVATION_CANCELLED,
                $this->params($reservation, 'facility_name') + ['note' => (string) ($note ?? '')],
                '/student/reservations',
            );
        }
    }

    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $page, int $limit): Paginator
    {
        $result = $this->repo->paginate($filters, $limit, Paginator::offset($page, $limit));
        // Views render these flags instead of re-implementing the rules.
        foreach ($result['items'] as &$row) {
            $row['can_cancel'] = self::canCancel($row);
            $row['can_approve'] = self::canApprove($row);
            $row['can_reject'] = $row['status'] === 'PENDING';
        }
        unset($row);

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    public function hasConflictWarning(int $reservationId): bool
    {
        return $this->repo->pendingConflictsWith($reservationId) > 0;
    }

    public function countPending(): int
    {
        return $this->repo->countPending();
    }

    /** @param array<string, mixed> $input */
    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $image  new photo (replaces the current one)
     * @param bool $removeImage  drop the photo (the page then shows a building icon)
     */
    public function saveFacility(array $input, ?int $id = null, ?array $image = null, bool $removeImage = false): int
    {
        $data = $this->validator->validate($input, [
            'code' => 'required|regex:/^[a-z0-9_]{2,40}$/|unique:facilities,code' . ($id !== null ? ",$id" : ''),
            'name_ar' => 'required|string|max:150',
            'name_en' => 'required|string|max:150',
            'description_ar' => 'required|string|max:3000',
            'description_en' => 'required|string|max:3000',
            'location_ar' => 'required|string|max:200',
            'location_en' => 'required|string|max:200',
            'capacity' => 'nullable|integer|min:1|max:100000',
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $data['is_active'] ? 1 : 0;
        $before = $id !== null ? $this->facility($id, false) : null;
        if ($image !== null) {
            $data['image_path'] = $this->uploads->storePublicImage($image, 'image', 'facilities');
        } elseif ($removeImage && $before !== null) {
            $data['image_path'] = null;
        }
        if ($before === null) {
            return $this->db->insert('facilities', $data);
        }
        $this->db->update('facilities', $data, ['id' => $id]);
        if (array_key_exists('image_path', $data)) {
            $this->uploads->deletePublic($before['image_path'] ?? null); // only ever removes files under uploads/
        }

        return $id;
    }

    /** Pending requests, and approved bookings that have not started yet, can be cancelled. @param array<string, mixed> $r */
    public static function canCancel(array $r): bool
    {
        return $r['status'] === 'PENDING'
            || ($r['status'] === 'APPROVED' && strtotime((string) $r['start_datetime']) > time());
    }

    /** Only pending requests whose time is still ahead can be approved. @param array<string, mixed> $r */
    public static function canApprove(array $r): bool
    {
        return $r['status'] === 'PENDING' && strtotime((string) $r['start_datetime']) > time();
    }

    /**
     * Apply a conditional status change with the same lock order as approve(): facility row first,
     * then the reservation. (Changing `status` re-checks the facility foreign key, which locks the
     * facility row; taking the locks in opposite orders deadlocked against a concurrent approval.)
     * @param array<string, mixed> $reservation @param list<string> $from @param array<string, mixed> $data
     */
    private function decide(array $reservation, array $from, array $data): bool
    {
        return $this->db->transaction(function () use ($reservation, $from, $data): bool {
            $this->repo->lockFacility((int) $reservation['facility_id']);

            return $this->repo->transition((int) $reservation['id'], $from, $data);
        });
    }

    /** @return array<string, mixed> */
    private function decision(string $status, int $adminId, ?string $note): array
    {
        return ['status' => $status, 'decided_by' => $adminId, 'decided_at' => date('Y-m-d H:i:s'), 'admin_note' => $note !== '' ? $note : null];
    }

    /** @param array<string, mixed> $row @return array<string, string> */
    private function params(array $row, string $prefix = 'name'): array
    {
        return [
            'facility_ar' => (string) $row[$prefix . '_ar'],
            'facility_en' => (string) $row[$prefix . '_en'],
            'date' => isset($row['start_datetime']) ? substr((string) $row['start_datetime'], 0, 16) : '',
        ];
    }
}

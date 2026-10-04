<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Paginator;
use App\Core\Validator;
use App\Domain\ActivityStatus;
use App\Repositories\ActivityRepository;
use App\Repositories\LookupRepository;

/**
 * Business rules shared by events and volunteer opportunities.
 *
 * Registration is serialized per activity with SELECT ... FOR UPDATE on the activity row,
 * so two students can never take the last seat at the same time. The UNIQUE(activity, user)
 * key is a second line of defence against duplicate registrations.
 */
abstract class ActivityService
{
    public function __construct(
        protected readonly Database $db,
        protected readonly ActivityRepository $repo,
        protected readonly NotificationService $notifications,
        protected readonly AuditLogger $audit,
        protected readonly Validator $validator,
        protected readonly LookupRepository $lookups,
        protected readonly UploadService $uploads,
    ) {
    }

    /** Translation namespace ("events" / "volunteering"). */
    abstract protected function ns(): string;

    /** Public URL prefix ("/events" / "/volunteering"). */
    abstract protected function path(): string;

    abstract protected function registrationTable(): string;

    abstract protected function foreignKey(): string;

    /** @return array{registered:string, unregistered:string, cancelled:string, updated:string} */
    abstract protected function notificationTypes(): array;

    /** Type-specific validation rules (lookup column, hours, image...). @return array<string, string|list<string>> */
    abstract protected function extraRules(): array;

    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $page, int $limit, ?int $userId = null, bool $adminOrder = false): Paginator
    {
        $result = $this->repo->paginate($filters, $limit, Paginator::offset($page, $limit), $userId, $adminOrder);

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    /**
     * One activity. `registration_state` tells the detail page which registration panel to show:
     * open | full | closed_cancelled | closed_started | registered | registered_cancellable.
     * @return array<string, mixed>
     */
    public function get(int $id, ?int $userId = null): array
    {
        $item = $this->repo->find($id, $userId) ?? throw new HttpException(404);
        $item['registration_state'] = self::registrationState($item);

        return $item;
    }

    /** @param array<string, mixed> $item row with status, capacity, registered_count, my_status */
    public static function registrationState(array $item): string
    {
        $mine = $item['my_status'] ?? null;
        if ($mine !== null && $mine !== 'CANCELLED') {
            return self::canUnregister((string) $mine, (string) $item['status']) ? 'registered_cancellable' : 'registered';
        }

        return match (ActivityStatus::from((string) $item['status'])) {
            ActivityStatus::CANCELLED => 'closed_cancelled',
            ActivityStatus::ONGOING, ActivityStatus::COMPLETED => 'closed_started',
            ActivityStatus::UPCOMING => (int) $item['registered_count'] >= (int) $item['capacity'] ? 'full' : 'open',
        };
    }

    /** A student may withdraw only while still merely registered and before the activity starts. */
    public static function canUnregister(string $registrationStatus, string $activityStatus): bool
    {
        return $registrationStatus === 'REGISTERED' && $activityStatus === ActivityStatus::UPCOMING->value;
    }

    /**
     * Attendance can be recorded once the activity has started, and never on a cancelled activity.
     * @param array<string, mixed> $registration needs status + start_datetime (and cancelled_at when known)
     */
    public static function canMarkAttended(array $registration): bool
    {
        return $registration['status'] === 'REGISTERED'
            && ($registration['cancelled_at'] ?? null) === null
            && strtotime((string) $registration['start_datetime']) <= time();
    }

    /** @param array<string, mixed> $registration */
    public static function canUndoAttended(array $registration): bool
    {
        return $registration['status'] === 'ATTENDED';
    }

    /** @param array<string, mixed> $extra columns stored on the registration (e.g. motivation) */
    public function register(int $userId, int $id, array $extra = []): void
    {
        $activity = $this->db->transaction(function () use ($userId, $id, $extra): array {
            $activity = $this->repo->lockForUpdate($id) ?? throw new HttpException(404);
            $status = ActivityStatus::from((string) $activity['status']);

            match ($status) {
                ActivityStatus::CANCELLED => throw new BusinessRuleException($this->ns() . '.errors.cancelled'),
                ActivityStatus::COMPLETED => throw new BusinessRuleException($this->ns() . '.errors.completed'),
                ActivityStatus::ONGOING => throw new BusinessRuleException($this->ns() . '.errors.started'),
                ActivityStatus::UPCOMING => null,
            };

            $existing = $this->repo->registration($id, $userId, true);
            if ($existing !== null && $existing['status'] !== 'CANCELLED') {
                throw new BusinessRuleException($this->ns() . '.errors.already_registered');
            }
            // Removed by an admin (volunteering records who cancelled it): the student can't simply sign up again.
            if ($existing !== null && !empty($existing['reviewed_by'])) {
                throw new BusinessRuleException($this->ns() . '.errors.removed_by_admin');
            }
            if ($this->repo->seatsTaken($id) >= (int) $activity['capacity']) {
                throw new BusinessRuleException($this->ns() . '.errors.full');
            }

            if ($existing !== null) {
                // Re-activate a previously cancelled registration (keeps the unique key intact).
                $this->db->update($this->registrationTable(), ['status' => 'REGISTERED'] + $this->reactivateColumns() + $extra, ['id' => (int) $existing['id']]);
            } else {
                $this->db->insert($this->registrationTable(), [$this->foreignKey() => $id, 'user_id' => $userId] + $extra);
            }

            return $activity;
        });

        $this->notifications->notify($userId, $this->notificationTypes()['registered'], $this->titleParams($activity), $this->path() . '/' . $id);
    }

    public function unregister(int $userId, int $id): void
    {
        $activity = $this->db->transaction(function () use ($userId, $id): array {
            $activity = $this->repo->lockForUpdate($id) ?? throw new HttpException(404);
            $registration = $this->repo->registration($id, $userId, true);
            if ($registration === null || $registration['status'] !== 'REGISTERED') {
                throw new BusinessRuleException($this->ns() . '.errors.not_registered');
            }
            if (!self::canUnregister((string) $registration['status'], (string) $activity['status'])) {
                throw new BusinessRuleException($this->ns() . '.errors.cannot_unregister');
            }
            $this->db->update($this->registrationTable(), ['status' => 'CANCELLED'] + $this->cancelColumns(), ['id' => (int) $registration['id']]);

            return $activity;
        });

        $this->notifications->notify($userId, $this->notificationTypes()['unregistered'], $this->titleParams($activity), $this->path() . '/' . $id);
    }

    /**
     * Validate + create or update (admin).
     * @param array<string, mixed> $input
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $image
     * @param bool $removeImage  drop the uploaded cover so the type/category default is shown
     */
    public function save(array $input, int $adminId, ?int $id = null, ?array $image = null, bool $removeImage = false): int
    {
        $data = $this->validator->validate($input, array_merge([
            'title_ar' => 'required|string|max:200',
            'title_en' => 'required|string|max:200',
            'description_ar' => 'required|string|max:5000',
            'description_en' => 'required|string|max:5000',
            'location_ar' => 'required|string|max:200',
            'location_en' => 'required|string|max:200',
            'start_datetime' => $id === null ? 'required|datetime|after_now' : 'required|datetime',
            'end_datetime' => 'required|datetime|after:start_datetime',
            'capacity' => 'required|integer|min:1|max:100000',
        ], $this->extraRules()));

        foreach (['start_datetime', 'end_datetime'] as $field) {
            $data[$field] = str_replace('T', ' ', (string) $data[$field]) . ':00';
        }

        $before = $id !== null ? ($this->repo->find($id) ?? throw new HttpException(404)) : null;
        if ($before !== null && (int) $data['capacity'] < (int) $before['registered_count']) {
            throw new \App\Core\Exceptions\ValidationException(['capacity' => [
                t($this->ns() . '.errors.capacity_below_registered', ['count' => (int) $before['registered_count']]),
            ]]);
        }

        if ($image !== null) {
            $data['image_path'] = $this->uploads->storePublicImage($image, 'image', $this->ns());
        } elseif ($removeImage && $before !== null) {
            $data['image_path'] = null;
        }

        if ($before === null) {
            $data['created_by'] = $adminId;

            return $this->repo->create($data);
        }

        $this->repo->update($id, $data);
        if (array_key_exists('image_path', $data)) {
            $this->uploads->deletePublic($before['image_path'] ?? null);
        }

        // Tell registered students when something they rely on changed.
        $changed = $before['start_datetime'] !== $data['start_datetime']
            || $before['end_datetime'] !== $data['end_datetime']
            || $before['location_ar'] !== $data['location_ar'];
        if ($changed && $before['cancelled_at'] === null) {
            $this->notifications->notifyMany(
                $this->repo->registeredUserIds($id),
                $this->notificationTypes()['updated'],
                $this->titleParams($data),
                $this->path() . '/' . $id,
            );
        }

        return $id;
    }

    public function cancel(int $adminId, int $id, string $reason): void
    {
        // Stored in a 255-character column and repeated in every registrant's notification.
        $reason = (string) $this->validator->validate(['reason' => $reason], ['reason' => 'nullable|string|max:255'])['reason'];
        $activity = $this->get($id);
        if ($activity['cancelled_at'] !== null) {
            throw new BusinessRuleException($this->ns() . '.errors.already_cancelled');
        }
        if ($activity['status'] === ActivityStatus::COMPLETED->value) {
            throw new BusinessRuleException($this->ns() . '.errors.completed');
        }
        // All or nothing: a failure while writing the audit entry or a notification must not leave the activity
        // cancelled with some (or none) of its registrants told.
        $this->db->transaction(function () use ($adminId, $id, $reason, $activity): void {
            if (!$this->repo->markCancelled($id, mb_substr($reason, 0, 255) ?: null)) {
                throw new BusinessRuleException($this->ns() . '.errors.already_cancelled');
            }
            $this->audit->log($adminId, $this->ns() . '.cancelled', $this->ns(), $id, ['reason' => $reason]);
            $this->notifications->notifyMany(
                $this->repo->registeredUserIds($id),
                $this->notificationTypes()['cancelled'],
                $this->titleParams($activity) + ['reason' => $reason],
                $this->path() . '/' . $id,
            );
        });
    }

    /** Deleting is allowed only when nobody holds a seat; otherwise the admin must cancel. */
    public function delete(int $adminId, int $id): void
    {
        $activity = $this->get($id);
        // Same lock as register(): a student who registers while the admin deletes either gets in first (the delete
        // is refused) or finds the activity gone. Unlocked, the foreign key would silently cascade-delete the new
        // registration along with the activity.
        $this->db->transaction(function () use ($id): void {
            $this->repo->lockForUpdate($id) ?? throw new HttpException(404);
            if ($this->repo->seatsTaken($id) > 0) {
                throw new BusinessRuleException($this->ns() . '.errors.delete_has_registrations');
            }
            $this->repo->delete($id);
        });
        $this->uploads->deletePublic($activity['image_path'] ?? null);
        $this->audit->log($adminId, $this->ns() . '.deleted', $this->ns(), $id, ['title' => (string) $activity['title_en']]);
    }

    /** @param array<string, mixed> $filters */
    public function registrations(int $id, int $page, int $limit, string $q = ''): Paginator
    {
        $activity = $this->get($id);
        $result = $this->repo->registrations($id, $limit, Paginator::offset($page, $limit), $q);
        // Per-row action flags, computed by the same rules the actions enforce.
        foreach ($result['items'] as &$row) {
            $context = $row + ['start_datetime' => $activity['start_datetime'], 'cancelled_at' => $activity['cancelled_at']];
            $row['can_mark_attended'] = self::canMarkAttended($context);
            $row['can_undo_attended'] = self::canUndoAttended($context);
            $row += $this->extraRegistrationFlags($context);
        }
        unset($row);

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    /** Type-specific row flags (volunteering adds completion/cancellation). @param array<string, mixed> $registration @return array<string, bool> */
    protected function extraRegistrationFlags(array $registration): array
    {
        return [];
    }

    /** Admin marks attendance (only once the activity has started). */
    public function markAttended(int $registrationId, bool $attended): int
    {
        $row = $this->repo->registrationWithActivity($registrationId) ?? throw new HttpException(404);

        if (strtotime((string) $row['start_datetime']) > time()) {
            throw new BusinessRuleException($this->ns() . '.errors.attendance_before_start');
        }
        if ($attended && $row['cancelled_at'] !== null) {
            throw new BusinessRuleException($this->ns() . '.errors.cancelled');
        }
        if (!($attended ? self::canMarkAttended($row) : self::canUndoAttended($row))) {
            throw new BusinessRuleException('common.errors.invalid_transition');
        }
        if (!$this->repo->transitionRegistration($registrationId, [$attended ? 'REGISTERED' : 'ATTENDED'], ['status' => $attended ? 'ATTENDED' : 'REGISTERED'])) {
            throw new BusinessRuleException('common.errors.invalid_transition');
        }

        return (int) $row[$this->foreignKey()];
    }

    /** @return array<string, int|float> */
    public function stats(): array
    {
        return [
            'total' => $this->repo->countAll(),
            'upcoming' => $this->repo->countByStatus(ActivityStatus::UPCOMING),
        ];
    }

    /** @param array<string, mixed> $activity @return array<string, string> */
    protected function titleParams(array $activity): array
    {
        return ['title_ar' => (string) $activity['title_ar'], 'title_en' => (string) $activity['title_en']];
    }

    /** @return array<string, mixed> */
    protected function reactivateColumns(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function cancelColumns(): array
    {
        return [];
    }
}

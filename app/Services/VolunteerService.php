<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Paginator;
use App\Core\Validator;
use App\Repositories\LookupRepository;
use App\Repositories\VolunteerRepository;

/**
 * Volunteering. Hours are awarded only when an admin approves completion
 * (REGISTERED/ATTENDED → COMPLETED with hours_awarded); registering awards nothing.
 */
final class VolunteerService extends ActivityService
{
    /** Upper bound for hours awarded to one person for one opportunity. */
    private const MAX_HOURS_PER_REGISTRATION = 200;

    public function __construct(
        Database $db,
        VolunteerRepository $repo,
        NotificationService $notifications,
        AuditLogger $audit,
        Validator $validator,
        LookupRepository $lookups,
        UploadService $uploads,
    ) {
        parent::__construct($db, $repo, $notifications, $audit, $validator, $lookups, $uploads);
    }

    protected function ns(): string
    {
        return 'volunteering';
    }

    protected function path(): string
    {
        return '/volunteering';
    }

    protected function registrationTable(): string
    {
        return 'volunteer_registrations';
    }

    protected function foreignKey(): string
    {
        return 'opportunity_id';
    }

    protected function notificationTypes(): array
    {
        return [
            'registered' => NotificationService::VOLUNTEER_REGISTERED,
            'unregistered' => NotificationService::VOLUNTEER_CANCELLED,
            'cancelled' => NotificationService::OPPORTUNITY_CANCELLED,
            'updated' => NotificationService::OPPORTUNITY_UPDATED,
        ];
    }

    protected function extraRules(): array
    {
        return [
            'category_id' => 'required|integer|in:' . $this->lookups->idList('volunteer_categories'),
            'volunteer_hours' => 'required|numeric|min:0|max:' . self::MAX_HOURS_PER_REGISTRATION,
        ];
    }

    private function volunteers(): VolunteerRepository
    {
        /** @var VolunteerRepository */
        return $this->repo;
    }

    /**
     * Completion (awarding hours) is possible once the opportunity has started, for volunteers who are
     * still registered/attended, and never for a cancelled opportunity.
     * @param array<string, mixed> $registration needs status, cancelled_at, start_datetime
     */
    public static function canComplete(array $registration): bool
    {
        return in_array($registration['status'], ['REGISTERED', 'ATTENDED'], true)
            && $registration['cancelled_at'] === null
            && strtotime((string) $registration['start_datetime']) <= time();
    }

    /** @param array<string, mixed> $registration */
    public static function canCancelRegistration(array $registration): bool
    {
        return in_array($registration['status'], ['REGISTERED', 'ATTENDED'], true);
    }

    protected function extraRegistrationFlags(array $registration): array
    {
        return [
            'can_complete' => self::canComplete($registration),
            'can_cancel_registration' => self::canCancelRegistration($registration),
        ];
    }

    /** @param array<string, mixed> $input */
    public function apply(int $userId, int $id, array $input): void
    {
        $data = $this->validator->validate($input, ['motivation' => 'nullable|string|max:1000']);
        $this->register($userId, $id, ['motivation' => $data['motivation']]);
    }

    /** Admin approves completion and awards hours. */
    public function complete(int $adminId, int $registrationId, mixed $hoursInput): int
    {
        $registration = $this->volunteers()->findRegistration($registrationId) ?? throw new HttpException(404);
        $data = $this->validator->validate(
            ['hours' => $hoursInput ?? $registration['volunteer_hours']],
            ['hours' => 'required|numeric|min:0|max:' . self::MAX_HOURS_PER_REGISTRATION],
        );

        if (!self::canComplete($registration)) {
            // Most specific explanation first.
            throw new BusinessRuleException(match (true) {
                $registration['cancelled_at'] !== null => 'volunteering.errors.cancelled',
                !in_array($registration['status'], ['REGISTERED', 'ATTENDED'], true) => 'volunteering.errors.cannot_complete',
                default => 'volunteering.errors.complete_before_start',
            });
        }

        // Guarded: a concurrent completion/cancellation wins and this one changes nothing (no double notification).
        if (!$this->volunteers()->transitionRegistration($registrationId, ['REGISTERED', 'ATTENDED'], [
            'status' => 'COMPLETED',
            'hours_awarded' => $data['hours'],
            'reviewed_by' => $adminId,
            'completed_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new BusinessRuleException('volunteering.errors.cannot_complete');
        }

        $this->audit->log($adminId, 'volunteering.completed', 'volunteer_registration', $registrationId, ['hours' => $data['hours']]);
        $this->notifications->notify(
            (int) $registration['user_id'],
            NotificationService::VOLUNTEER_COMPLETED,
            $this->titleParams($registration) + ['hours' => fmt_number($data['hours'], 1)],
            '/student/volunteering',
        );

        return (int) $registration['opportunity_id'];
    }

    /** Admin cancels one person's registration (e.g. did not show up). */
    public function cancelRegistration(int $adminId, int $registrationId): int
    {
        $registration = $this->volunteers()->findRegistration($registrationId) ?? throw new HttpException(404);
        if (!self::canCancelRegistration($registration)) {
            throw new BusinessRuleException('common.errors.invalid_transition');
        }
        if (!$this->volunteers()->transitionRegistration($registrationId, ['REGISTERED', 'ATTENDED'], ['status' => 'CANCELLED', 'reviewed_by' => $adminId])) {
            throw new BusinessRuleException('common.errors.invalid_transition');
        }
        $this->notifications->notify((int) $registration['user_id'], NotificationService::VOLUNTEER_CANCELLED, $this->titleParams($registration), '/student/volunteering');

        return (int) $registration['opportunity_id'];
    }

    public function hoursFor(int $userId): float
    {
        return $this->volunteers()->hoursFor($userId);
    }

    public function forStudent(int $userId, ?string $status, int $page, int $limit): Paginator
    {
        if ($status !== null && !in_array($status, ['REGISTERED', 'ATTENDED', 'COMPLETED', 'CANCELLED'], true)) {
            throw new ValidationException(['status' => [t('validation.in', ['attribute' => 'status'])]]);
        }
        $result = $this->volunteers()->forStudent($userId, $status, $limit, Paginator::offset($page, $limit));
        foreach ($result['items'] as &$row) {
            $row['can_unregister'] = self::canUnregister((string) $row['registration_status'], (string) $row['status']);
        }
        unset($row);

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function upcoming(int $limit, ?int $userId = null): array
    {
        return $this->repo->upcoming($limit, $userId);
    }

    public function stats(): array
    {
        return parent::stats() + ['hours' => $this->volunteers()->totalAwardedHours()];
    }
}

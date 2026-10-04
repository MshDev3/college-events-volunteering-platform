<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Validator;
use App\Domain\ActivityStatus;
use App\Repositories\EventRepository;
use App\Repositories\LookupRepository;

final class EventService extends ActivityService
{
    public function __construct(
        Database $db,
        EventRepository $repo,
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
        return 'events';
    }

    protected function path(): string
    {
        return '/events';
    }

    protected function registrationTable(): string
    {
        return 'event_registrations';
    }

    protected function foreignKey(): string
    {
        return 'event_id';
    }

    protected function notificationTypes(): array
    {
        return [
            'registered' => NotificationService::EVENT_REGISTERED,
            'unregistered' => NotificationService::EVENT_UNREGISTERED,
            'cancelled' => NotificationService::EVENT_CANCELLED,
            'updated' => NotificationService::EVENT_UPDATED,
        ];
    }

    protected function extraRules(): array
    {
        return ['event_type_id' => 'required|integer|in:' . $this->lookups->idList('event_types')];
    }

    protected function reactivateColumns(): array
    {
        return ['cancelled_at' => null, 'registered_at' => date('Y-m-d H:i:s')];
    }

    protected function cancelColumns(): array
    {
        return ['cancelled_at' => date('Y-m-d H:i:s')];
    }

    private function events(): EventRepository
    {
        /** @var EventRepository */
        return $this->repo;
    }

    public function forStudent(int $userId, ?ActivityStatus $tab, int $page, int $limit): Paginator
    {
        $result = $this->events()->forStudent($userId, $tab, $limit, Paginator::offset($page, $limit));
        foreach ($result['items'] as &$row) {
            $row['can_unregister'] = self::canUnregister((string) $row['registration_status'], (string) $row['status']);
        }
        unset($row);

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    /** @return array<string, int> */
    public function studentTabCounts(int $userId): array
    {
        return $this->events()->studentTabCounts($userId);
    }

    /** @param array<string, mixed> $filters */
    public function searchRegistrations(array $filters, int $page, int $limit): Paginator
    {
        $result = $this->events()->searchRegistrations($filters, $limit, Paginator::offset($page, $limit));

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function upcoming(int $limit, ?int $userId = null): array
    {
        return $this->repo->upcoming($limit, $userId);
    }

    public function stats(): array
    {
        return parent::stats() + ['registrations' => $this->events()->totalActiveRegistrations()];
    }
}

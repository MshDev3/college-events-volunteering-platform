<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Translator;
use App\Core\Validator;
use App\Domain\Role;
use App\Repositories\EventRepository;
use App\Repositories\LookupRepository;
use App\Services\ActivityService;
use App\Services\AuditLogger;
use App\Services\EventService;
use App\Services\NotificationService;
use App\Services\UploadService;
use PDO;

/**
 * S5 (delete races with registration), S7 (cancel is all-or-nothing), S8 (no attendance on a cancelled activity).
 */
final class ActivityGuardsTest extends IntegrationTestCase
{
    private function register(int $eventId, int $userId): int
    {
        return $this->db->insert('event_registrations', ['event_id' => $eventId, 'user_id' => $userId]);
    }

    // ---------------------------------------------------------------- S8

    public function testAttendanceCannotBeMarkedOnACancelledEvent(): void
    {
        $admin = $this->user(Role::ADMIN);
        $event = $this->event('-2 hours', '+2 hours');
        $registration = $this->register($event, $this->user());
        $service = app(EventService::class);

        // Before the cancellation the registration can be marked (the rule is only about cancelled activities).
        $service->markAttended($registration, true);
        $service->markAttended($registration, false);

        $service->cancel($admin, $event, 'Cancelled for the test');

        $this->expectException(BusinessRuleException::class);
        $service->markAttended($registration, true);
    }

    public function testTheAttendanceFlagIsOffForACancelledActivity(): void
    {
        $started = ['status' => 'REGISTERED', 'start_datetime' => date('Y-m-d H:i:s', strtotime('-1 hour'))];

        self::assertTrue(ActivityService::canMarkAttended($started + ['cancelled_at' => null]));
        self::assertFalse(ActivityService::canMarkAttended($started + ['cancelled_at' => '2026-01-01 10:00:00']));
        self::assertTrue(ActivityService::canMarkAttended($started), 'rows without the column behave as before');
    }

    // ---------------------------------------------------------------- S7

    /** A notification service whose every statement fails, to break the cancellation after the event row was updated. */
    private function brokenNotifications(): NotificationService
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (): never {
            throw new \RuntimeException('notifications are down');
        });

        return new NotificationService(new Database($pdo), app(Translator::class));
    }

    private function eventService(NotificationService $notifications): EventService
    {
        return new EventService(
            $this->db,
            app(EventRepository::class),
            $notifications,
            app(AuditLogger::class),
            app(Validator::class),
            app(LookupRepository::class),
            app(UploadService::class),
        );
    }

    public function testCancellationIsAllOrNothing(): void
    {
        $admin = $this->user(Role::ADMIN);
        $event = $this->event();
        $this->register($event, $this->user());

        try {
            $this->eventService($this->brokenNotifications())->cancel($admin, $event, 'Will not be applied');
            self::fail('the broken notification service should make the cancellation fail');
        } catch (\RuntimeException $e) {
            self::assertSame('notifications are down', $e->getMessage());
        }

        self::assertNull($this->db->value('SELECT cancelled_at FROM events WHERE id = ?', [$event]), 'the event must not be left cancelled without its registrants having been told');
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'events.cancelled'"));
    }

    public function testCancellationStillWorksAndNotifiesEveryone(): void
    {
        $admin = $this->user(Role::ADMIN);
        $event = $this->event();
        $this->register($event, $this->user());
        $this->register($event, $this->user());

        app(EventService::class)->cancel($admin, $event, 'Weather');

        self::assertNotNull($this->db->value('SELECT cancelled_at FROM events WHERE id = ?', [$event]));
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM notifications'));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'events.cancelled'"));
    }

    // ---------------------------------------------------------------- S5

    public function testDeleteCannotSilentlyDropARegistrationThatArrivesDuringIt(): void
    {
        // Deterministic version of the race. This process holds the event row; a delete worker starts. Code that checks
        // for seats and then deletes without locking passes its check, then waits at the DELETE. Meanwhile a student
        // registers (here) and releases the row, and the DELETE would cascade the new registration away.
        // Code that locks first waits before it checks, sees the registration, and refuses.
        $admin = $this->user(Role::ADMIN);
        $student = $this->user();
        $event = $this->event();
        $pdo = $this->db->pdo();

        $env = getenv();
        $env['DB_DATABASE'] = (string) config('db.test_database');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM events WHERE id = ? FOR UPDATE')->execute([$event]);
            $proc = proc_open([PHP_BINARY, __DIR__ . '/workers/activity-worker.php', 'delete', (string) $event, (string) $admin, sprintf('%.6F', microtime(true) + 0.5)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($proc);
            usleep(2_500_000); // the worker boots in ~1 s, then waits on the row (either before or at its DELETE)
            self::assertTrue(proc_get_status($proc)['running'], 'the delete must be waiting for the event row');
            $this->register($event, $student);
        } finally {
            $pdo->commit();
        }

        $result = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        proc_close($proc);

        self::assertSame('events.errors.delete_has_registrations', $result, 'a delete that overlaps a registration must refuse');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM events WHERE id = ?', [$event]));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM event_registrations WHERE event_id = ?', [$event]), 'the registration survives');
    }

    public function testDeleteStillWorksForAnEventWithoutRegistrations(): void
    {
        $admin = $this->user(Role::ADMIN);
        $event = $this->event();

        app(EventService::class)->delete($admin, $event);

        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM events WHERE id = ?', [$event]));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'events.deleted'"));
    }
}

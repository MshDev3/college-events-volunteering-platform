<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ValidationException;
use App\Domain\Role;
use App\Services\EventService;

final class EventRegistrationTest extends IntegrationTestCase
{
    private EventService $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = app(EventService::class);
    }

    public function testCapacityIsNeverExceededAndDuplicatesAreRefused(): void
    {
        $event = $this->event(capacity: 2);
        [$a, $b, $c] = [$this->user(), $this->user(), $this->user()];

        $this->events->register($a, $event);
        $this->assertRule('events.errors.already_registered', fn () => $this->events->register($a, $event));
        $this->events->register($b, $event);
        $this->assertRule('events.errors.full', fn () => $this->events->register($c, $event));

        self::assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'CANCELLED'", [$event]));
        self::assertSame('2 / 2', $this->events->get($event)['registered_count'] . ' / ' . $this->events->get($event)['capacity']);
    }

    public function testClosedEventsRefuseRegistration(): void
    {
        $student = $this->user();
        $this->assertRule('events.errors.started', fn () => $this->events->register($student, $this->event('-1 hour', '+1 hour')));
        $this->assertRule('events.errors.completed', fn () => $this->events->register($student, $this->event('-3 hours', '-1 hour')));
        $cancelled = $this->event(overrides: ['cancelled_at' => date('Y-m-d H:i:s')]);
        $this->assertRule('events.errors.cancelled', fn () => $this->events->register($student, $cancelled));
    }

    public function testCancelFreesTheSeatAndCanBeUndone(): void
    {
        $event = $this->event(capacity: 1);
        [$a, $b] = [$this->user(), $this->user()];
        $this->events->register($a, $event);
        $this->events->unregister($a, $event);
        $this->events->register($b, $event);                      // seat freed
        $this->assertRule('events.errors.full', fn () => $this->events->register($a, $event));
        self::assertSame('registered_cancellable', $this->events->get($event, $b)['registration_state']);
    }

    public function testCapacityCannotDropBelowCurrentRegistrations(): void
    {
        $admin = $this->user(Role::ADMIN);
        $event = $this->event(capacity: 5);
        $this->events->register($this->user(), $event);
        $this->events->register($this->user(), $event);
        $row = $this->events->get($event);

        $this->expectException(ValidationException::class);
        $this->events->save([
            'title_ar' => 'x', 'title_en' => 'x', 'description_ar' => 'x', 'description_en' => 'x', 'location_ar' => 'x', 'location_en' => 'x',
            'event_type_id' => $row['event_type_id'], 'capacity' => 1,
            'start_datetime' => date('Y-m-d\TH:i', strtotime((string) $row['start_datetime'])),
            'end_datetime' => date('Y-m-d\TH:i', strtotime((string) $row['end_datetime'])),
        ], $admin, $event);
    }

    public function testCancellingNotifiesRegistrantsWithoutDanglingReason(): void
    {
        $admin = $this->user(Role::ADMIN);
        $student = $this->user();
        $event = $this->event();
        $this->events->register($student, $event);
        $this->events->cancel($admin, $event, '');

        $items = app(\App\Services\NotificationService::class)->forUser($student, 10)['items'];
        $cancelled = array_values(array_filter($items, static fn ($n) => $n['type'] === 'event_cancelled'))[0];
        self::assertSame($cancelled['body'], rtrim($cancelled['body']), 'no trailing space when the reason is empty');
        self::assertSame('CANCELLED', $this->events->get($event)['status']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Role;
use App\Repositories\VolunteerRepository;
use App\Services\EventService;
use App\Services\VolunteerService;

/**
 * L-5: a volunteer removed by an admin cannot sign up again, and admin actions on registrations
 * and activities apply only from the state they were decided in (no double notifications).
 */
final class ActivityTransitionTest extends IntegrationTestCase
{
    private function volunteerRegistration(int $opportunity, int $user): int
    {
        return (int) $this->db->value('SELECT id FROM volunteer_registrations WHERE opportunity_id = ? AND user_id = ?', [$opportunity, $user]);
    }

    private function notifications(int $user, string $type): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$user, $type]);
    }

    public function testVolunteerRemovedByAdminCannotSignUpAgain(): void
    {
        $service = app(VolunteerService::class);
        $admin = $this->user(Role::ADMIN);
        $student = $this->user();
        $opp = $this->opportunity();

        $service->apply($student, $opp, ['motivation' => 'x']);
        $service->cancelRegistration($admin, $this->volunteerRegistration($opp, $student));
        $this->assertRule('volunteering.errors.removed_by_admin', fn () => $service->apply($student, $opp, []));

        // A student who withdrew by themselves can still come back.
        $other = $this->user();
        $service->apply($other, $opp, []);
        $service->unregister($other, $opp);
        $service->apply($other, $opp, []);
        self::assertSame('REGISTERED', $this->db->value('SELECT status FROM volunteer_registrations WHERE id = ?', [$this->volunteerRegistration($opp, $other)]));
    }

    public function testAdminRegistrationActionsApplyOnlyOnce(): void
    {
        $service = app(VolunteerService::class);
        $admin = $this->user(Role::ADMIN);
        $student = $this->user();
        $opp = $this->opportunity();
        $service->apply($student, $opp, []);
        $registration = $this->volunteerRegistration($opp, $student);
        $this->db->query('UPDATE volunteer_opportunities SET start_datetime = NOW() - INTERVAL 3 HOUR, end_datetime = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$opp]);

        $service->complete($admin, $registration, 2);
        $this->assertRule('volunteering.errors.cannot_complete', fn () => $service->complete($admin, $registration, 3));
        $this->assertRule('common.errors.invalid_transition', fn () => $service->cancelRegistration($admin, $registration));
        self::assertSame(1, $this->notifications($student, 'volunteer_completed'));
        self::assertSame('2.00', (string) $this->db->value('SELECT hours_awarded FROM volunteer_registrations WHERE id = ?', [$registration]));

        // The SQL guard itself: a stale "REGISTERED" decision changes nothing once completed.
        self::assertFalse(app(VolunteerRepository::class)->transitionRegistration($registration, ['REGISTERED', 'ATTENDED'], ['status' => 'CANCELLED']));
    }

    public function testAttendanceAndActivityCancellationAreGuarded(): void
    {
        $events = app(EventService::class);
        $admin = $this->user(Role::ADMIN);
        $student = $this->user();
        $event = $this->event('+1 day', '+1 day +2 hours');
        $events->register($student, $event);
        $registration = (int) $this->db->value('SELECT id FROM event_registrations WHERE event_id = ? AND user_id = ?', [$event, $student]);
        $this->db->query('UPDATE events SET start_datetime = NOW() - INTERVAL 1 HOUR, end_datetime = NOW() + INTERVAL 1 HOUR WHERE id = ?', [$event]);

        $events->markAttended($registration, true);
        $this->assertRule('common.errors.invalid_transition', fn () => $events->markAttended($registration, true));
        $events->markAttended($registration, false);
        self::assertSame('REGISTERED', $this->db->value('SELECT status FROM event_registrations WHERE id = ?', [$registration]));

        $upcoming = $this->event('+5 days', '+5 days +1 hour');
        $events->register($student, $upcoming);
        $events->cancel($admin, $upcoming, 'Room unavailable');
        $this->assertRule('events.errors.already_cancelled', fn () => $events->cancel($admin, $upcoming, 'Again'));
        self::assertSame(1, $this->notifications($student, 'event_cancelled'), 'registrants are notified once');
    }
}

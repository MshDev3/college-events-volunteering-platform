<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Role;
use App\Services\ReservationService;
use App\Services\VolunteerService;

final class ReservationAndVolunteerTest extends IntegrationTestCase
{
    /** @param array<string, mixed> $extra */
    private function request(ReservationService $service, int $user, string $date, string $from, string $to, array $extra = []): int
    {
        return $service->request($user, $extra + [
            'facility_id' => (int) $this->db->value("SELECT id FROM facilities WHERE code = 'theater'"),
            'date' => $date, 'start_time' => $from, 'end_time' => $to, 'purpose' => 'Integration test booking',
        ]);
    }

    public function testApprovedBookingsCannotOverlap(): void
    {
        $service = app(ReservationService::class);
        $admin = $this->user(Role::ADMIN);
        $day = date('Y-m-d', strtotime('+10 days'));

        $a = $this->request($service, $this->user(), $day, '10:00', '12:00');
        $b = $this->request($service, $this->user(), $day, '11:00', '13:00');    // pending overlap is allowed…
        $adjacent = $this->request($service, $this->user(), $day, '12:00', '14:00');

        $service->approve($admin, $a, null);
        $this->assertRule('reservations.errors.conflict_on_approve', fn () => $service->approve($admin, $b, null));   // …but never approved
        $service->approve($admin, $adjacent, null);                                 // touching intervals do not conflict
        $this->assertRule('reservations.errors.conflict', fn () => $this->request($service, $this->user(), $day, '11:30', '11:45'));

        self::assertSame('PENDING', $this->db->value('SELECT status FROM facility_reservations WHERE id = ?', [$b]));
    }

    public function testCancellationRules(): void
    {
        $service = app(ReservationService::class);
        $owner = $this->user();
        $other = $this->user();
        $id = $this->request($service, $owner, date('Y-m-d', strtotime('+5 days')), '09:00', '10:00');

        try {
            $service->cancel($other, $id, false);
            self::fail('Another student must not cancel this reservation.');
        } catch (\App\Core\Exceptions\HttpException $e) {
            self::assertSame(404, $e->status);
        }
        $service->cancel($owner, $id, false);
        $this->assertRule('reservations.errors.cannot_cancel', fn () => $service->cancel($owner, $id, false));
    }

    public function testHoursAreAwardedOnlyOnApprovedCompletion(): void
    {
        $service = app(VolunteerService::class);
        $admin = $this->user(Role::ADMIN);
        $student = $this->user();
        $future = $this->opportunity(hours: 4);
        $service->apply($student, $future, ['motivation' => 'test']);
        self::assertSame(0.0, $service->hoursFor($student), 'registering awards nothing');

        $regId = (int) $this->db->value('SELECT id FROM volunteer_registrations WHERE opportunity_id = ?', [$future]);
        $this->assertRule('volunteering.errors.complete_before_start', fn () => $service->complete($admin, $regId, 4));

        $this->db->query('UPDATE volunteer_opportunities SET start_datetime = NOW() - INTERVAL 5 HOUR, end_datetime = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$future]);
        $service->complete($admin, $regId, 3.5);
        self::assertSame(3.5, $service->hoursFor($student));

        // A cancelled registration on another opportunity never counts.
        $other = $this->opportunity('-5 hours', '-1 hour', 10);
        $this->db->insert('volunteer_registrations', ['opportunity_id' => $other, 'user_id' => $student, 'status' => 'CANCELLED']);
        self::assertSame(3.5, $service->hoursFor($student));
        $this->assertRule('volunteering.errors.cannot_complete', fn () => $service->complete($admin, $regId, 1));
    }
}

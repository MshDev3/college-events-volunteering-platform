<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Role;
use App\Repositories\ReservationRepository;
use App\Services\ReservationService;

/**
 * M-2: every reservation status change is conditional on the status it was decided from,
 * so concurrent admin actions can never overwrite each other.
 */
final class ReservationTransitionTest extends IntegrationTestCase
{
    private function reservation(int $user, string $when = '+6 days'): int
    {
        return app(ReservationService::class)->request($user, [
            'facility_id' => (int) $this->db->value("SELECT id FROM facilities WHERE code = 'theater'"),
            'date' => date('Y-m-d', strtotime($when)), 'start_time' => '10:00', 'end_time' => '11:00',
            'purpose' => 'Transition test booking',
        ]);
    }

    private function statusOf(int $id): string
    {
        return (string) $this->db->value('SELECT status FROM facility_reservations WHERE id = ?', [$id]);
    }

    private function decisionNotifications(int $userId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type IN ('reservation_approved', 'reservation_rejected', 'reservation_cancelled')",
            [$userId],
        );
    }

    public function testRepositoryTransitionOnlyAppliesFromTheExpectedStatus(): void
    {
        $repo = app(ReservationRepository::class);
        $id = $this->reservation($this->user());
        $this->db->query("UPDATE facility_reservations SET status = 'APPROVED' WHERE id = ?", [$id]);

        self::assertFalse($repo->transition($id, ['PENDING'], ['status' => 'REJECTED']), 'a stale "PENDING" decision is refused');
        self::assertSame('APPROVED', $this->statusOf($id));
        self::assertTrue($repo->transition($id, ['PENDING', 'APPROVED'], ['status' => 'CANCELLED']));
        self::assertSame('CANCELLED', $this->statusOf($id));
    }

    public function testDecidedRequestsCannotBeDecidedAgain(): void
    {
        $service = app(ReservationService::class);
        $admin = $this->user(Role::ADMIN);
        $student = $this->user();

        $approved = $this->reservation($student);
        $service->approve($admin, $approved, null);
        $this->assertRule('reservations.errors.not_pending', fn () => $service->reject($admin, $approved, 'Too late'));
        $this->assertRule('reservations.errors.not_pending', fn () => $service->approve($admin, $approved, null));
        self::assertSame('APPROVED', $this->statusOf($approved));

        $rejected = $this->reservation($student, '+7 days');
        $service->reject($admin, $rejected, 'No staff available');
        $this->assertRule('reservations.errors.not_pending', fn () => $service->approve($admin, $rejected, null));
        $this->assertRule('reservations.errors.cannot_cancel', fn () => $service->cancel($student, $rejected, false));
        self::assertSame('REJECTED', $this->statusOf($rejected));
    }

    public function testApprovedBookingsCanBeCancelledOnlyBeforeTheyStart(): void
    {
        $service = app(ReservationService::class);
        $admin = $this->user(Role::ADMIN);
        $student = $this->user();

        $future = $this->reservation($student);
        $service->approve($admin, $future, null);
        $service->cancel($student, $future, false);
        self::assertSame('CANCELLED', $this->statusOf($future));

        $started = $this->reservation($student, '+8 days');
        $service->approve($admin, $started, null);
        $this->db->query('UPDATE facility_reservations SET start_datetime = NOW() - INTERVAL 1 HOUR, end_datetime = NOW() + INTERVAL 1 HOUR WHERE id = ?', [$started]);
        $this->assertRule('reservations.errors.cannot_cancel', fn () => $service->cancel($admin, $started, true));
        self::assertFalse(app(ReservationRepository::class)->transition($started, ['APPROVED'], ['status' => 'CANCELLED']), 'the SQL guard holds even without the PHP check');
        self::assertSame('APPROVED', $this->statusOf($started));
    }

    public function testSimultaneousApprovalsOfOverlappingRequestsNeverDoubleBook(): void
    {
        $admin = $this->user(Role::ADMIN);
        $env = getenv();
        $env['DB_DATABASE'] = (string) config('db.test_database');

        for ($round = 0; $round < 3; $round++) {
            $when = '+' . (20 + $round) . ' days';
            $ids = [$this->reservation($this->user(), $when), $this->reservation($this->user(), $when)]; // same slot
            $start = sprintf('%.6F', microtime(true) + 1.5);
            $procs = [];
            foreach ($ids as $id) {
                $proc = proc_open([PHP_BINARY, __DIR__ . '/workers/reservation-worker.php', 'approve', (string) $id, (string) $admin, $start],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $procs[] = [$proc, $pipes];
            }
            $results = [];
            foreach ($procs as [$proc, $pipes]) {
                $results[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
                proc_close($proc);
            }
            sort($results);
            self::assertSame(['ok', 'reservations.errors.conflict_on_approve'], $results, "round $round");
            self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM facility_reservations WHERE id IN (?, ?) AND status = 'APPROVED'", $ids));
        }
    }

    public function testSimultaneousApproveAndRejectHaveExactlyOneWinner(): void
    {
        $admin = $this->user(Role::ADMIN);
        $env = getenv();
        $env['DB_DATABASE'] = (string) config('db.test_database');

        for ($round = 0; $round < 4; $round++) {
            $student = $this->user();
            $id = $this->reservation($student, '+' . (10 + $round) . ' days');
            $start = sprintf('%.6F', microtime(true) + 1.5);
            $procs = [];
            foreach (['approve', 'reject', 'cancel'] as $action) {
                $procs[$action] = proc_open([PHP_BINARY, __DIR__ . '/workers/reservation-worker.php', $action, (string) $id, (string) $admin, $start],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $procs[$action] = [$procs[$action], $pipes];
            }
            $results = [];
            foreach ($procs as $action => [$proc, $pipes]) {
                $results[$action] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
                proc_close($proc);
            }

            $winners = array_keys($results, 'ok', true);
            $final = $this->statusOf($id);
            $expected = ['approve' => 'APPROVED', 'reject' => 'REJECTED', 'cancel' => 'CANCELLED'];
            // approve + cancel may both succeed in sequence (cancelling a future approved booking is allowed).
            $consistent = count($winners) === 1 ? $final === $expected[$winners[0]]
                : ($winners === ['approve', 'cancel'] && $final === 'CANCELLED');
            self::assertTrue($consistent, "round $round: " . json_encode($results) . " final=$final");
            self::assertSame(count($winners), $this->decisionNotifications($student), 'one notification per applied decision, never a contradictory one');
            foreach (array_diff_key($results, array_flip($winners)) as $action => $result) {
                self::assertContains($result, ['reservations.errors.not_pending', 'reservations.errors.cannot_cancel'], "$action lost with an unexpected result");
            }
        }
    }
}

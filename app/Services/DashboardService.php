<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\EventRepository;
use App\Repositories\UserRepository;
use App\Repositories\VolunteerRepository;

/** Aggregated numbers for dashboards and the homepage. Every figure is a live query. */
final class DashboardService
{
    /** @var array<string, int>|null */
    private ?array $sidebar = null;

    public function __construct(
        private readonly Database $db,
        private readonly EventService $events,
        private readonly VolunteerService $volunteering,
        private readonly ReservationService $reservations,
        private readonly FeedbackService $feedback,
        private readonly ContactService $contact,
        private readonly UserRepository $users,
        private readonly EventRepository $eventRepo,
        private readonly VolunteerRepository $volunteerRepo,
    ) {
    }

    /** @return array<string, int|float> */
    public function adminStats(): array
    {
        $events = $this->events->stats();
        $volunteer = $this->volunteering->stats();

        return [
            'students' => $this->users->countStudents(),
            'events' => $events['total'],
            'upcoming_events' => $events['upcoming'],
            'registrations' => $events['registrations'],
            'opportunities' => $volunteer['total'],
            'volunteer_hours' => $volunteer['hours'],
            'pending_reservations' => $this->reservations->countPending(),
            'open_complaints' => $this->feedback->countOpenComplaints(),
            'unread_messages' => $this->contact->countUnread(),
        ];
    }

    /** Cheap counters for the admin sidebar badges (memoized per request). @return array<string, int> */
    public function sidebarCounts(): array
    {
        return $this->sidebar ??= [
            'pending_reservations' => $this->reservations->countPending(),
            'open_feedback' => $this->feedback->countOpen(),
            'unread_messages' => $this->contact->countUnread(),
        ];
    }

    /** Public homepage figures. @return array<string, int|float> */
    public function publicStats(): array
    {
        return [
            'upcoming_events' => $this->eventRepo->countByStatus(\App\Domain\ActivityStatus::UPCOMING),
            'opportunities' => $this->volunteerRepo->countByStatus(\App\Domain\ActivityStatus::UPCOMING),
            'volunteer_hours' => $this->volunteerRepo->totalAwardedHours(),
            'students' => $this->users->countStudents(),
        ];
    }

    /** @return array<string, int|float> */
    public function studentStats(int $userId): array
    {
        $row = $this->db->fetch(
            "SELECT
               SUM(r.status <> 'CANCELLED' AND e.cancelled_at IS NULL) AS registered,
               SUM(r.status <> 'CANCELLED' AND e.cancelled_at IS NULL AND e.end_datetime < NOW()) AS completed
             FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.user_id = ?",
            [$userId],
        ) ?? [];

        return [
            'registered_events' => (int) ($row['registered'] ?? 0),
            'completed_events' => (int) ($row['completed'] ?? 0),
            'volunteer_hours' => $this->volunteering->hoursFor($userId),
        ];
    }

    /**
     * Recent actions of a student across modules, newest first.
     * @return list<array{kind:string, title_ar:string, title_en:string, status:string, at:string, link:string}>
     */
    public function studentActivity(int $userId, int $limit = 6): array
    {
        return $this->db->fetchAll(
            "(SELECT 'event' AS kind, e.title_ar, e.title_en, r.status, r.updated_at AS at, CONCAT('/events/', e.id) AS link
              FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.user_id = ?)
             UNION ALL
             (SELECT 'volunteer', o.title_ar, o.title_en, v.status, v.updated_at, CONCAT('/volunteering/', o.id)
              FROM volunteer_registrations v JOIN volunteer_opportunities o ON o.id = v.opportunity_id WHERE v.user_id = ?)
             UNION ALL
             (SELECT 'reservation', f.name_ar, f.name_en, fr.status, fr.updated_at, '/student/reservations'
              FROM facility_reservations fr JOIN facilities f ON f.id = fr.facility_id WHERE fr.user_id = ?)
             UNION ALL
             (SELECT 'feedback', fb.subject, fb.subject, fb.status, fb.updated_at, CONCAT('/student/feedback/', fb.id)
              FROM feedback fb WHERE fb.user_id = ?)
             ORDER BY at DESC LIMIT ?",
            [$userId, $userId, $userId, $userId, $limit],
        );
    }

    /** @return list<array<string, mixed>> */
    public function latestFeedback(int $limit = 5): array
    {
        return $this->feedback->list([], 1, $limit)->items;
    }

    /** @return list<array<string, mixed>> */
    public function pendingReservations(int $limit = 5): array
    {
        return $this->reservations->list(['status' => 'PENDING'], 1, $limit)->items;
    }
}

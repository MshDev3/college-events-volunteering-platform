<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ValidationException;
use App\Services\FeedbackService;
use App\Services\ReservationService;
use App\Services\UserService;

/**
 * M-3 / L-6: per-student limits on feedback and booking requests, and on current-password checks.
 */
final class ThrottlingTest extends IntegrationTestCase
{
    /** Run $fn and return the translated message of the given field, or null when it succeeded. */
    private function errorOf(string $field, callable $fn): ?string
    {
        try {
            $fn();

            return null;
        } catch (ValidationException $e) {
            return $e->errors[$field][0] ?? json_encode($e->errors);
        }
    }

    public function testFeedbackIsLimitedPerStudentPerHour(): void
    {
        $service = app(FeedbackService::class);
        $student = $this->user();
        $input = ['type' => 'SUGGESTION', 'category_id' => (int) $this->db->value('SELECT MIN(id) FROM feedback_categories'),
            'subject' => 'Throttle test', 'message' => 'A suggestion long enough to be valid.'];

        for ($i = 0; $i < FeedbackService::MAX_PER_HOUR; $i++) {
            self::assertNull($this->errorOf('message', fn () => $service->submit($student, $input, null)), "submission $i");
        }
        self::assertSame(t('feedback.errors.too_many'), $this->errorOf('message', fn () => $service->submit($student, $input, null)));
        self::assertSame(FeedbackService::MAX_PER_HOUR, (int) $this->db->value('SELECT COUNT(*) FROM feedback WHERE user_id = ?', [$student]));
        self::assertNull($this->errorOf('message', fn () => $service->submit($this->user(), $input, null)), 'other students are not affected');
    }

    public function testBookingRequestsAreLimitedPerStudentPerHour(): void
    {
        $service = app(ReservationService::class);
        $student = $this->user();
        $facility = (int) $this->db->value("SELECT id FROM facilities WHERE code = 'theater'");
        $request = fn (int $day) => $service->request($student, [
            'facility_id' => $facility, 'date' => date('Y-m-d', strtotime("+$day days")),
            'start_time' => '10:00', 'end_time' => '11:00', 'purpose' => 'Throttle test booking',
        ]);

        for ($i = 1; $i <= ReservationService::MAX_REQUESTS_PER_HOUR; $i++) {
            self::assertNull($this->errorOf('purpose', fn () => $request($i)), "request $i");
        }
        self::assertSame(t('reservations.errors.too_many'), $this->errorOf('purpose', fn () => $request(30)));
        self::assertSame(ReservationService::MAX_REQUESTS_PER_HOUR, (int) $this->db->value('SELECT COUNT(*) FROM facility_reservations WHERE user_id = ?', [$student]));
    }

    public function testCurrentPasswordChecksAreThrottled(): void
    {
        $mail = $this->captureMail();
        $users = app(UserService::class);
        $id = $this->user(password: 'Correct-Passw0rd');
        $user = $users->find($id);
        $change = fn (string $newEmail, string $password) => $users->updateProfile($user, [
            'full_name' => $user->fullName, 'email' => $newEmail, 'phone' => '0551234567',
            'preferred_locale' => 'en', 'email_password' => $password,
        ]);

        self::assertNull($this->errorOf('email_password', fn () => $change("changed.$id@test.local", 'Correct-Passw0rd')), 'a correct password is not counted');
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(t('profile.errors.password_required_for_email'), $this->errorOf('email_password', fn () => $change("again.$id@test.local", "wrong-$i")));
        }
        $throttled = $this->errorOf('email_password', fn () => $change("again.$id@test.local", 'Correct-Passw0rd'));
        self::assertStringContainsString(t('profile.errors.too_many_password_checks', ['minutes' => 15]), (string) $throttled);

        self::assertSame($user->email, $this->db->value('SELECT email FROM users WHERE id = ?', [$id]), 'nothing changes before the link is confirmed');
        self::assertSame("changed.$id@test.local", $this->db->value('SELECT new_email FROM email_change_requests WHERE user_id = ?', [$id]), 'throttled attempts did not replace the pending request');
        self::assertCount(1, $mail, 'only the accepted request sent a link');
    }
}

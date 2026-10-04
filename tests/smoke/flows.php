<?php

declare(strict_types=1);

/**
 * End-to-end behaviour tests against the running site + database.
 *
 *   php tests/smoke/flows.php [base-url]
 *
 * Each scenario drives real HTTP requests (with CSRF tokens and cookies) and asserts on
 * responses and on the database. Test data uses unique emails and is removed at the end.
 */

require __DIR__ . '/bootstrap.php';

use App\Core\Database;

$base = BASE_URL;
$db = app(Database::class);
$run = bin2hex(random_bytes(3));
$pass = 0;
$fail = 0;
$createdUsers = [];
$createdEvents = [];
$oppId = 0;

// Everything this run creates is tagged and removed on shutdown, so a crash mid-run leaves nothing behind.
register_shutdown_function(static function () use (&$createdEvents, &$createdUsers, &$oppId, $db, $run): void {
    foreach ($createdEvents as $id) {
        $db->query('DELETE FROM events WHERE id = ?', [$id]);
    }
    $db->query('DELETE FROM volunteer_opportunities WHERE id = ?', [$oppId]);
    $db->query('DELETE FROM facility_reservations WHERE purpose LIKE ?', ["E2E % $run"]);
    $db->query('DELETE FROM contact_messages WHERE subject = ?', ['E2E contact ' . $run]);
    foreach ($createdUsers as $id) {
        $db->query('DELETE FROM feedback WHERE user_id = ?', [$id]);
        $db->query('DELETE FROM users WHERE id = ?', [$id]);
    }
    $db->query('DELETE FROM rate_limits');
});

function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        fwrite(STDOUT, "  ✔ $label\n");
    } else {
        $fail++;
        fwrite(STDOUT, "  ✘ $label\n");
    }
}

function flashText(array $res): string
{
    return preg_match('#<div class="alert[^"]*"[^>]*>.*?<div>(.*?)</div>#s', $res['body'], $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
}

function section(string $title): void
{
    fwrite(STDOUT, "\n$title\n");
}

$sid = (string) random_int(700000000, 799999999);
$email = "e2e.$run@college.test";
$password = 'Passw0rd!' . $run;

// ------------------------------------------------------------------ Registration
section('Registration');
$c = new HttpClient($base);
$c->get('/lang/en');
$res = $c->post('/register', [
    'full_name' => 'E2E Student', 'student_id' => $sid, 'email' => $email, 'phone' => '0551112233',
    'password' => $password, 'password_confirmation' => $password,
], true, $base . '/register');
ok($res['status'] === 303 && str_ends_with($res['headers']['location'] ?? '', '/student/dashboard'), 'valid registration signs in and redirects to dashboard');
$userId = (int) $db->value('SELECT id FROM users WHERE email = ?', [$email]);
$createdUsers[] = $userId;
$hash = (string) $db->value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
ok(str_starts_with($hash, '$argon2id$') && !str_contains($hash, $password), 'password stored as Argon2id hash, never plaintext');
ok((int) $db->value('SELECT role_id FROM users WHERE id = ?', [$userId]) === 1, 'self-registration always creates a STUDENT');

$c2 = new HttpClient($base);
$c2->get('/lang/en');
$res = $c2->post('/register', [
    'full_name' => 'Dup', 'student_id' => $sid, 'email' => $email, 'phone' => '0551112233',
    'password' => $password, 'password_confirmation' => $password . 'x',
], true, $base . '/register');
$page = $c2->get('/register')['body'];
ok(str_contains($page, 'This email is already in use') && str_contains($page, 'This student ID is already in use'), 'duplicate email and student ID rejected with field messages');
ok(str_contains($page, 'The password confirmation doesn') , 'password confirmation mismatch reported');
ok(!str_contains($page, $password), 'passwords are never echoed back into the form');

// ------------------------------------------------------------------ Login / role check
section('Login & role verification');
$g = new HttpClient($base);
$g->get('/lang/en');
$res = $g->post('/login', ['identifier' => $email, 'password' => $password, 'role' => 'ADMIN'], true, $base . '/login');
$page = $g->get('/login')['body'];
ok(str_contains($page, 'doesn&#039;t have admin access') && $g->get('/admin/dashboard')['status'] === 303, 'student choosing “Admin” is rejected and gets no admin access');

$res = $g->post('/login', ['identifier' => $email, 'password' => 'wrong-password', 'role' => 'STUDENT'], true, $base . '/login');
$page = $g->get('/login')['body'];
ok(str_contains($page, 'Email/student ID or password is incorrect'), 'wrong password gives a generic error');
$res = $g->post('/login', ['identifier' => 'nobody.' . $run . '@college.test', 'password' => 'whatever1', 'role' => 'STUDENT'], true, $base . '/login');
$page = $g->get('/login')['body'];
ok(str_contains($page, 'Email/student ID or password is incorrect'), 'unknown account gives the same generic error (no enumeration)');

$s = new HttpClient($base);
$s->login($sid, $password, 'STUDENT'); // login by student ID
ok($s->get('/student/dashboard')['status'] === 200, 'login with student ID works');
ok($s->get('/admin/dashboard')['status'] === 403, 'student gets 403 on /admin/*');
ok($s->get('/admin/users')['status'] === 403, 'student gets 403 on /admin/users');

$a = new HttpClient($base);
$a->get('/lang/en');
$res = $a->post('/login', ['identifier' => ADMIN_EMAIL, 'password' => ADMIN_PASSWORD, 'role' => 'STUDENT'], true, $base . '/login');
ok(str_contains($a->get('/login')['body'], 'This is an admin account'), 'admin choosing “Student” is told to pick Admin');

// CSRF
$res = $s->post('/student/feedback', ['type' => 'SUGGESTION', 'category_id' => 1, 'subject' => 'x', 'message' => 'y', '_token' => str_repeat('0', 64)]);
ok($res['status'] === 419, 'POST with a bad CSRF token is rejected (419)');

// ------------------------------------------------------------------ Login throttling
section('Login rate limiting');
$t = new HttpClient($base);
$t->get('/lang/en');
$victim = "throttle.$run@college.test";
for ($i = 0; $i < 5; $i++) {
    $t->post('/login', ['identifier' => $victim, 'password' => 'bad-pass-1', 'role' => 'STUDENT'], true, $base . '/login');
}
$t->post('/login', ['identifier' => $victim, 'password' => 'bad-pass-1', 'role' => 'STUDENT'], true, $base . '/login');
ok(str_contains($t->get('/login')['body'], 'Too many login attempts'), 'account+IP locked after 5 failed attempts');
$db->query('DELETE FROM rate_limits'); // don't affect later scenarios

// ------------------------------------------------------------------ Remember me
section('Remember me');
$r = new HttpClient($base);
$r->login($email, $password, 'STUDENT', true);
$cookie = $r->cookie('tvtc_remember');
ok($cookie !== null && preg_match('/^[a-f0-9]{24}%3A[a-f0-9]{64}$|^[a-f0-9]{24}:[a-f0-9]{64}$/', $cookie) === 1, 'remember cookie issued as selector:validator');
[$selector, $validator] = explode(':', urldecode((string) $cookie));
$row = $db->fetch('SELECT validator_hash FROM auth_remember_tokens WHERE selector = ?', [$selector]);
ok($row !== null && $row['validator_hash'] === hash('sha256', $validator) && $row['validator_hash'] !== $validator, 'DB stores only sha256(validator)');
$r->keepOnlyCookies(['tvtc_remember', 'lang']); // simulate closing the browser
ok($r->get('/student/dashboard')['status'] === 200, 'session restored from remember cookie after browser restart');
$rotated = urldecode((string) $r->cookie('tvtc_remember'));
ok($rotated !== urldecode((string) $cookie) && str_starts_with($rotated, $selector . ':'), 'validator rotated on use');
// Another tab sending the just-rotated cookie a moment later is not mistaken for theft.
$tab = new HttpClient($base);
$tab->setOnlyCookie('tvtc_remember', urlencode(urldecode((string) $cookie)));
ok($tab->get('/student/dashboard')['status'] === 200 && (int) $db->value('SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id = ?', [$userId]) === 1,
    'the previous cookie is accepted for ' . App\Services\Auth\RememberMeService::GRACE_SECONDS . ' s after rotation (parallel tabs)');
// Replay the OLD cookie later (after the grace window) → theft detection revokes all tokens.
$db->query('UPDATE auth_remember_tokens SET rotated_at = NOW() - INTERVAL 1 HOUR WHERE selector = ?', [$selector]);
$thief = new HttpClient($base);
$thief->get('/lang/en');
$thief->setOnlyCookie('tvtc_remember', urlencode(urldecode((string) $cookie)));
ok($thief->get('/student/dashboard')['status'] === 303, 'replayed (stolen) old cookie is rejected');
ok((int) $db->value('SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id = ?', [$userId]) === 0, 'replay revokes all remember tokens of that user');
$n = new HttpClient($base);
$n->login($email, $password, 'STUDENT', false);
ok($n->cookie('tvtc_remember') === null, 'no remember cookie when “Remember me” is unchecked');

// ------------------------------------------------------------------ Password reset
section('Forgot / reset password');
$f = new HttpClient($base);
$f->get('/lang/en');
$f->post('/forgot-password', ['email' => $email], true, $base . '/forgot-password');
$sentPage = $f->get('/forgot-password')['body'];
$f->post('/forgot-password', ['email' => "unknown.$run@college.test"], true, $base . '/forgot-password');
$unknownPage = $f->get('/forgot-password')['body'];
ok(str_contains($sentPage, 'If this email is registered') && str_contains($unknownPage, 'If this email is registered'), 'same response for known and unknown emails');
// The email is queued and sent right after the response (L-8): wait briefly for it.
$link = null;
for ($try = 0; $try < 50 && $link === null; $try++) {
    usleep(100000);
    $mailFiles = glob(dirname(__DIR__, 2) . '/storage/mail/*.json') ?: [];
    rsort($mailFiles);
    foreach ($mailFiles as $file) {
        $meta = json_decode((string) file_get_contents($file), true);
        if (($meta['to'] ?? '') === $email && preg_match('#/reset-password/([a-f0-9]{64})#', (string) $meta['text'], $m)) {
            $link = $m[1];
            break;
        }
    }
}
$tokenRow = $db->fetch('SELECT token_hash, expires_at FROM password_reset_tokens WHERE user_id = ?', [$userId]);
ok($tokenRow !== null && strlen((string) $tokenRow['token_hash']) === 64, 'reset token stored hashed with expiry');
ok((int) $db->value('SELECT COUNT(*) FROM mail_queue WHERE user_id = ?', [$userId]) === 0, 'the queued reset email was sent after the response (queue empty)');
ok($link !== null && hash('sha256', $link) === $tokenRow['token_hash'], 'email (log driver) contains the token; DB holds only its hash');
ok(str_contains((string) ($meta['text'] ?? ''), (string) config('app.url')), 'reset link built from APP_URL, not the Host header');
$resetPage = $f->get('/reset-password/' . $link);
ok($resetPage['status'] === 200 && ($resetPage['headers']['referrer-policy'] ?? '') === 'no-referrer', 'reset page opens with Referrer-Policy: no-referrer');
$newPassword = 'N3wPassword' . $run;
// The reset page sends no Referer (no-referrer policy): a validation error must come back to the same page, not home.
$res = $f->post('/reset-password/' . $link, ['password' => $newPassword, 'password_confirmation' => $newPassword . 'x']);
ok($res['status'] === 303 && str_ends_with($res['headers']['location'] ?? '', '/reset-password/' . $link)
    && str_contains($f->get('/reset-password/' . $link)['body'], 'The password confirmation doesn'), 'a reset-form error without Referer returns to the reset page with the message');
$res = $f->post('/reset-password/' . $link, ['password' => $newPassword, 'password_confirmation' => $newPassword], true, $base . '/forgot-password');
ok($res['status'] === 303 && str_ends_with($res['headers']['location'] ?? '', '/login'), 'valid token resets the password');
ok($n->get('/student/dashboard')['status'] === 303, 'existing sessions are logged out after the reset');
$f->get('/lang/en');
ok(str_contains($f->get('/reset-password/' . $link)['body'], 'already been used'), 'token is single-use');
$password = $newPassword;
$s = new HttpClient($base);
$s->login($email, $password, 'STUDENT');

// ------------------------------------------------------------------ Events
section('Events: registration rules');
$adminId = (int) $db->value("SELECT id FROM users WHERE email = ?", [ADMIN_EMAIL]);
$typeId = (int) $db->value("SELECT id FROM event_types WHERE code = 'workshop'");
$mk = static function (string $start, string $end, int $capacity, bool $cancelled = false) use ($db, $typeId, $adminId, &$createdEvents): int {
    $id = $db->insert('events', [
        'title_ar' => 'اختبار', 'title_en' => 'E2E test', 'description_ar' => 'x', 'description_en' => 'x',
        'event_type_id' => $typeId, 'location_ar' => 'قاعة', 'location_en' => 'Hall',
        'start_datetime' => date('Y-m-d H:i:s', strtotime($start)), 'end_datetime' => date('Y-m-d H:i:s', strtotime($end)),
        'capacity' => $capacity, 'created_by' => $adminId, 'cancelled_at' => $cancelled ? date('Y-m-d H:i:s') : null,
    ]);
    $createdEvents[] = $id;

    return $id;
};
$ev = $mk('+3 days 10:00', '+3 days 12:00', 1);
$s->get('/lang/en');
$res = $s->post("/events/$ev/register", [], true, "$base/events/$ev");
ok($res['status'] === 303 && (int) $db->value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND user_id = ? AND status = 'REGISTERED'", [$ev, $userId]) === 1, 'student registers for an upcoming event');
$page = $s->get("/events/$ev")['body'];
ok(str_contains($page, '1 / 1') && str_contains($page, 'registered'), 'capacity shows real count “1 / 1”');
$s->post("/events/$ev/register", [], true, "$base/events/$ev");
ok(str_contains($s->get("/events/$ev")['body'], 'already registered') || (int) $db->value('SELECT COUNT(*) FROM event_registrations WHERE event_id = ?', [$ev]) === 1, 'duplicate registration prevented');

$other = new HttpClient($base);
$other->login('student2@college.test', STUDENT_PASSWORD, 'STUDENT');
$other->get('/lang/en');
$other->post("/events/$ev/register", [], true, "$base/events/$ev");
ok(str_contains($other->get("/events/$ev")['body'], 'This event is full'), 'registration refused when the event is full');

$past = $mk('-3 days 10:00', '-3 days 12:00', 50);
$other->post("/events/$past/register", [], true, "$base/events/$past");
ok(str_contains($other->get("/events/$past")['body'], 'this event has ended'), 'registration refused after the event completed');
$cancelled = $mk('+5 days 10:00', '+5 days 12:00', 50, true);
$other->post("/events/$cancelled/register", [], true, "$base/events/$cancelled");
ok(str_contains($other->get("/events/$cancelled")['body'], 'this event was cancelled'), 'registration refused for a cancelled event');
$ongoing = $mk('-1 hours', '+1 hours', 50);
$row = $db->fetch('SELECT ' . App\Domain\ActivityStatus::sql('e') . ' AS s FROM events e WHERE id = ?', [$ongoing]);
ok($row['s'] === 'ONGOING', 'status auto-calculated as ONGOING between start and end');

$s->post("/events/$ev/unregister", [], true, "$base/student/registrations");
ok($db->value('SELECT status FROM event_registrations WHERE event_id = ? AND user_id = ?', [$ev, $userId]) === 'CANCELLED', 'student can cancel an upcoming registration');
$s->post("/events/$ev/register", [], true, "$base/events/$ev");
ok($db->value('SELECT status FROM event_registrations WHERE event_id = ? AND user_id = ?', [$ev, $userId]) === 'REGISTERED', 'cancelled registration can be re-activated');

// Admin CRUD + cancel override
section('Admin: event management');
$a = new HttpClient($base);
$a->login(ADMIN_EMAIL, ADMIN_PASSWORD, 'ADMIN');
$a->get('/lang/en');
$res = $a->post('/admin/events', [
    'title_ar' => 'ورشة اختبار', 'title_en' => 'Test workshop ' . $run, 'description_ar' => 'وصف', 'description_en' => 'Description',
    'location_ar' => 'معمل 1', 'location_en' => 'Lab 1', 'event_type_id' => $typeId,
    'start_datetime' => date('Y-m-d\TH:i', strtotime('+10 days 09:00')), 'end_datetime' => date('Y-m-d\TH:i', strtotime('+10 days 11:00')),
    'capacity' => 30,
], true, "$base/admin/events/create");
$newId = (int) $db->value('SELECT id FROM events WHERE title_en = ?', ['Test workshop ' . $run]);
$createdEvents[] = $newId;
ok($res['status'] === 303 && $newId > 0, 'admin creates an event');
$a->post('/admin/events', [
    'title_ar' => 'x', 'title_en' => 'y', 'description_ar' => 'x', 'description_en' => 'y', 'location_ar' => 'x', 'location_en' => 'y',
    'event_type_id' => $typeId, 'start_datetime' => date('Y-m-d\TH:i', strtotime('+2 days 10:00')), 'end_datetime' => date('Y-m-d\TH:i', strtotime('+2 days 09:00')), 'capacity' => 0,
], true, "$base/admin/events/create");
$formPage = $a->get('/admin/events/create')['body'];
ok(str_contains($formPage, 'must be after the start time') && str_contains($formPage, 'capacity must be at least 1'), 'end-before-start and zero capacity are rejected');
$res = $a->post("/admin/events/$newId", [
    'title_ar' => 'ورشة اختبار', 'title_en' => 'Test workshop ' . $run, 'description_ar' => 'وصف', 'description_en' => 'Description',
    'location_ar' => 'معمل 2', 'location_en' => 'Lab 2', 'event_type_id' => $typeId,
    'start_datetime' => date('Y-m-d\TH:i', strtotime('+10 days 09:00')), 'end_datetime' => date('Y-m-d\TH:i', strtotime('+10 days 12:00')), 'capacity' => 40,
], true, "$base/admin/events/$newId/edit");
ok($db->value('SELECT location_en FROM events WHERE id = ?', [$newId]) === 'Lab 2', 'admin edits an event');
$s->post("/events/$newId/register", [], true, "$base/events/$newId");
$a->post("/admin/events/$newId/cancel", ['reason' => 'Trainer unavailable'], true, "$base/admin/events/$newId");
$row = $db->fetch('SELECT ' . App\Domain\ActivityStatus::sql('e') . ' AS s FROM events e WHERE id = ?', [$newId]);
ok($row['s'] === 'CANCELLED', 'cancel overrides the automatic status');
ok((int) $db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'event_cancelled'", [$userId]) >= 1, 'registrants are notified of the cancellation');
$a->post("/admin/events/$newId/delete", [], true, "$base/admin/events/$newId");
ok((int) $db->value('SELECT COUNT(*) FROM events WHERE id = ?', [$newId]) === 1, 'event with registrations cannot be deleted');
$empty = $mk('+20 days 10:00', '+20 days 11:00', 10);
$a->post("/admin/events/$empty/delete", [], true, "$base/admin/events/$empty");
ok((int) $db->value('SELECT COUNT(*) FROM events WHERE id = ?', [$empty]) === 0, 'event without registrations can be deleted');

// ------------------------------------------------------------------ Volunteering hours
section('Volunteering & hours');
$catId = (int) $db->value("SELECT id FROM volunteer_categories WHERE code = 'design'");
$oppId = $db->insert('volunteer_opportunities', [
    'category_id' => $catId, 'title_ar' => 'تطوع اختبار', 'title_en' => 'E2E volunteering', 'description_ar' => 'x', 'description_en' => 'x',
    'location_ar' => 'x', 'location_en' => 'x', 'start_datetime' => date('Y-m-d H:i:s', strtotime('+1 day')),
    'end_datetime' => date('Y-m-d H:i:s', strtotime('+1 day +3 hours')), 'volunteer_hours' => 3, 'capacity' => 5, 'created_by' => $adminId,
]);
$hoursBefore = (float) $db->value("SELECT COALESCE(SUM(hours_awarded),0) FROM volunteer_registrations WHERE user_id = ? AND status = 'COMPLETED'", [$userId]);
$s->post("/volunteering/$oppId/register", ['motivation' => 'I like design'], true, "$base/volunteering/$oppId");
$regId = (int) $db->value('SELECT id FROM volunteer_registrations WHERE opportunity_id = ? AND user_id = ?', [$oppId, $userId]);
ok($regId > 0, 'student signs up for an opportunity');
ok((float) $db->value("SELECT COALESCE(SUM(hours_awarded),0) FROM volunteer_registrations WHERE user_id = ? AND status = 'COMPLETED'", [$userId]) === $hoursBefore, 'registering alone awards no hours');
$a->post("/admin/volunteering/registrations/$regId/complete", ['hours' => 3], true, "$base/admin/volunteering/$oppId");
ok($db->value('SELECT status FROM volunteer_registrations WHERE id = ?', [$regId]) === 'REGISTERED', 'completion cannot be approved before the opportunity starts');
$db->query('UPDATE volunteer_opportunities SET start_datetime = NOW() - INTERVAL 4 HOUR, end_datetime = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$oppId]);
$a->post("/admin/volunteering/registrations/$regId/complete", ['hours' => 2.5], true, "$base/admin/volunteering/$oppId");
ok($db->value('SELECT status FROM volunteer_registrations WHERE id = ?', [$regId]) === 'COMPLETED', 'admin approves completion');
$s->get('/lang/en');
$dash = $s->get('/student/dashboard')['body'];
ok(str_contains($dash, '2.5 hours'), 'student dashboard shows awarded hours (2.5 hours)');

// ------------------------------------------------------------------ Reservations
section('Facility reservations');
$facilityId = (int) $db->value("SELECT id FROM facilities WHERE code = 'theater'");
$day = date('Y-m-d', strtotime('+40 days'));
$res = $s->post('/student/reservations', ['facility_id' => $facilityId, 'date' => $day, 'start_time' => '10:00', 'end_time' => '12:00', 'purpose' => 'E2E rehearsal ' . $run, 'expected_attendees' => 20], true, "$base/student/reservations/new");
$r1 = (int) $db->value('SELECT id FROM facility_reservations WHERE purpose = ?', ['E2E rehearsal ' . $run]);
ok($r1 > 0 && $db->value('SELECT status FROM facility_reservations WHERE id = ?', [$r1]) === 'PENDING', 'student submits a reservation request (PENDING)');
$other->post('/student/reservations', ['facility_id' => $facilityId, 'date' => $day, 'start_time' => '11:00', 'end_time' => '13:00', 'purpose' => 'E2E overlap ' . $run], true, "$base/student/reservations/new");
$r2 = (int) $db->value('SELECT id FROM facility_reservations WHERE purpose = ?', ['E2E overlap ' . $run]);
ok($r2 > 0, 'overlapping PENDING request is accepted for review (only APPROVED blocks time)');
$a->post("/admin/reservations/$r1/approve", [], true, "$base/admin/reservations");
ok($db->value('SELECT status FROM facility_reservations WHERE id = ?', [$r1]) === 'APPROVED', 'admin approves the first request');
$a->post("/admin/reservations/$r2/approve", [], true, "$base/admin/reservations");
ok($db->value('SELECT status FROM facility_reservations WHERE id = ?', [$r2]) === 'PENDING', 'conflicting request cannot be approved');
$other->get('/lang/en');
$other->post('/student/reservations', ['facility_id' => $facilityId, 'date' => $day, 'start_time' => '11:30', 'end_time' => '12:30', 'purpose' => 'E2E conflict ' . $run], true, "$base/student/reservations/new");
ok(str_contains($other->get('/student/reservations/new')['body'], 'already booked at that time'), 'new request over an approved slot is refused');
$a->post("/admin/reservations/$r2/reject", ['admin_note' => 'Slot taken'], true, "$base/admin/reservations");
ok($db->value('SELECT status FROM facility_reservations WHERE id = ?', [$r2]) === 'REJECTED', 'admin rejects with a reason');
$s->post("/student/reservations/$r2/cancel", [], true, "$base/student/reservations");
ok($db->value('SELECT status FROM facility_reservations WHERE id = ?', [$r2]) === 'REJECTED', "a student cannot cancel someone else's reservation");
$s->post('/student/reservations', ['facility_id' => $facilityId, 'date' => date('Y-m-d', strtotime('-1 day')), 'start_time' => '10:00', 'end_time' => '11:00', 'purpose' => 'E2E past ' . $run], true, "$base/student/reservations/new");
ok((int) $db->value('SELECT COUNT(*) FROM facility_reservations WHERE purpose = ?', ['E2E past ' . $run]) === 0, 'reservations in the past are refused');

// ------------------------------------------------------------------ Feedback & contact
section('Suggestions, complaints, contact');
foreach (['SUGGESTION', 'COMPLAINT'] as $type) {
    $s->post('/student/feedback', ['type' => $type, 'category_id' => 1, 'subject' => "E2E $type $run", 'message' => 'A detailed message for testing.'], true, "$base/student/feedback/new");
}
ok((int) $db->value("SELECT COUNT(*) FROM feedback WHERE subject LIKE ? AND user_id = ?", ["E2E %$run", $userId]) === 2, 'student submits a suggestion and a complaint');
$fid = (int) $db->value('SELECT id FROM feedback WHERE subject = ?', ["E2E COMPLAINT $run"]);
ok($other->get("/student/feedback/$fid")['status'] === 404, "another student cannot open this student's complaint");
$a->post("/admin/feedback/$fid/reply", ['admin_reply' => 'We are on it.', 'status' => 'IN_PROGRESS'], true, "$base/admin/feedback/$fid");
ok($db->value('SELECT status FROM feedback WHERE id = ?', [$fid]) === 'IN_PROGRESS' && str_contains($s->get("/student/feedback/$fid")['body'], 'We are on it.'), 'admin replies; the student sees the reply');
$longMessage = str_repeat('طويل ', 300);
$guest = new HttpClient($base);
$guest->post('/contact', ['name' => 'Guest', 'email' => "guest.$run@example.com", 'subject' => 'E2E contact ' . $run, 'message' => $longMessage], true, "$base/contact");
ok(mb_strlen((string) $db->value('SELECT message FROM contact_messages WHERE subject = ?', ['E2E contact ' . $run])) === mb_strlen(trim($longMessage)), 'long contact messages are stored in full (legacy truncation fixed)');

// ------------------------------------------------------------------ Admin protection
section('Admin self-protection');
$a->post("/admin/users/$adminId/role", ['role' => 'STUDENT'], true, "$base/admin/users");
ok((int) $db->value('SELECT role_id FROM users WHERE id = ?', [$adminId]) === 2, 'admin cannot demote themselves');
$a->post("/admin/users/$adminId/active", ['active' => '0'], true, "$base/admin/users");
ok((int) $db->value('SELECT is_active FROM users WHERE id = ?', [$adminId]) === 1, 'admin cannot deactivate themselves');
$a->post("/admin/users/$userId/active", ['active' => '0'], true, "$base/admin/users");
ok($s->get('/student/dashboard')['status'] === 303, 'deactivated user is signed out immediately');
$a->post("/admin/users/$userId/active", ['active' => '1'], true, "$base/admin/users");

// ------------------------------------------------------------------ Pagination / search / i18n
section('Search, pagination, language');
$p = new HttpClient($base);
$p->get('/lang/en');
$page = $p->get('/events?limit=5&page=1')['body'];
ok(substr_count($page, 'class="activity-card"') === 5 && str_contains($page, 'Showing 1–5 of'), 'server-side pagination (?page=1&limit=5)');
ok(substr_count($p->get('/events?limit=500')['body'], 'class="activity-card"') <= 50, 'limit is capped (no unlimited loads)');
ok(str_contains($p->get('/events?q=Cybersecurity')['body'], 'Cybersecurity Fundamentals'), 'search finds events by title');
$p->get('/lang/ar');
$ar = $p->get('/events/3')['body'];
ok(str_contains($ar, 'dir="rtl"') && preg_match('/(صباحًا|مساءً)/u', $ar) === 1, 'Arabic page is RTL with Arabic time markers');
ok($p->cookie('lang') === 'ar' && str_contains($p->get('/')['body'], 'lang="ar"'), 'language choice persists across requests');

// ------------------------------------------------------------------ Card media, contact details, date filters
section('Card media, contact details, date filters');
$png = app()->basePath('public/assets/img/feedback.png');
$g = new HttpClient($base);
$eventsPage = $g->get('/events?limit=50')['body'];
ok(substr_count($eventsPage, 'class="activity-card"') === substr_count($eventsPage, '<img class="cover"')
    && !str_contains($eventsPage, 'assets/img/facilities/'), 'every event card has a cover, none borrowed from a venue');
$sameType = $db->fetchAll("SELECT e.id FROM events e JOIN event_types t ON t.id = e.event_type_id WHERE e.image_path IS NULL AND t.code = 'exhibition' ORDER BY e.id LIMIT 2");
if (count($sameType) === 2) {
    $cover = static fn (int $id): string => preg_match('#<img class="detail-cover mb-4" src="[^"]*/(assets/img/defaults/events/[a-z-]+-\d+\.jpg)#', $g->get("/events/$id")['body'], $m) === 1 ? $m[1] : '';
    ok($cover((int) $sameType[0]['id']) !== '' && $cover((int) $sameType[0]['id']) !== $cover((int) $sameType[1]['id']), 'two events of the same type get different default photos');
}
$volPage = $g->get('/volunteering?limit=50')['body'];
ok(substr_count($volPage, 'class="activity-card"') > 0
    && substr_count($volPage, 'class="activity-card"') === substr_count($volPage, 'assets/img/defaults/volunteering/') + substr_count($volPage, 'uploads/volunteering/'),
    'every volunteering card has an image (upload or category default)');

// Volunteering cover: upload, shown publicly, then removed back to the default.
$opp = $db->fetch('SELECT * FROM volunteer_opportunities WHERE id = ?', [$oppId]);
$oppFields = array_intersect_key($opp, array_flip(['title_ar', 'title_en', 'description_ar', 'description_en', 'location_ar', 'location_en', 'category_id', 'capacity', 'volunteer_hours']))
    + ['start_datetime' => date('Y-m-d\TH:i', strtotime((string) $opp['start_datetime'])), 'end_datetime' => date('Y-m-d\TH:i', strtotime((string) $opp['end_datetime']))];
$a->post("/admin/volunteering/$oppId", $oppFields + ['image' => new CURLFile($png, 'image/png', 'cover.png')], true, "$base/admin/volunteering/$oppId/edit");
$oppImage = (string) $db->value('SELECT image_path FROM volunteer_opportunities WHERE id = ?', [$oppId]);
ok(preg_match('#^uploads/volunteering/[a-f0-9]{32}\.png$#', $oppImage) === 1 && str_contains($g->get("/volunteering/$oppId")['body'], $oppImage), 'admin uploads a volunteering cover and it is shown');
$a->post("/admin/volunteering/$oppId", $oppFields + ['remove_image' => '1'], true, "$base/admin/volunteering/$oppId/edit");
ok($db->value('SELECT image_path FROM volunteer_opportunities WHERE id = ?', [$oppId]) === null && !is_file(app()->basePath('public/' . $oppImage))
    && str_contains($g->get("/volunteering/$oppId")['body'], 'assets/img/defaults/volunteering/'), 'removing it deletes the file and restores the category default');

// Facility photo: replaceable from the admin; the bundled original is restored afterwards.
$facility = $db->fetch('SELECT * FROM facilities ORDER BY id LIMIT 1');
$facilityFields = array_intersect_key($facility, array_flip(['code', 'name_ar', 'name_en', 'description_ar', 'description_en', 'location_ar', 'location_en', 'capacity'])) + ['is_active' => '1'];
$a->post('/admin/facilities/' . $facility['id'], $facilityFields + ['image' => new CURLFile($png, 'image/png', 'hall.png')], true, "$base/admin/facilities/{$facility['id']}/edit");
$facilityImage = (string) $db->value('SELECT image_path FROM facilities WHERE id = ?', [$facility['id']]);
ok(str_starts_with($facilityImage, 'uploads/facilities/') && str_contains($g->get('/facilities')['body'], $facilityImage), 'admin replaces a facility photo');
ok(is_file(app()->basePath('public/' . $facility['image_path'])), 'the bundled original photo is never deleted');
@unlink(app()->basePath('public/' . $facilityImage));
$db->update('facilities', ['image_path' => $facility['image_path']], ['id' => $facility['id']]);

// About photo + optional address, through Admin › Settings.
$settingsBefore = $db->fetchAll('SELECT `key`, value FROM settings');
$settingsForm = array_column($settingsBefore, 'value', 'key');
$settingsForm = array_intersect_key($settingsForm, \App\Services\SettingsService::EDITABLE);
$a->post('/admin/settings', ['address_ar' => '', 'address_en' => ''] + $settingsForm + ['about_image' => new CURLFile($png, 'image/png', 'campus.png')], true, "$base/admin/settings");
$aboutImage = (string) $db->value("SELECT value FROM settings WHERE `key` = 'about_image'");
ok(str_starts_with($aboutImage, 'uploads/site/') && str_contains($g->get('/about')['body'], $aboutImage), 'the About photo is uploaded from Settings and shown');
$contact = $g->get('/contact')['body'];
ok(!str_contains($contact, 'bi-geo-alt" aria-hidden="true"></i><span><span class="k">') && !str_contains($contact, 'يُحدَّث العنوان')
    && !str_contains($contact, 'google.com/maps'), 'an empty address hides the address line and the map');
ok(preg_match('#<a href="tel:\+\d+" dir="ltr">#', $contact) === 1, 'phone numbers render LTR');
$a->post('/admin/settings', ['address_ar' => 'الرياض', 'address_en' => 'Riyadh <b>'] + $settingsForm + ['remove_about_image' => '1'], true, "$base/admin/settings");
$g->get('/lang/en');
$mapPage = $g->get('/contact');
ok(str_contains($mapPage['body'], '<iframe class="contact-map" src="https://www.google.com/maps?q=Riyadh+%3Cb%3E&amp;hl=en&amp;output=embed"')
    && str_contains((string) ($mapPage['headers']['content-security-policy'] ?? ''), 'frame-src https://www.google.com;'), 'a set address shows an encoded Google Maps frame allowed by the CSP');
$g->get('/lang/ar');
ok(str_contains($g->get('/about')['body'], 'assets/img/defaults/about.jpg') && !is_file(app()->basePath('public/' . $aboutImage)), 'removing it restores the default About photo');
foreach ($settingsBefore as $row) {
    $db->query('UPDATE settings SET value = ? WHERE `key` = ?', [$row['value'], $row['key']]);
}

// Date filters still submit Y-m-d and filter correctly.
$day = (string) $db->value('SELECT DATE(start_datetime) FROM events WHERE cancelled_at IS NULL AND start_datetime > NOW() ORDER BY start_datetime LIMIT 1');
$filtered = $g->get("/events?from=$day&to=$day")['body'];
$expected = (int) $db->value('SELECT COUNT(*) FROM events WHERE start_datetime <= ? AND end_datetime >= ?', ["$day 23:59:59", "$day 00:00:00"]);
ok(substr_count($filtered, 'class="activity-card"') === $expected && $expected > 0, 'from/to date filter returns exactly the events on that day');
ok(preg_match('#type="date"[^>]*name="from" value="' . $day . '"[^>]*data-datepicker#', $filtered) === 1, 'the chosen date is kept in the date-picker field');

// ------------------------------------------------------------------ Email change by link
section('Email change by link');
$db->query('DELETE FROM rate_limits');
$s = new HttpClient($base); // the earlier deactivate/reactivate check ended the previous session
$s->login($email, $password, 'STUDENT');
$s->get('/lang/en');
$other = new HttpClient($base);
$other->login($email, $password, 'STUDENT'); // a second device of the same user
$newEmail = "e2e.moved.$run@college.test";
$profileFields = ['full_name' => 'E2E Student', 'phone' => '0551112233', 'preferred_locale' => 'en'];
$s->post('/profile', $profileFields + ['email' => $newEmail, 'email_password' => $password], true, "$base/profile");
$profilePage = $s->get('/profile')['body'];
ok(str_contains($profilePage, 'open the confirmation link') && str_contains($profilePage, $newEmail)
    && $db->value('SELECT email FROM users WHERE id = ?', [$userId]) === $email, 'saving a new email only sends a link; the login email is unchanged');
$confirmToken = null;
foreach (array_reverse(glob(dirname(__DIR__, 2) . '/storage/mail/*.json') ?: []) as $file) {
    $meta = json_decode((string) file_get_contents($file), true);
    if (($meta['to'] ?? '') === $newEmail && preg_match('#/profile/email/confirm/([a-f0-9]{64})#', (string) $meta['text'], $m)) {
        $confirmToken = $m[1];
        break;
    }
}
ok($confirmToken !== null && hash('sha256', $confirmToken) === $db->value('SELECT token_hash FROM email_change_requests WHERE user_id = ?', [$userId]), 'the link goes to the NEW address; the DB holds only its hash');
ok((new HttpClient($base))->get("/profile/email/confirm/$confirmToken")['status'] === 303, 'the link requires signing in');
$confirmPage = $s->get("/profile/email/confirm/$confirmToken");
ok($confirmPage['status'] === 200 && ($confirmPage['headers']['referrer-policy'] ?? '') === 'no-referrer' && str_contains($confirmPage['body'], $newEmail)
    && $db->value('SELECT email FROM users WHERE id = ?', [$userId]) === $email, 'opening the link (GET) shows a confirm button and changes nothing');
$res = $s->post("/profile/email/confirm/$confirmToken", []);
ok($res['status'] === 303 && $db->value('SELECT email FROM users WHERE id = ?', [$userId]) === $newEmail, 'confirming (POST) changes the login email');
ok($other->get('/student/dashboard')['status'] === 303 && $s->get('/student/dashboard')['status'] === 200, 'the other device is signed out; this one stays signed in');
$warned = false;
foreach (array_reverse(glob(dirname(__DIR__, 2) . '/storage/mail/*.json') ?: []) as $file) {
    $meta = json_decode((string) file_get_contents($file), true);
    if (($meta['to'] ?? '') === $email && str_contains((string) ($meta['subject'] ?? ''), 'login email was changed')) {
        $warned = !str_contains((string) $meta['text'], $newEmail);
        break;
    }
}
ok($warned, 'the old address is warned, with the new address masked');
$s->post("/profile/email/confirm/$confirmToken", []);
ok(str_contains($s->get('/profile')['body'], 'invalid, has expired or was already used'), 'the link is single-use');
$email = $newEmail;
(new HttpClient($base))->login($email, $password, 'STUDENT');
ok(true, 'login works with the confirmed address');

// Cleanup runs in the shutdown function registered at the top.
fwrite(STDOUT, "\n$pass passed, $fail failed.\n");
exit($fail === 0 ? 0 : 1);

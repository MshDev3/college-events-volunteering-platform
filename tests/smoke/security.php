<?php

declare(strict_types=1);

/**
 * Automated security checks against the running site (HTTP) + database.
 *
 *   php tests/smoke/security.php [base-url]
 *
 * Requires the local site with demo data (DEMO_* credentials in .env). Every record it creates is
 * tagged and removed at the end.
 */

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Router;

$base = BASE_URL;
$db = app(Database::class);
$tag = 'SEC' . bin2hex(random_bytes(3));
$pass = 0;
$fail = 0;
$cookieFile = static fn (HttpClient $c): string => (string) (new ReflectionProperty(HttpClient::class, 'cookieFile'))->getValue($c);
$setCookie = static fn (HttpClient $c, string $name, string $value) => $c->setOnlyCookie($name, $value);

function check(bool $cond, string $label): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    fwrite(STDOUT, ($cond ? '  ✔ ' : '  ✘ ') . $label . PHP_EOL);
}

function section(string $t): void
{
    fwrite(STDOUT, PHP_EOL . $t . PHP_EOL);
}

$cleanup = static function () use ($db, $tag): void {
    $db->query('DELETE FROM events WHERE title_en LIKE ?', ["$tag%"]);
    $db->query('DELETE FROM contact_messages WHERE subject LIKE ?', ["$tag%"]);
    $ids = array_column($db->fetchAll('SELECT id FROM feedback WHERE subject LIKE ?', ["$tag%"]), 'id');
    foreach ($db->fetchAll('SELECT a.stored_name FROM feedback_attachments a JOIN feedback f ON f.id = a.feedback_id WHERE f.subject LIKE ?', ["$tag%"]) as $a) {
        @unlink(app()->basePath('storage/uploads/attachments/' . $a['stored_name']));
    }
    foreach ($ids as $id) {
        $db->query('DELETE FROM feedback WHERE id = ?', [$id]);
    }
    $db->query('DELETE FROM notifications WHERE data LIKE ?', ["%$tag%"]);
    $db->query('DELETE FROM password_reset_tokens WHERE requested_ip = ?', [$tag]);
    $db->query('DELETE FROM rate_limits');
};

// ------------------------------------------------------------------ Sessions & cookies
section('Sessions & cookies');
$c = new HttpClient($base);
$guestHeaders = $c->get('/login')['headers'];
$before = $c->cookie('tvtc_session');
$loginRes = $c->login(STUDENT_EMAIL, STUDENT_PASSWORD, 'STUDENT');
$after = $c->cookie('tvtc_session');
check($before !== null && $before !== $after, 'session id is regenerated at login');
$fixed = new HttpClient($base);
$setCookie($fixed, 'tvtc_session', (string) $before);
check($fixed->get('/student/dashboard')['status'] === 303, 'pre-login (fixated) session id grants no access');
$cookieHeader = (string) ($guestHeaders['set-cookie'] ?? '');
check(stripos($cookieHeader, 'HttpOnly') !== false && stripos($cookieHeader, 'SameSite=Lax') !== false, 'session cookie is HttpOnly + SameSite=Lax');
check(str_starts_with(BASE_URL, 'https://') || stripos($cookieHeader, 'secure') === false, 'Secure flag only when HTTPS/https APP_URL (plain-http localhost)');
$authId = (string) $c->cookie('tvtc_session');
check($c->get('/logout')['status'] === 405, 'logout is POST-only (no CSRF-able GET logout)');
$c->post('/logout', [], true, $base . '/');
$old = new HttpClient($base);
$setCookie($old, 'tvtc_session', $authId);
check($old->get('/student/dashboard')['status'] === 303, 'old session id is dead after logout');
$r = new HttpClient($base);
$res = $r->login(STUDENT_EMAIL, STUDENT_PASSWORD, 'STUDENT', true);
$remember = (string) ($res['headers']['set-cookie'] ?? '');
check(str_contains($remember, 'tvtc_remember=') && stripos($remember, 'HttpOnly') !== false && stripos($remember, 'SameSite=Lax') !== false, 'remember-me cookie is HttpOnly + SameSite=Lax');
$r->post('/logout', [], true, $base . '/');
$db->query('DELETE FROM rate_limits');

// ------------------------------------------------------------------ Route matrix: CSRF + role on every route
section('Route matrix (every route in config/routes.php)');
$routes = (new ReflectionProperty(Router::class, 'routes'))->getValue(app(Router::class));
$student = new HttpClient($base);
$student->login(STUDENT_EMAIL, STUDENT_PASSWORD, 'STUDENT');
$admin = new HttpClient($base);
$admin->login(ADMIN_EMAIL, ADMIN_PASSWORD, 'ADMIN');
$guest = new HttpClient($base);
$csrfFail = [];
$roleFail = [];
$posts = 0;
$adminRoutes = 0;
foreach ($routes as $route) {
    $path = str_replace(['(\d+)', '([A-Fa-f0-9]{64})', '([^/]+)'], ['1', str_repeat('a', 64), 'x'], trim($route['regex'], '#^$'));
    if (str_contains($path, '.php')) {
        continue; // legacy 301 redirects
    }
    $isAdmin = in_array('role:ADMIN', $route['middleware'], true);
    if ($route['method'] === 'GET') {
        if ($isAdmin) {
            $adminRoutes++;
            $st = $student->get($path)['status'];
            if ($st !== 403) {
                $roleFail[] = "GET $path → $st";
            }
        }
        continue;
    }
    $posts++;
    foreach (['guest' => $guest, 'student' => $student, 'admin' => $admin] as $who => $client) {
        $st = $client->request('POST', $path, ['x' => '1'])['status'];
        if ($st !== 419) {
            $csrfFail[] = "POST $path as $who → $st";
        }
    }
    if ($isAdmin) {
        $adminRoutes++;
        $st = $student->post($path, ['x' => '1'])['status'];
        if ($st !== 403) {
            $roleFail[] = "POST $path (valid CSRF) → $st";
        }
    }
}
check($csrfFail === [], "all $posts state-changing routes reject requests without a CSRF token (guest/student/admin)" . ($csrfFail ? ': ' . implode('; ', array_slice($csrfFail, 0, 3)) : ''));
check($roleFail === [], "a student gets 403 on all $adminRoutes admin routes (GET and POST)" . ($roleFail ? ': ' . implode('; ', array_slice($roleFail, 0, 3)) : ''));
check($guest->get('/admin/users')['status'] === 303, 'a guest is redirected to login from admin routes');

// ------------------------------------------------------------------ Dev mailbox
section('Development mailbox (/_dev/mail)');
$lanIp = null;
foreach (gethostbynamel(gethostname()) ?: [] as $ip) {
    if (!str_starts_with($ip, '127.')) {
        $lanIp = $ip;
        break;
    }
}
check($student->get('/_dev/mail')['status'] === (config('app.env') === 'local' ? 403 : 404), 'a student cannot open it');
check($admin->get('/_dev/mail')['status'] === (config('app.env') === 'local' ? 200 : 404), 'an admin on localhost can (local env only)');
if ($lanIp !== null) {
    $lan = new HttpClient(str_replace('://localhost', '://' . $lanIp, $base));
    if ($lan->get('/login')['status'] === 0) {
        // The web server itself only listens on 127.0.0.1 (recommended): nothing is reachable through the LAN.
        check(true, 'not reachable through the LAN IP: the web server does not listen on it');
    } else {
        $lan->login(ADMIN_EMAIL, ADMIN_PASSWORD, 'ADMIN');
        check($lan->get('/_dev/mail')['status'] === 404, 'not reachable through the LAN IP, even for an admin');
    }
}
check(in_array((new HttpClient($base))->get('/storage/mail/')['status'], [403, 404], true), 'mail files are not web-served');

// ------------------------------------------------------------------ Password reset tokens
section('Password reset tokens');
$uid = (int) $db->value("SELECT id FROM users WHERE email = 'student6@college.test'");
$hashBefore = (string) $db->value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
foreach (['expired' => ['-1 minute', null], 'used' => ['+30 minutes', date('Y-m-d H:i:s')]] as $kind => [$expires, $usedAt]) {
    $token = bin2hex(random_bytes(32));
    $db->insert('password_reset_tokens', ['user_id' => $uid, 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', strtotime($expires)), 'used_at' => $usedAt, 'requested_ip' => $tag]);
    $g = new HttpClient($base);
    $g->get('/lang/en');
    $page = $g->get('/reset-password/' . $token)['body'];
    $g->post('/reset-password/' . $token, ['password' => 'Hijack3d!x', 'password_confirmation' => 'Hijack3d!x'], true, $base . '/forgot-password');
    check(str_contains($page, 'invalid, expired') && $db->value('SELECT password_hash FROM users WHERE id = ?', [$uid]) === $hashBefore, "$kind token is refused and changes nothing");
}
check((new HttpClient($base))->get('/reset-password/../../.env')['status'] === 404, 'malformed token paths are 404');

// ------------------------------------------------------------------ XSS
section('XSS escaping');
$payload = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
$raw = static fn (string $html): bool => str_contains($html, '<script>alert(1)') || str_contains($html, '<img src=x onerror');
(new HttpClient($base))->post('/contact', ['name' => "N $payload", 'email' => 'xss@example.com', 'subject' => "$tag $payload", 'message' => "Message $payload body"], true, $base . '/contact');
$st3 = new HttpClient($base);
$st3->login('student3@college.test', STUDENT_PASSWORD, 'STUDENT');
$st3->post('/student/feedback', ['type' => 'COMPLAINT', 'category_id' => 1, 'subject' => "$tag $payload", 'message' => "Body $payload"], true, $base . '/student/feedback/new');
$admin->post('/admin/events', ['title_ar' => "$tag $payload", 'title_en' => "$tag $payload", 'description_ar' => $payload, 'description_en' => $payload,
    'location_ar' => $payload, 'location_en' => $payload, 'event_type_id' => 1, 'capacity' => 5,
    'start_datetime' => date('Y-m-d\TH:i', strtotime('+9 days 10:00')), 'end_datetime' => date('Y-m-d\TH:i', strtotime('+9 days 11:00'))], true, $base . '/admin/events/create');
$evId = (int) $db->value('SELECT id FROM events WHERE title_en LIKE ?', ["$tag%"]);
$st3->post("/events/$evId/register", [], true, "$base/events/$evId");
$msgId = (int) $db->value('SELECT id FROM contact_messages WHERE subject LIKE ?', ["$tag%"]);
$fbId = (int) $db->value('SELECT id FROM feedback WHERE subject LIKE ?', ["$tag%"]);
$unsafe = [];
foreach (['/admin/messages', "/admin/messages/$msgId", '/admin/feedback', "/admin/feedback/$fbId", '/admin/dashboard', '/admin/events', "/admin/events/$evId", "/admin/events/$evId/edit"] as $p) {
    if ($raw($admin->get($p)['body'])) {
        $unsafe[] = $p;
    }
}
foreach (["/events/$evId", '/events', '/', "/student/feedback/$fbId", '/student/dashboard', '/notifications', '/student/registrations'] as $p) {
    if ($raw($st3->get($p)['body'])) {
        $unsafe[] = $p;
    }
}
check($evId > 0 && $msgId > 0 && $fbId > 0 && $unsafe === [], 'script/attribute payloads in events, complaints, contact messages and names are escaped on 15 pages' . ($unsafe ? ': ' . implode(', ', $unsafe) : ''));

(new HttpClient($base))->post('/contact', ['name' => 'Mailto', 'email' => 'a?bcc=spy@evil.com', 'subject' => "$tag mailto", 'message' => 'Checking reply links are safe.'], true, $base . '/contact');
$mailtoId = (int) $db->value('SELECT id FROM contact_messages WHERE subject = ?', ["$tag mailto"]);
$mailtoPage = $mailtoId > 0 ? $admin->get("/admin/messages/$mailtoId")['body'] : '';
check($mailtoId === 0 || (str_contains($mailtoPage, 'href="mailto:a%3Fbcc%3Dspy@evil.com?subject=') && !str_contains($mailtoPage, 'mailto:a?bcc')),
    'a crafted contact address cannot add recipients to the admin reply link' . ($mailtoId === 0 ? ' (address rejected by validation)' : ''));

// ------------------------------------------------------------------ Array input (L-9)
section('Array input');
$arrayPost = static function (HttpClient $client, string $path, string $field) use ($base, $cookieFile): int {
    $h = curl_init($base . $path);
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile($client), CURLOPT_COOKIEJAR => $cookieFile($client),
        CURLOPT_POSTFIELDS => http_build_query(['_token' => $client->csrfToken(), $field => ['x', 'y']]), CURLOPT_REFERER => $base . '/contact']);
    curl_exec($h);

    return (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
};
$arrayFailures = [];
foreach ([
    [new HttpClient($base), '/login', 'identifier'], [new HttpClient($base), '/register', 'full_name'], [new HttpClient($base), '/contact', 'message'],
    [new HttpClient($base), '/forgot-password', 'email'], [$st3, '/student/feedback', 'subject'], [$st3, '/student/reservations', 'purpose'],
    [$st3, '/profile', 'full_name'], [$admin, '/admin/events', 'title_ar'], [$admin, '/admin/volunteering', 'capacity'], [$admin, '/admin/settings', 'phone_1'],
] as [$client, $path, $field]) {
    $st = $arrayPost($client, $path, $field);
    if ($st >= 500 || $st === 0) {
        $arrayFailures[] = "$path {$field}[] → $st";
    }
}
$getFailures = [];
foreach (['/events?q[]=x', '/events?page=9223372036854775807', '/login?next[]=x', '/?lang[]=en', '/volunteering?page[]=2'] as $url) {
    if (($st = (new HttpClient($base))->get($url)['status']) >= 500) {
        $getFailures[] = "$url → $st";
    }
}
check($arrayFailures === [] && $getFailures === [], 'array fields ("x[]=") and huge page numbers never cause a server error' . ($arrayFailures || $getFailures ? ': ' . implode('; ', [...$arrayFailures, ...$getFailures]) : ''));
$utf8Failures = [];
foreach ([['subject' => "$tag-utf8 bad\xFF subject", 'message' => 'A valid message body for the test'], ['subject' => "$tag-utf8 fine", 'message' => "bad \xC3\x28 message body for the test"]] as $i => $fields) {
    $uc = new HttpClient($base);
    $res = $uc->post('/contact', $fields + ['name' => 'Utf8 Probe', 'email' => 'utf8probe@example.test', 'website' => ''], true, $base . '/contact');
    if ($res['status'] >= 500 || $res['status'] === 0) {
        $utf8Failures[] = "field set $i → {$res['status']}";
    }
}
check($utf8Failures === [] && (int) $db->value('SELECT COUNT(*) FROM contact_messages WHERE subject LIKE ?', ["$tag-utf8%"]) === 0, 'text that is not valid UTF-8 is refused as invalid input, never a server error or a stored message' . ($utf8Failures ? ': ' . implode('; ', $utf8Failures) : ''));
$jsonFailures = [];
foreach (['/events', '/volunteering'] as $listPath) {
    $res = (new HttpClient($base))->request('GET', $listPath . '?limit=5', [], null, ['Accept: application/json']);
    $json = json_decode($res['body'], true);
    $item = $json['data']['items'][0] ?? null;
    if (!is_array($item)) {
        $jsonFailures[] = "$listPath: no items in the JSON response (status {$res['status']})";
    } elseif (array_key_exists('created_by', $item)) {
        $jsonFailures[] = "$listPath exposes created_by (the administrator's internal user id)";
    } elseif (!isset($item['id'], $item['title_en'], $item['start_datetime'], $item['capacity'], $item['status'])) {
        $jsonFailures[] = "$listPath lost a field the public pages use";
    }
}
check($jsonFailures === [], 'the public JSON listings keep their public fields and do not expose the administrator account id' . ($jsonFailures ? ': ' . implode('; ', $jsonFailures) : ''));
$flagUser = $db->insert('users', ['full_name' => "$tag Flag User", 'email' => strtolower($tag) . '-flag@example.test', 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT, ['cost' => 4]), 'role_id' => 1]);
$isActive = static fn (): int => (int) $db->value('SELECT is_active FROM users WHERE id = ?', [$flagUser]);
$flagFailures = [];
foreach ([[[], 'no active field'], [['active' => ''], 'empty active field'], [['active' => 'banana'], 'unrecognised active value']] as [$body, $label]) {
    $st = $admin->post("/admin/users/$flagUser/active", $body, true, "$base/admin/users")['status'];
    if ($st !== 422 || $isActive() !== 1) {
        $flagFailures[] = "$label → $st, user is " . ($isActive() === 1 ? 'still active' : 'DEACTIVATED');
        $db->query('UPDATE users SET is_active = 1 WHERE id = ?', [$flagUser]);
    }
}
$off = $admin->post("/admin/users/$flagUser/active", ['active' => '0'], true, "$base/admin/users")['status'];
$offOk = $off === 303 && $isActive() === 0;
$on = $admin->post("/admin/users/$flagUser/active", ['active' => '1'], true, "$base/admin/users")['status'];
$onOk = $on === 303 && $isActive() === 1;
$db->query('DELETE FROM users WHERE id = ?', [$flagUser]);
check($flagFailures === [] && $offOk && $onOk, 'deactivating needs an explicit active=0 (a missing or garbled field is refused), and 0/1 still work' . ($flagFailures ? ': ' . implode('; ', $flagFailures) : ''));
$db->query('DELETE FROM rate_limits');

// ------------------------------------------------------------------ Open redirects (?next=)
section('Open redirects');
// A tab or other control character after the first slash is stripped by browsers, turning "/<TAB>/evil.com" into "//evil.com".
$nextLeaks = [];
foreach (["/\t/evil.com", "/\n/evil.com", "/\x01/evil.com", '/\\evil.com', '//evil.com', 'https://evil.com'] as $attack) {
    $page = (new HttpClient($base))->get('/login?next=' . rawurlencode($attack));
    if (str_contains($page['body'], 'name="next"')) {
        $nextLeaks[] = json_encode($attack);
    }
}
check($nextLeaks === [], 'a hostile ?next= value (control characters, backslash, //, absolute URL) is never put into the login form' . ($nextLeaks ? ': ' . implode(', ', $nextLeaks) : ''));
check(str_contains((new HttpClient($base))->get('/login?next=' . rawurlencode('/events?page=2'))['body'], 'name="next"'), 'a normal local ?next= path is still kept');
$redirectLeaks = [];
foreach (["/\t/evil.com", "/\n/evil.com", '//evil.com'] as $attack) {
    $nc = new HttpClient($base);
    $res = $nc->request('POST', '/login', [
        '_token' => $nc->csrfToken('/login'), 'identifier' => STUDENT_EMAIL, 'password' => STUDENT_PASSWORD, 'role' => 'STUDENT', 'next' => $attack,
    ], $base . '/login');
    $location = $res['headers']['location'] ?? '';
    if ($res['status'] !== 303 || preg_match('~[\x00-\x1f]|//evil~', $location) === 1) {
        $redirectLeaks[] = json_encode($attack) . ' → ' . json_encode($location) . " ({$res['status']})";
    }
}
check($redirectLeaks === [], 'a hostile "next" posted with a correct login still lands on the student home' . ($redirectLeaks ? ': ' . implode('; ', $redirectLeaks) : ''));
$db->query('DELETE FROM rate_limits');

// ------------------------------------------------------------------ Attachments
section('Feedback attachments');
$pdf = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($pdf, "%PDF-1.4\n% $tag test attachment\n");
$owner = new HttpClient($base);
$owner->login('student4@college.test', STUDENT_PASSWORD, 'STUDENT');
$owner->post('/student/feedback', ['type' => 'SUGGESTION', 'category_id' => 1, 'subject' => "$tag attachment", 'message' => 'Suggestion with a file',
    'attachment' => new CURLFile($pdf, 'application/pdf', 'my report.pdf')], true, $base . '/student/feedback/new');
$attId = (int) $db->value('SELECT a.id FROM feedback_attachments a JOIN feedback f ON f.id = a.feedback_id WHERE f.subject = ?', ["$tag attachment"]);
$ownerDl = $owner->get("/attachments/$attId");
check($attId > 0 && $ownerDl['status'] === 200 && str_starts_with($ownerDl['body'], '%PDF-'), 'the owner can download their attachment');
check(str_starts_with((string) ($ownerDl['headers']['content-disposition'] ?? ''), 'attachment;') && ($ownerDl['headers']['x-content-type-options'] ?? '') === 'nosniff', 'downloads are forced as attachments with nosniff');
check($st3->get("/attachments/$attId")['status'] === 404, 'another student cannot download it (404, existence not revealed)');
check((new HttpClient($base))->get("/attachments/$attId")['status'] === 303, 'a guest is sent to login');
check($admin->get("/attachments/$attId")['status'] === 200, 'an admin can download it');
$stored = (string) $db->value('SELECT stored_name FROM feedback_attachments WHERE id = ?', [$attId]);
check(in_array((new HttpClient($base))->get('/storage/uploads/attachments/' . $stored)['status'], [403, 404], true), 'stored files are not reachable by URL');
$php = tempnam(sys_get_temp_dir(), 'php');
file_put_contents($php, '<?php echo "pwned"; ?>');
$owner->post('/student/feedback', ['type' => 'COMPLAINT', 'category_id' => 1, 'subject' => "$tag php upload", 'message' => 'Complaint with a script',
    'attachment' => new CURLFile($php, 'application/pdf', 'shell.php')], true, $base . '/student/feedback/new');
check((int) $db->value('SELECT COUNT(*) FROM feedback WHERE subject = ?', ["$tag php upload"]) === 0, 'a PHP file disguised as PDF is rejected (no record created)');

// ------------------------------------------------------------------ Event images
section('Event image uploads');
$png = app()->basePath('public/assets/img/feedback.png');
$eventFields = ['title_ar' => "$tag img", 'title_en' => "$tag img", 'description_ar' => 'x', 'description_en' => 'x', 'location_ar' => 'x', 'location_en' => 'x',
    'event_type_id' => 1, 'capacity' => 5, 'start_datetime' => date('Y-m-d\TH:i', strtotime('+12 days 10:00')), 'end_datetime' => date('Y-m-d\TH:i', strtotime('+12 days 11:00'))];
check($student->post('/admin/events', $eventFields + ['image' => new CURLFile($png, 'image/png', 'a.png')])['status'] === 403, 'a student cannot upload event images (403)');
$admin->post('/admin/events', ['title_en' => "$tag php"] + $eventFields + ['image' => new CURLFile($php, 'image/jpeg', 'photo.php.jpg')], true, $base . '/admin/events/create');
check((int) $db->value('SELECT COUNT(*) FROM events WHERE title_en = ?', ["$tag php"]) === 0, 'a PHP script posing as a JPEG is rejected');
$admin->post('/admin/events', $eventFields + ['image' => new CURLFile($png, 'image/png', '../../evil.php.png')], true, $base . '/admin/events/create');
$imagePath = (string) $db->value('SELECT image_path FROM events WHERE title_en = ?', ["$tag img"]);
check(preg_match('#^uploads/events/[a-f0-9]{32}\.png$#', $imagePath) === 1, 'a real image gets a random name + MIME-derived extension (client name ignored)');
$served = (new HttpClient($base))->get('/' . $imagePath);
check($served['status'] === 200 && ($served['headers']['content-type'] ?? '') === 'image/png', 'the stored image is served as image/png');
$probe = app()->basePath('public/uploads/events/' . $tag . '.php');
file_put_contents($probe, '<?php echo "EXECUTED";');
$exec = (new HttpClient($base))->get('/uploads/events/' . $tag . '.php');
@unlink($probe);
check($exec['status'] === 403 && !str_contains($exec['body'], 'EXECUTED'), 'even a .php file placed in uploads/ is never executed (403)');
@unlink(app()->basePath('public/' . $imagePath));

// ------------------------------------------------------------------ Registration rate limit + concurrency
section('Registration rate limit');
$limiter = app(RateLimiter::class);
foreach (['::1', '127.0.0.1'] as $ip) {
    for ($i = 0; $i < (int) config('auth.register_max_attempts_per_ip'); $i++) {
        $limiter->hit('register-attempt:' . $ip, 900);
    }
}
$reg = new HttpClient($base);
$reg->get('/lang/en');
$reg->post('/register', ['full_name' => 'Rate Limited', 'student_id' => '999999999', 'email' => "$tag@example.com", 'phone' => '0550000000',
    'password' => 'Passw0rd!x', 'password_confirmation' => 'Passw0rd!x'], true, $base . '/register');
check(str_contains($reg->get('/register')['body'], 'Too many sign-up attempts') && $db->value('SELECT id FROM users WHERE email = ?', ["$tag@example.com"]) === null,
    'sign-ups are refused after ' . config('auth.register_max_attempts_per_ip') . ' attempts per IP / 15 min');
$db->query('DELETE FROM rate_limits');

section('Login rate limit under parallel requests');
$victim = "parallel.$tag@college.test";
$clients = [];
$mh = curl_multi_init();
for ($i = 0; $i < 15; $i++) {
    $cl = new HttpClient($base);
    $cl->get('/lang/en');
    $h = curl_init("$base/login");
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile($cl), CURLOPT_COOKIEJAR => $cookieFile($cl),
        CURLOPT_POSTFIELDS => ['_token' => $cl->csrfToken('/login'), 'identifier' => $victim, 'password' => 'Wrong-pass-1', 'role' => 'STUDENT']]);
    curl_multi_add_handle($mh, $h);
    $clients[] = [$h, $cl];
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$outcome = ['failed' => 0, 'throttled' => 0, 'other' => 0];
foreach ($clients as [$h, $cl]) {
    curl_multi_remove_handle($mh, $h);
    curl_close($h);
    $page = $cl->get('/login')['body'];
    $outcome[str_contains($page, 'Too many login attempts') ? 'throttled' : (str_contains($page, 'password is incorrect') ? 'failed' : 'other')]++;
}
check($outcome['failed'] <= 5 && $outcome['failed'] + $outcome['throttled'] === 15,
    "15 simultaneous wrong-password logins → at most 5 checked, the rest throttled (" . json_encode($outcome) . ')');
$db->query('DELETE FROM rate_limits');

section('Concurrency');
$ev = $db->insert('events', ['title_ar' => "$tag race", 'title_en' => "$tag race", 'description_ar' => 'x', 'description_en' => 'x', 'event_type_id' => 1,
    'location_ar' => 'x', 'location_en' => 'x', 'start_datetime' => date('Y-m-d H:i:s', strtotime('+3 days')),
    'end_datetime' => date('Y-m-d H:i:s', strtotime('+3 days +2 hours')), 'capacity' => 3]);
$handles = [];
$mh = curl_multi_init();
for ($i = 0; $i < 12; $i++) {
    $cl = new HttpClient($base);
    $cl->login("demo$i@college.test", STUDENT_PASSWORD, 'STUDENT');
    $h = curl_init("$base/events/$ev/register");
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['_token' => $cl->csrfToken()], CURLOPT_COOKIEFILE => $cookieFile($cl), CURLOPT_RETURNTRANSFER => true]);
    curl_multi_add_handle($mh, $h);
    $handles[] = [$h, $cl];
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
check((int) $db->value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'CANCELLED'", [$ev]) === 3, '12 simultaneous registrations for 3 seats → exactly 3');

// ------------------------------------------------------------------ HTTP exposure
section('HTTP exposure');
$paths = ['/.env', '/.env.example', '/.git/config', '/.git/HEAD', '/.gitignore', '/backups/', '/storage/logs/', '/storage/mail/', '/tests/smoke/flows.php',
    '/docs/01-audit-and-plan.md', '/README.md', '/composer.json', '/composer.lock', '/vendor/autoload.php', '/vendor/composer/installed.json',
    '/app/bootstrap.php', '/config/app.php', '/bin/console', '/views/layouts/app.php', '/locales/en/auth.json',
    '/database/migrations/001_create_schema.sql', '/database/setup/create_app_user.sql', '/database/demo/seed.php',
    '/legacy/connection.php', '/legacy/project.sql', '/phpunit.xml', '/public/.htaccess', '/uploads/.htaccess'];
$exposed = [];
foreach ($paths as $p) {
    $st = (new HttpClient($base))->get($p)['status'];
    if (!in_array($st, [403, 404], true)) {
        $exposed[] = "$p → $st";
    }
}
check($exposed === [], count($paths) . ' sensitive paths return 403/404' . ($exposed ? ': ' . implode(', ', $exposed) : ''));
$root = preg_replace('#/project$#', '', $base);
$zips = glob('C:/xampp/htdocs/*.{zip,sql,gz}', GLOB_BRACE) ?: [];
check($zips === [] && (new HttpClient((string) $root))->get('/project_backup_2026-09-24.zip')['status'] === 404, 'no backup archives or dumps in the web root');

$cleanup();
@unlink($pdf);
@unlink($php);
fwrite(STDOUT, PHP_EOL . "$pass passed, $fail failed." . PHP_EOL);
exit($fail === 0 ? 0 : 1);

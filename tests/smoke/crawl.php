<?php

declare(strict_types=1);

/**
 * End-to-end smoke crawl against the running site (Apache/XAMPP).
 *
 *   php tests/smoke/crawl.php [base-url]
 *
 * Logs in as the demo student and admin, requests every page in Arabic and English and fails on:
 *   - unexpected HTTP status
 *   - untranslated keys leaking into the HTML (e.g. "events.admin.title")
 *   - PHP warnings / stack traces in the output
 *   - inline style="" or <script> without src (blocked by our CSP)
 *   - wrong lang/dir attributes
 */

require __DIR__ . '/bootstrap.php';

$base = BASE_URL;
$failures = 0;
$checked = 0;

$check = static function (HttpClient $client, string $path, string $lang, int $expect = 200) use (&$failures, &$checked): string {
    $client->get('/lang/' . $lang);
    $res = $client->get($path);
    $checked++;
    $problems = [];
    if ($res['status'] !== $expect) {
        $problems[] = "status {$res['status']} (expected $expect)";
    }
    $html = $res['body'];
    if ($expect === 200) {
        $text = strip_tags(preg_replace('#<script\b[^>]*>.*?</script>#s', '', $html) ?? '');
        if (preg_match_all('/\b(?:common|auth|home|events|volunteering|facilities|reservations|feedback|contact|profile|users|settings|student|notifications|admin|validation|emails|about|dev)\.[a-z_]+(?:\.[A-Za-z_]+)*\b/', $text, $m)) {
            $problems[] = 'raw keys: ' . implode(', ', array_slice(array_unique($m[0]), 0, 5));
        }
        if (preg_match('/(Warning|Notice|Fatal error|Deprecated|Stack trace|Uncaught)\b/', $html)) {
            $problems[] = 'PHP error text in output';
        }
        if (preg_match('/\sstyle="/', $html)) {
            $problems[] = 'inline style attribute (blocked by CSP)';
        }
        if (preg_match('#<script(?![^>]*\bsrc=)(?![^>]*application/json)[^>]*>#', $html)) {
            $problems[] = 'inline <script> (blocked by CSP)';
        }
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        if (!str_contains($html, "<html lang=\"$lang\" dir=\"$dir\">")) {
            $problems[] = "missing <html lang=\"$lang\" dir=\"$dir\">";
        }
    }
    if ($problems !== []) {
        $failures++;
        fwrite(STDOUT, "FAIL [$lang] $path: " . implode('; ', $problems) . PHP_EOL);
    }

    return $html;
};

$public = ['/', '/about', '/events', '/events?status=UPCOMING&q=a', '/volunteering', '/facilities', '/facilities/1', '/contact',
    '/login', '/register', '/forgot-password', '/events/3', '/volunteering/1'];
$studentPages = ['/student/dashboard', '/student/registrations', '/student/registrations?tab=completed', '/student/registrations?tab=cancelled',
    '/student/volunteering', '/student/reservations', '/student/reservations/new?facility=1', '/student/feedback', '/student/feedback/new',
    '/student/feedback/1', '/profile', '/notifications', '/events/2', '/volunteering/2'];
$adminPages = ['/admin/dashboard', '/admin/events', '/admin/events/create', '/admin/events/3', '/admin/events/3/edit',
    '/admin/volunteering', '/admin/volunteering/create', '/admin/volunteering/6', '/admin/volunteering/6/edit', '/admin/registrations',
    '/admin/reservations', '/admin/facilities', '/admin/facilities/1/edit', '/admin/facilities/create', '/admin/feedback', '/admin/feedback/1',
    '/admin/messages', '/admin/messages/1', '/admin/users', '/admin/settings', '/profile'];

foreach (['ar', 'en'] as $lang) {
    $guest = new HttpClient($base);
    foreach ($public as $p) {
        $check($guest, $p, $lang);
    }
    $check($guest, '/does-not-exist', $lang, 404);
    $check($guest, '/admin/dashboard', $lang, 303);   // guest → login redirect
    $check($guest, '/student/dashboard', $lang, 303);

    $student = new HttpClient($base);
    $student->login(STUDENT_EMAIL, STUDENT_PASSWORD, 'STUDENT');
    foreach ($studentPages as $p) {
        $check($student, $p, $lang);
    }
    $check($student, '/admin/dashboard', $lang, 403);  // student cannot open admin

    $admin = new HttpClient($base);
    $admin->login(ADMIN_EMAIL, ADMIN_PASSWORD, 'ADMIN');
    foreach ($adminPages as $p) {
        $check($admin, $p, $lang);
    }
}

fwrite(STDOUT, sprintf("%d pages checked, %d failure(s).\n", $checked, $failures));
exit($failures === 0 ? 0 : 1);

# Test Report

## Follow-up round (2026-09-30, evening)

Changes since the round below: new email addresses are confirmed by link, UI improvements were ported from the client copy, the seed contact details are now demo values, and the local server banners are hidden. Decisions are in [`docs/03-decisions.md`](03-decisions.md).

| Suite | Result |
|---|---|
| PHPUnit (`composer test`) | **112 tests, 578 assertions, all passing**: 62 unit, 50 integration (11 new for email change by link) |
| End-to-end behaviour (`tests/smoke/flows.php`) | **97 / 97**. New section: email change by link over HTTP, 9 checks |
| Security (`tests/smoke/security.php`) | **37 / 37**. The route matrix now covers 43 state-changing routes, including the two new email-change routes |
| Page crawl (`tests/smoke/crawl.php`) | **104 pages, 0 failures** |
| Translations (`bin/console i18n:check`) | **OK**: 839 Arabic keys, 815 English |
| Dependencies (`composer audit --locked`) | **No advisories** |
| Syntax (`php -l` on every PHP file) | **No errors** |

The suites passed on the branch before the merge and again on `main` after it.

**Manual walkthrough (34 checks, all passing).** A fresh student and the administrator used the real forms:
- registration, login and password reset;
- events, volunteering and reservations;
- a complaint with a PDF;
- all 10 admin sections, including create/cancel/delete of an event;
- the new email change: the link goes to the new address, the confirm page opens with no change yet, and confirming changes the email and warns the old address.

**Screenshots (headless Edge), all correct:**
- login panel (site name now readable);
- home page at 360 px in Arabic and English (the header fits on one row);
- About page at 360 px;
- profile with a pending email change;
- the Arabic confirmation page;
- admin users at 390 px (stacked cards, no horizontal overflow);
- admin reservations in Arabic at 1024 px;
- admin messages at 768 px.

**Client delivery package.** The ZIP was installed exactly as its README describes, into a scratch folder and a temporary database with fresh random passwords:
- **Install:** 24 tables imported; `db:check` reports data access only; `migrate` is refused for the website account and reports "Nothing to migrate" with the migration account; `admin:create` works.
- **HTTP checks, 13/13:** presentation content; the client's contact details; security headers; the old demo logins no longer exist; all admin sections; student registration; a booking reaching the admin; `.env` not served.

**Local server.** `Server: Apache` (no version), no `X-Powered-By`, no version on error pages, and `TRACE` answers 405.

---

## Security hardening round (2026-09-30)

Environment: Windows 11, XAMPP (Apache 2.4.58 with mod_php, PHP 8.2.12, MariaDB 10.4.32). All findings of the September security audit (H-1, H-2, M-1…M-5, L-1…L-11) are fixed; see [`SECURITY.md`](../SECURITY.md).

| Suite | Command | Result |
|---|---|---|
| PHPUnit: unit (62) + integration (42) | `composer test` | **104 tests, 509 assertions, all passing** |
| End-to-end behaviour (HTTP + DB) | `php tests/smoke/flows.php` | **88 / 88 passing** |
| Security (HTTP + DB) | `php tests/smoke/security.php` | **37 / 37 passing** |
| Page crawl, guest/student/admin × AR/EN | `php tests/smoke/crawl.php` | **104 pages, 0 failures** |
| Translation catalogue | `php bin/console i18n:check` | **OK**: 817 Arabic keys, 793 English (difference = Arabic-only plural forms) |
| Dependencies | `composer audit --locked` | **No advisories** |

**Restricted database accounts.** The suites were also run with two temporary accounts created from `database/setup/create_app_user.sql`: `SELECT/INSERT/UPDATE/DELETE` only for the application, plus a separate migration account, on scratch databases.
- `migrate` works with the migration account; the application account is refused DDL ("Access denied").
- `db:check` reports "data access only".
- PHPUnit: 104 / 104. Flows: 88 / 88. Security: 37 / 37. Crawl: 104 pages, 0 failures.
- The temporary accounts and databases were dropped afterwards.

**New tests in this round**

| Finding | Test | Proves |
|---|---|---|
| M-1 | `RateLimitTest` (integration, parallel PHP processes) | 16 simultaneous limiter calls with a limit of 5 → exactly 5 pass; 12 simultaneous wrong-password logins for one account → exactly 5 password checks (the pre-fix code let all 12 through); 5 failures lock the account on that IP only; 20 guesses from 20 different IPs lock the account; correct passwords never count against a shared IP |
| M-1 | `security.php` | 15 simultaneous HTTP logins → at most 5 checked, the rest throttled |
| M-2 | `ReservationTransitionTest` (parallel) | Stale decisions refused; decided requests stay decided; approved bookings cancellable only before they start; simultaneous approve/reject/cancel → one consistent outcome and one notification per applied decision; simultaneous approval of two overlapping requests → exactly one approved (the pre-fix code double-booked) |
| M-3, L-6 | `ThrottlingTest` | 10 complaints/hour and 10 booking requests/hour per student; 5 wrong current-password checks lock further checks |
| M-5 | `LegacyImportTest` | Imported admins get an unusable Argon2id hash; students keep their bcrypt hash |
| H-1 | `InitialAdminTest` | First admin created with a hashed password and an audit entry; refused once an admin exists; weak passwords and existing emails refused |
| H-2 | `DbPrivilegeCheckerTest`, `RouterAndMigratorTest` | Excess privileges detected; migrations use the separate account; unsafe database names rejected |
| L-1 | `RequestTest` | HSTS only on real HTTPS, never for a spoofed `X-Forwarded-Proto`, never locally |
| L-3 | `RouterAndMigratorTest`, `flows.php` | A reset-form error without a Referer returns to the reset page with the message |
| L-4 | `RememberMeTest` (parallel), `flows.php` | Overlapping requests with one cookie keep the token (fails on the pre-fix code); grace window; a later replay still revokes everything |
| L-5 | `ActivityTransitionTest` | A removed volunteer cannot re-register; completion, removal, attendance and cancellation apply once |
| L-6 | `EmailChangeTest` | Email change bumps `auth_version`, revokes remember tokens, emails the old address with the new one masked |
| L-7 | `MailtoTest`, `security.php` | Crafted addresses cannot add recipients or headers to mailto links |
| L-8 | `PasswordResetQueueTest`, `flows.php` | The request sends nothing and stores no secret; the worker creates the token and sends the link; retry, back-off and drop; the HTTP flow receives the email after the response |
| L-9 | `ValidatorTest`, `security.php` | Array input rejected for every rule and ignored by the request accessors; page cap; ten forms and five URLs with array input never return 500 |
| L-11 | `DemoSeederPasswordTest` | Weak demo passwords refused; generated ones strong and unique |

**Timing (L-8).** Eight `POST /forgot-password` requests each, over HTTP on this machine (log mail driver): registered address median 47 ms (38–53), unknown address 45 ms (42–47).

**Manual walkthrough.** A fresh student and the demo admin went through every flow using the real forms and pages; all 31 checks passed:
- registration (and weak-password refusal);
- login by student ID, generic error, admin choice refused;
- password reset (email after the response, `no-referrer`, error stays on the page, old session ended, new password works);
- events (register, cancel, register again);
- volunteering (admin creates an opportunity, student signs up, admin removes them, re-registration refused);
- reservations (request, approve, later reject refused);
- feedback with a PDF (admin reply, attachment download);
- admin panel (all 10 sections; create, cancel and delete an event; a second cancel refused);
- email change (old address warned).

Headless Edge screenshots of login, forgot-password, events (AR), student dashboard, student reservations (AR), admin dashboard, admin reservations (AR) and admin messages rendered correctly.

**Web exposure.** With the new root `.htaccess`, 28 sensitive paths return 403/404 (including `/.git/HEAD`, `/composer.lock`, `/database/setup/create_app_user.sql` and `/database/demo/seed.php`). A missing `.env` gives a 503 "Service not configured" (checked by temporarily renaming it). Over `https://localhost` the session cookie is `Secure`, and HSTS is sent only outside `APP_ENV=local`. `X-Powered-By` is no longer sent; the `Server` banner needs the Apache settings in SECURITY.md.

---

## Previous round (2026-09-25)

Date: 2026-09-25 (after the media, date-filter and contact-detail fixes). Environment: Windows 11, XAMPP (Apache 2.4, PHP 8.2.12, MariaDB 10.4.32). Application database `college_platform` with the legacy import and local demo seed; integration tests use the separate `college_platform_test`.

## Suites

| Suite | Command | Result |
|---|---|---|
| PHPUnit: unit (43) + integration (15) | `composer test` | **58 tests, 167 assertions, all passing** |
| End-to-end behaviour (HTTP + DB) | `php tests/smoke/flows.php` | **83 / 83 passing** |
| Security (HTTP + DB) | `php tests/smoke/security.php` | **34 / 34 passing** |
| Page crawl, guest/student/admin × AR/EN | `php tests/smoke/crawl.php` | **104 pages, 0 failures** |
| Translation catalogue | `php bin/console i18n:check` | **OK**: 806 Arabic keys, 782 English (difference = Arabic-only plural forms) |

## What each suite covers

**Unit (43 cases, no database)**
- **Event status rules:** 6 cases. Upcoming / ongoing / completed boundaries, the cancel override, and registration eligibility.
- **Translator:** 13 cases. Arabic 6-form plurals, English plurals, placeholders, locale fallback.
- **Date formatting:** 4 cases. The specified Arabic and English formats, relative time, and input values.
- **Validator:** 7 cases. Type normalization, email, password policy, date order, lengths, multibyte, Saudi phone trunk-zero (`+966011…` → `+96611…`).
- **Card images:** 3 cases. An upload wins; every event type and volunteering category has a bundled default; unknown or hostile type codes fall back to the generic image.
- **Router + SQL splitter:** 4 cases. Parameters, 404/405, quoting/comments, all migration files parse.
- **Uploads:** 5 cases.
  - A real image gets a random name and an extension derived from its MIME type.
  - PHP scripts and polyglot files posing as JPEG/GIF are rejected.
  - SVG and HTML are rejected.
  - Private files stay outside the web root, with no path traversal.
  - Oversized files are rejected.
- **Request / proxy trust:** 5 cases.
  - Forwarded headers are ignored without a trusted proxy.
  - A trusted proxy is honored, and an untrusted peer can't spoof.
  - HTTPS or an `https://` `APP_URL` makes cookies Secure.
  - Loopback detection.

**Integration (15 cases, real MariaDB test database, dropped and migrated each run)**
- **Events:** capacity and duplicates, closed events (started / completed / cancelled), cancelling frees the seat, capacity can't drop below current registrations, cancellation notification has no dangling reason.
- **Reservations:** approved bookings can't overlap (touching slots are allowed), a new request over an approved slot is refused, cancellation ownership and rules.
- **Volunteer hours:** 0 hours on registration, refused before start, awarded on approval, cancelled registrations never count, no double completion.
- **Password reset:** a valid token resets once, bumps `auth_version` and revokes remember-me; expired or used tokens are refused and change nothing; purge removes only dead tokens.

**End-to-end flows (83):**

| Area | Checks |
|---|---|
| Registration | 6 |
| Login & role verification | 8 |
| Login throttling | 1 |
| Remember me | 7 |
| Password reset | 8 |
| Event rules | 9 |
| Admin event management | 7 |
| Volunteering & hours | 5 |
| Reservations | 8 |
| Feedback & contact | 4 |
| Admin self-protection | 3 |
| Search / pagination / language | 5 |
| Card media, contact details, date filters | 12 |

**Security (34)**

| Area | Checks | Covers |
|---|---|---|
| Sessions & cookies | 7 | ID rotation at login, fixation rejected, HttpOnly/SameSite, Secure only on HTTPS, POST-only logout, dead session after logout, remember-me cookie flags |
| Route matrix | 3 | **All 41** state-changing routes reject missing CSRF tokens for guest/student/admin; a student gets **403 on all 47** admin routes; guests are redirected |
| Development mailbox | 4 | Student 403; admin on localhost 200; **404 via the LAN IP even for an admin**; mail files not web-served |
| Reset tokens | 3 | Expired → refused, used → refused, malformed → 404 |
| XSS | 1 | Script/attribute payloads in event text, names, complaints and contact messages escaped on 15 pages |
| Attachments | 7 | Owner 200, other student 404, guest → login, admin 200, forced download + nosniff, stored files not URL-reachable, PHP disguised as PDF rejected |
| Event images | 5 | Student 403; PHP-as-JPEG rejected; random name + MIME extension; served as image/png; a `.php` placed in uploads is never executed |
| Registration rate limit | 1 | Refused after 60 attempts per IP per 15 min |
| Concurrency | 1 | 12 simultaneous registrations for 3 seats → exactly 3 |
| HTTP exposure | 2 | 17 sensitive paths return 403/404; no archives or dumps in the web root |

## Responsive check (headless Edge, same-origin harness)

Headers at **390, 768, 1024, 1280 and 1440 px**, in Arabic (RTL) and English (LTR), as a guest and as a logged-in student:
- no overflow at any width;
- Create account, the language switcher and the user menu are visible;
- the mobile menu opens and lists every link (plus the language switcher at 390 px);
- the user dropdown opens fully visible, anchored to the start side in RTL.

The previous English overflow at exactly 1024 px is fixed: the navigation now collapses below 1200 px. Admin pages were checked at 1280 px and 390 px in the previous round.

## Date filters and card media (headless Edge)
- The Arabic placeholder renders as **يوم/شهر/سنة** (not letter-reversed), and the English one as dd/mm/yyyy.
- The flatpickr calendar opens in Arabic (Saturday first, right to left, mirrored arrows) and in English.
- Picking a date auto-submits `from=2026-09-26` (Y-m-d) and shows `26/09/2026`. **Apply** and **Clear filters** work as before.
- Event and volunteering grids: every card has the same 16:9 cover. No venue photos appear on events.
- About page: the default campus illustration. Contact page: no placeholder address; phones read `+966…` left to right.

## Manual checks (not automated)
- `http://localhost/project_backup_2026-09-24.zip` → **404** (also via LAN IP). The file is at `<backup folder>`.
- `/_dev/mail` via LAN IP → **404**; guest on localhost → login redirect.
- `/student/profile` → **301** to `/profile`.
- `php bin/console tokens:purge` runs cleanly.
- `db:seed` with `APP_ENV=production` → refused (exit 1).

## Not verified in this environment
- **Real SMTP delivery** (mail verified through the `log` driver).
- **HTTPS end-to-end:** Secure-cookie logic is covered by unit tests; there is no TLS on localhost.
- **Screen-reader session:** semantic markup, labels, ARIA and focus styles are in place, but not tested with assistive technology.
- **Tablet admin pages:** only public pages and headers were captured at 768/1024 px.

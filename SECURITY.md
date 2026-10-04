# Security

This document describes how the Technical College Portal protects its users and data, how each measure is tested, and what is deliberately out of scope. Deployment steps are in the [README](README.md).

## Reporting a vulnerability

Please report suspected vulnerabilities privately through GitHub's **"Report a vulnerability"** (Security → Advisories) on this repository rather than in a public issue. Include the affected URL or file, the steps to reproduce, and the impact you observed.

## Threat model in brief

- **Users:** anonymous visitors, self-registered students, and a small number of administrators.
- **Assets:** personal data (names, student IDs, emails, phones), complaints and their attachments, volunteer hours, facility bookings, and administrator accounts.
- **Main threats considered:** account takeover (guessing, stolen cookies, reset-link abuse), privilege escalation from student to admin, injection (SQL, XSS, header/mailto), cross-site request forgery, malicious uploads, race conditions in capacity/booking/rate-limit logic, account enumeration, abuse/flooding, and leaking configuration or source through the web server.

## Summary

| Area | Measure |
|---|---|
| Passwords | Argon2id (bcrypt fallback); legacy bcrypt hashes verified and upgraded on login; policy ≥ 8 characters with letters and digits |
| Login | Generic error for wrong password or unknown account (same timing); role choice verified against the database; atomic rate limits |
| Sessions | HttpOnly + SameSite=Lax (+ Secure on HTTPS) cookie, strict mode, ID regenerated at login/logout, idle 120 min / absolute 720 min, `auth_version` ends other sessions |
| Remember me | Selector/validator tokens, only SHA-256 of the validator stored, rotated on every use under a row lock, replay of an old cookie revokes all tokens |
| Password reset | 256-bit single-use token stored as SHA-256, 60-minute expiry, identical response and timing for known and unknown emails, `no-referrer` page |
| Email change | Current password, then a single-use link to the new address (confirmed while signed in, by POST); the old address is warned and other sessions end |
| Authorization | Role reloaded from the database on every request; ownership checks return 404; admin self-protection and last-admin guard; audit log |
| Input | Prepared statements only; declarative validation with scalar-only input; output escaped everywhere; strict CSP |
| CSRF | Token on every non-GET request, checked with `hash_equals` |
| Abuse | DB-backed atomic rate limits on login, registration, reset, contact, feedback, bookings, password re-checks and email-change confirmations |
| Integrity | Row locks and status-guarded updates for seats, bookings, volunteer decisions and cancellations |
| Uploads | Content-sniffed type, image decode / PDF signature check, random names, private files outside the web root, no PHP execution in `uploads/` |
| Transport & headers | CSP, HSTS (HTTPS), X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, no `X-Powered-By` |
| Configuration | No secrets in the repository; clear failure without `.env`; production-safe defaults; `root` refused outside local |
| Database | Runtime account limited to SELECT/INSERT/UPDATE/DELETE; separate migration account; `db:check` verifies it |
| Web root | Document root `public/`; explicit deny rules as a second layer |

## Authentication

**Password storage.** Passwords are hashed with Argon2id through `password_hash()`; if PHP lacks Argon2 support, bcrypt is used. Legacy bcrypt hashes imported from the old system still verify and are rehashed to Argon2id at the next successful login. Hashes are only ever read to verify a password. Passwords, tokens and CSRF values are never re-flashed into forms after a validation error.

**Login.**
- Users log in with email or student ID. A wrong password and an unknown account produce the same message, and an unknown account still runs a dummy hash verification so both paths take the same time.
- The Student/Admin selector on the login form is a claim, not a privilege: the login succeeds only when it matches the role stored in the database.
- Deactivated accounts cannot log in and are signed out immediately when deactivated.

**Rate limiting (atomic).** Limits are stored in the `rate_limits` table, so they hold across PHP processes and survive cookie or session resets. `RateLimiter::attempt()` increments the counter and reads the value produced by its own statement (`INSERT … ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(…)`), so of N simultaneous requests at most the limit get through. A correct password refunds its attempt, so busy shared campus IPs are not locked out by successful logins.

| Action | Limit |
|---|---|
| Login, per account + IP | 5 attempts / 15 min |
| Login, per IP | 30 attempts / 15 min |
| Login, per account across all IPs | 20 attempts / hour (stops distributed guessing) |
| Password reset | 3 per email and 10 per IP / hour (the per-email limit is silent: same response) |
| Registration, per IP | 60 submissions / 15 min and 30 accounts created / hour |
| Contact form, per IP | 5 / hour (plus a honeypot field) |
| Suggestions & complaints, per student | 10 / hour, counted before an attachment is written to disk |
| Facility booking requests, per student | 10 / hour |
| Current-password checks (email or password change), per account | 5 wrong / 15 min |
| Email-change confirmation emails, per account | 3 / hour |

**First administrator.** No account is created by installing the application. The first administrator is created with `php bin/console admin:create`, which reads the password from a hidden prompt (never from a command-line argument), applies the password policy, and refuses once an active admin exists. Demo accounts exist only through a separate local-only script (see *Configuration*).

**Legacy import.** Administrators imported from the old system get an unusable random hash and must reset their password before first use; the old passwords never met any policy.

## Sessions and "Remember me"

- **Session cookie:** `HttpOnly`, `SameSite=Lax`, `Secure` whenever the request is HTTPS (directly, or through a proxy listed in `TRUSTED_PROXIES`) or `APP_URL` is `https://`, and scoped to the application's path. PHP's strict session mode is on and URL session IDs are disabled.
- **Session IDs** are 48 characters and are regenerated at login and logout (no session fixation). Sessions expire after 120 minutes idle or 720 minutes in total.
- **`auth_version`:** every session stores the account's version number. A password change, password reset, email change or deactivation increments it, which ends every other session at its next request; the session that made the change stays signed in.
- **Remember me:**
  - The cookie is `selector:validator`, and the database stores only `sha256(validator)`, so a database leak cannot be replayed as a cookie.
  - Each use rotates the validator. Consumption runs in a transaction with `SELECT … FOR UPDATE`, so simultaneous requests are processed one at a time.
  - The previous validator stays valid for 30 seconds after a rotation. Parallel requests from the same browser (e.g. restoring several tabs) are therefore accepted without rotating again.
  - Any other known-selector / wrong-validator combination is treated as a stolen, replayed cookie and **revokes every remember token of that user**.
  - Tokens expire after 30 days (absolute) and are revoked on logout, password change/reset, email change and deactivation.
- **Logout** is POST-only and CSRF-protected.

## Password reset

- The token is 32 random bytes (hex). The database stores only its SHA-256. It is valid for 60 minutes and is single use: consumed under `SELECT … FOR UPDATE`, and every other token of the user is deleted.
- **Account enumeration:**
  - The response is identical for registered and unknown addresses.
  - The request only records a job in `mail_queue`, and only for registered addresses. The job holds no secret. The token is created and the email sent after the response has been delivered (or by `mail:work` from cron), so response time doesn't reveal whether an address is registered: measured locally, 47 ms median for registered vs 45 ms for unknown addresses.
- The link is built from `APP_URL`, never from the `Host` header, so a forged Host cannot redirect reset links. The reset page is served with `Referrer-Policy: no-referrer` because the token is in its URL.
- A successful reset ends all sessions and remember tokens and notifies the user.

## Account changes

- **Changing the login email** takes two steps, and the email doesn't change until both are done.
  1. **Request.** The current password is required (throttled). The new address is stored as a pending request, and a confirmation link goes to that new address.
     - The link token is 256-bit random, stored only as SHA-256, valid for 60 minutes and usable once.
     - There is one pending request per account, and at most 3 verification emails per hour, so the form can't be used to spam other people.
  2. **Confirmation.** The link must be opened while signed in to the same account. It shows a page with a confirm button (`Referrer-Policy: no-referrer`), and a GET never changes anything, so mail scanners that prefetch links can't confirm it. The POST then:
     - re-checks that the address is still free;
     - changes the email;
     - ends every other session and remembered device;
     - writes an audit entry;
     - emails the **old** address in the account's language, with the new address masked.
  - A password change, password reset or deactivation cancels a pending request, so a hijacked session can't finish moving the account after the owner resets the password.
- Changing the password requires the current password (throttled). It ends other sessions and remember tokens and sends a notification.
- A student ID can be set once by the student; afterwards only an administrator changes it.

## Authorization

- The user's role is reloaded from the database on every request; route middleware enforces `auth`, `guest`, `role:ADMIN` and `role:STUDENT`.
- **Ownership:** another user's feedback, attachments, reservations and notifications return **404**, so their existence is not revealed.
- **Mass assignment:** forms read only whitelisted fields, so `role`, `auth_version` or `is_active` can never be set by a user. Self-registration always creates a STUDENT.
- **Admin self-protection:** an admin cannot change their own role or deactivate themselves, and the last active admin cannot be demoted or deactivated. Role changes, activations, decisions and deletions are written to `audit_logs`.
- **Development mailbox:** `/_dev/mail` answers only when `APP_ENV=local`, the request comes from loopback, and the user is an admin; otherwise it is a 404.

## Input handling and output encoding

- **SQL injection:** every query is a PDO prepared statement with emulation disabled. The database wrapper has no API for interpolating values. The only identifiers built into SQL are code constants (table/column names), and search terms are LIKE-escaped. The connection uses a strict SQL mode, so there is no silent truncation.
- **Validation:**
  - Declarative rules with translated messages.
  - Every field must be a single value: `field[]=x` is a validation error. The request accessors ignore arrays, so they can never reach string casts or the database.
  - Page numbers are capped and the page size is bounded.
  - Admin notes and cancellation reasons have maximum lengths.
- **XSS:** all template output goes through `e()` (`htmlspecialchars`, `ENT_QUOTES`, UTF-8). Embedded JSON uses the `JSON_HEX_*` flags. The JavaScript never uses `innerHTML` or `eval`. The CSP forbids inline scripts.
- **mailto: links:** addresses and subjects are percent-encoded (`mailto_href()`), so a stored address such as `a?bcc=x@evil.com` cannot add recipients or headers to an admin's reply link.
- **Open redirects:** "back" redirects accept only URLs inside `APP_URL`, and `?next=` after login accepts only local paths.

## CSRF

A global middleware checks a per-session token on every non-GET request with `hash_equals`. A missing or wrong token returns 419. Every form includes the token; the only state-changing GET requests are harmless (language choice, marking a message read). The security suite sends a request without a token to **every** POST route as a guest, a student and an admin.

## Concurrency and data integrity

- **Event and volunteering seats:** registration locks the activity row (`SELECT … FOR UPDATE`) before counting seats, so 12 simultaneous registrations for 3 seats produce exactly 3.
- **Facility bookings:**
  - Approval locks the facility and then the reservation (in that order, like every other decision), and re-checks for overlapping approved bookings.
  - The approval's consistency snapshot starts only after the lock is held, so two overlapping requests approved at the same moment can never both be approved.
  - Reject and cancel are conditional updates (`… WHERE status IN (expected)`), so a decision can never overwrite a concurrent one.
- **Volunteer and activity decisions:** completion (awarding hours), admin removal, attendance and activity cancellation apply only from the state they were decided in, so repeated or simultaneous clicks don't apply twice or send duplicate notifications. A volunteer removed by an admin cannot sign up again.
- **Hours** are awarded only when an admin approves completion, capped at 200 per registration.

## File uploads

- **Accepted types:** event, volunteering, facility and About images (JPEG, PNG, WebP), and complaint attachments (JPEG, PNG, WebP, PDF).
- **Checks:**
  - The type is detected from the file content with `finfo`, never from the client's name or MIME header.
  - Images must decode as real images; PDFs must start with `%PDF-`.
  - SVG, HTML, scripts and polyglot files are rejected.
  - A maximum size applies.
- **Storage:**
  - Files get random 128-bit names with an extension derived from the verified type.
  - Complaint attachments are stored **outside** the web root (`storage/`) and served only after an ownership check, as downloads with `nosniff`.
  - Public images live in `public/uploads/`, where PHP execution is disabled and scripts, SVG and HTML are denied, with a restrictive CSP on the files themselves.
  - File deletion is restricted to `uploads/`.

## HTTP headers

| Header | Value / rule |
|---|---|
| `Content-Security-Policy` | `default-src 'self'`; `script-src 'self'` (no inline scripts); `style-src 'self'` + Google Fonts; `img-src 'self' data:`; `frame-src https://www.google.com` (contact map); `frame-ancestors 'self'`; `form-action 'self'`; `base-uri 'self'`; `object-src 'none'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`, only on real HTTPS requests (a spoofed `X-Forwarded-Proto` from an untrusted peer is ignored) and never with `APP_ENV=local` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin`; `no-referrer` on the password-reset page |
| `Permissions-Policy` | camera, microphone and geolocation disabled |
| `Cache-Control` | `no-store, private` for application pages |
| `X-Powered-By` | removed by the application |

`X-Forwarded-For` / `X-Forwarded-Proto` are honoured only from the proxy addresses listed in `TRUSTED_PROXIES`; by default nobody is trusted.

## Errors and logging

- Visitors see translated error pages only; stack traces are shown only when `APP_ENV=local` **and** `APP_DEBUG=true`.
- PHP warnings are converted to exceptions; errors are logged to `storage/logs` (outside the web root) with a shortened stack trace. The database password never appears there: PHP 8.2 marks PDO's password parameter `#[SensitiveParameter]`, which hides it in traces.
- A missing `.env` produces a plain 503 "Service not configured"; the reason goes to the error log.

## Configuration and secrets

- **No secrets in the repository.** `.env` is git-ignored, and `.env.example` contains placeholders only, with production-safe defaults (`APP_ENV=production`, `APP_DEBUG=false`, SMTP mail).
- The application **fails closed**: without `DB_DATABASE` / `DB_USERNAME` it doesn't start, and `DB_USERNAME=root` is refused unless `APP_ENV=local`.
- **Demo data** is not part of the application. `database/demo/seed.php` is outside the Composer autoloader, refuses to run unless `APP_ENV=local`, returns 404 if reached over HTTP, and requires strong demo passwords from the developer's own `.env` (or generates random ones). No demo password is written in the code or the documentation.
- **Destructive developer commands** (`migrate --fresh`, the smoke-test suites) refuse to run unless `APP_ENV=local`.
- `.gitignore` covers `.env*`, `vendor/`, logs, development mail, uploads, dumps, archives, filled-in setup scripts and caches.

## Database privileges

The website connects with an account that has only `SELECT, INSERT, UPDATE, DELETE` on the application database. Schema changes use a separate migration account (`DB_MIGRATE_USERNAME`), ideally supplied only to the `migrate` command. `database/setup/create_app_user.sql` creates both, and `php bin/console db:check` reports any excess privilege (server-wide rights, DDL, `GRANT OPTION`, `PROXY`).

The whole test suite has been run through these two restricted accounts: PHPUnit, the HTTP end-to-end and security suites, and the page crawl.

## Web server hardening

- **Document root = `public/`.** Code, configuration, `storage/`, `vendor/` and tests are then outside the web root.
- When the project root must be web-served (e.g. a XAMPP folder), the root `.htaccess` routes into `public/` and explicitly denies dotfiles, dumps, logs, lock/config files, archives and application directories, and `storage/.htaccess` denies everything. Without `mod_rewrite`, everything is denied.
- **Version banners** must be disabled on the server. The application removes `X-Powered-By` itself, but the `Server` header comes from Apache:
  ```ini
  ; php.ini
  expose_php = Off
  display_errors = Off
  log_errors = On
  ```
  ```apache
  ServerTokens Prod
  ServerSignature Off
  TraceEnable Off
  ```
- Serve over HTTPS only (redirect port 80). Keep MariaDB bound to `127.0.0.1` with a root password, and keep backups and dumps outside the web root.

## How it is tested

| Suite | What it proves |
|---|---|
| PHPUnit unit tests | Validator (array input, every rule), request/proxy trust, HSTS conditions, router, SQL splitter, upload type detection, mailto encoding, privilege checker, demo-password strength, translations, dates |
| PHPUnit integration tests (real MariaDB) | Rate limits under **parallel processes** (16 limiter calls, 12 logins: never more than the limit), per-IP and cross-IP locks, refunds; reservation transitions, **parallel approve/reject/cancel** and **parallel overlapping approvals** (no double booking); Remember Me under overlapping requests; throttling of feedback, bookings and password checks; reset queue (no secrets, back-off, stale jobs); email change by link (nothing changes before confirmation, owner-only single-use links, expiry, address taken meanwhile, request limit, cancellation by password change/reset/deactivation, failed delivery); admin creation rules; legacy import of admins; volunteer and activity state guards |
| `tests/smoke/flows.php` | Registration, login and role check, throttling, Remember Me (rotation, grace, replay), password reset end to end, email change by link end to end, events, volunteering, reservations, feedback, contact, admin actions, pagination and search, i18n |
| `tests/smoke/security.php` | CSRF on every POST route for three roles, student 403 on every admin route, session fixation and logout, reset-token misuse, XSS on 15 pages, attachment access control, upload bypass attempts, 15 **simultaneous** HTTP logins, array input on ten forms, mailto injection, concurrency of seat allocation, and 28 sensitive paths returning 403/404 |
| `tests/smoke/crawl.php` | Every page for guest, student and admin in Arabic and English: status codes, CSP present, no untranslated keys |

Several concurrency tests were checked against the pre-fix code, and they fail there: the old login limiter let all 12 parallel guesses through, and the old approval code double-booked.

## Security review history

The code base was audited in September 2026 before publication. All findings were fixed:

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| H-1 | High | A delivery package shipped a demo administrator whose password was published | No accounts ship; `admin:create`; demo data moved to a local-only script with strong, private passwords |
| H-2 | High | The database connection used MySQL `root` | Restricted runtime account, separate migration account, `db:check`, `root` refused outside local |
| M-1 | Medium | Login rate limit bypassable with parallel requests; no per-account limit across IPs | Atomic count-then-decide limiter; per-account limit across IPs |
| M-2 | Medium | Reservation reject/cancel could overwrite a concurrent approval | Status-guarded transitions; same lock order everywhere; approval snapshot fix |
| M-3 | Medium | Unthrottled complaints (with attachments) and booking requests | Per-student limits; password re-check throttling |
| M-4 | Medium | Web-root protection relied only on a rewrite rule | Explicit deny rules; document root `public/` documented |
| M-5 | Medium | Imported legacy admins kept policy-free passwords | Unusable hash, reset required |
| L-1 | Low | No HSTS | HSTS on real HTTPS requests |
| L-2 | Low | Unclear failure without `.env` | Clear 503 / CLI message |
| L-3 | Low | Reset-form errors lost when no Referer is sent | Redirect back to the form's own page |
| L-4 | Low | Parallel Remember Me requests treated as theft | Row lock + 30-second rotation grace |
| L-5 | Low | Removed volunteers could re-register; unguarded admin state changes | Removal is final; status-guarded updates |
| L-6 | Low | Email change: old address not notified, other sessions kept | Old address notified; other sessions and devices signed out; the new address must be confirmed by a link first |
| L-7 | Low | mailto header injection in the admin reply link | Encoded mailto links |
| L-8 | Low | Reset timing revealed registered emails | Secret-free mail queue processed after the response |
| L-9 | Low | Array input and huge page numbers caused errors | Scalar-only input, page cap, note length limits |
| L-10 | Low | Server version banners | `X-Powered-By` removed; server settings documented |
| L-11 | Low | Demo seeding inside the application | Separate local-only script outside the autoloader |

## Known limitations

- The **per-account login limit** (20 per hour across all IPs) means an attacker who knows an email can temporarily lock that account; the owner can still reset the password by email. This trade-off was chosen to stop distributed password guessing.
- **Registration** tells the user when an email or student ID is already in use, as a usable form must; it is rate-limited per IP.
- There is **no two-factor authentication**.
- Under Apache's `mod_php`, queued emails are sent after the complete response has been delivered, but the worker process stays busy until they are sent. On busy servers use PHP-FPM, or `MAIL_QUEUE=cron` with `mail:work`.
- The CSP allows Google Fonts and a Google Maps frame (only when an address is configured).

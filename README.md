# College Events & Volunteering Platform — منصة الفعاليات والتطوع

> **Personal portfolio project. Not the official website of any institution.**
> Contact details in the seed data are fictitious (`+966500000000`, `@college.example`). All rights reserved — see [LICENSE](LICENSE).

A bilingual (Arabic / English, RTL / LTR) web platform for a technical college. Students browse and register for **events**, sign up for **volunteering opportunities that award certified hours**, **request facility bookings**, and send **suggestions and complaints**. Administrators run everything from an **admin panel**.

It is a ground-up rewrite of a small procedural PHP site into a layered, tested **PHP 8.2** application with no framework: a hand-written router, container, validator, session and CSRF layer on top of PDO. The point of the project is the engineering: clear layering, least-privilege database access, concurrency-safe business rules, and a test suite that exercises them.

- Design and audit of the original system: [`docs/01-audit-and-plan.md`](docs/01-audit-and-plan.md)
- Test report: [`docs/02-test-report.md`](docs/02-test-report.md)
- Decision log: [`docs/03-decisions.md`](docs/03-decisions.md)
- Installation, deployment and operations guide: [`docs/04-installation-and-operations.md`](docs/04-installation-and-operations.md)
- Security measures and review history: [`SECURITY.md`](SECURITY.md)

---

## Features

### Students
- **Accounts:** register with name, student ID, email, phone and an optional department; log in with **email or student ID**; **Remember me**; forgot / reset password by email; profile editing; password change; email change **confirmed by a link sent to the new address**.
- **Events:** browse and filter bilingual events by type, date and venue; register and unregister. Capacity is enforced under a database row lock, duplicates are impossible, and registration is closed for started, completed or cancelled events.
- **Volunteering:** opportunities in four areas (event management, design, translation, tech support) with an optional motivation text. **Hours are awarded only when an administrator approves completion**, and the student sees a running total.
- **Facility reservations:** request a date and time slot for the theater, events hall, stadium, gymnasium or any facility an admin adds; follow the decision; cancel a request.
- **Suggestions and complaints:** categorised, with an optional image or PDF attachment (stored outside the web root and served only to its owner and admins), status tracking and the admin's reply.
- **Dashboard and notifications:** statistics, upcoming activities and in-app notifications in the reader's language.

### Administrators
- **Dashboard** with live KPIs.
- **Events and volunteering:** create, edit, cancel (with a reason that is sent to every registrant) and delete; attendance marking; completion approval and hours; removal of a volunteer; cover images.
- **Registrations:** a cross-activity view.
- **Reservations:** approve, reject or cancel. Overlapping approved bookings are prevented, including simultaneous approvals.
- **Facilities:** create and edit, with photos.
- **Feedback:** status workflow (Open → In progress → Resolved / Closed), reply, archive.
- **Contact messages:** mark Read / Unread / Resolved.
- **Users:** search, change role, activate / deactivate, with self-protection and a **last-admin guard**; changes are written to an audit log.
- **Settings:** public contact details, address and the About-page photo.

### Arabic and English
Every string comes from `locales/{ar,en}/*.json` (19 files per language) with Arabic plural rules, localized dates and a full RTL layout (Bootstrap's RTL build). The language choice persists in a cookie and in the user's profile. `php bin/console i18n:check` reports keys missing in either language.

---

## Technology stack

| Layer | Technology |
|---|---|
| Language | PHP 8.2 (strict types, enums, readonly properties), no framework |
| Database | MariaDB 10.4+ / MySQL 8 through PDO (prepared statements, strict SQL mode), versioned SQL migrations |
| Front end | Server-rendered PHP templates and components, Bootstrap 5.3 (RTL build), Bootstrap Icons, flatpickr 4.6.13, a small vanilla-JS file, IBM Plex Sans Arabic |
| Mail | PHPMailer (SMTP) or a log driver for development, with a database-backed queue |
| Configuration | `vlucas/phpdotenv` |
| Testing | PHPUnit 11, plus HTTP end-to-end, security and page-crawl scripts |
| Dependency management | Composer (no Node toolchain) |

## Architecture

```
Request → public/index.php → App kernel
        → global middleware: session → authenticate (session / remember-me) → locale → layout data → CSRF
        → Router → route middleware (auth, guest, role:ADMIN / role:STUDENT, local)
        → Controller (thin) → Service (business rules) → Repository (PDO, prepared statements)
        → View (PHP templates + components) → Response (+ security headers)
        → after the response: deferred work (queued emails)
```

| Layer | Role | Size |
|---|---|---|
| `app/Http/Controllers` | Thin HTTP adapters: PublicSite, Auth, Account, Student, Admin | 29 classes |
| `app/Services` | Business rules and transactions: authentication, events and volunteering (shared `ActivityService`), reservations, feedback, contact, notifications, mail queue, users, uploads, settings, audit | 20 classes |
| `app/Repositories` | All SQL lives here | 9 classes |
| `app/Domain` | Enums (roles and statuses) and the `User` entity | 10 files |
| `app/Core` | Router, Request / Response, Session, CSRF, Database, Validator, Translator, View, RateLimiter, Paginator, Mail drivers, Container | 26 files |
| `app/Http/Middleware` | Session, authentication, roles, CSRF, locale, layout data | 10 classes |
| `app/Console` | CLI commands behind `bin/console` | 7 files |

### Project structure

```
app/            application code (layers above)
bin/console     command line entry point
config/         app.php (settings read from .env) and routes.php (the route map)
database/       migrations/ (versioned SQL), setup/ (least-privilege DB accounts), demo/ (local-only demo data)
docs/           audit and plan, test report, decision log, installation guide
locales/        ar/ and en/ translation catalogues
public/         the only web-facing directory (document root): front controller, assets, uploads
storage/        logs, development mail, private uploads (never web-served)
tests/          Unit/, Integration/ and smoke/ suites
views/          layouts, components, pages and email templates
```

## Database architecture

23 InnoDB tables created by six ordered migrations (`database/migrations/001`–`006`), with 25 foreign keys, 14 unique keys and 10 `CHECK` constraints. The migrations do not set a character set themselves: the database must be `utf8mb4` / `utf8mb4_unicode_ci`, which `database/setup/create_app_user.sql` does for you (use the same settings if you create the database by hand).

| Area | Tables |
|---|---|
| Identity and sessions | `users`, `roles`, `departments`, `auth_remember_tokens`, `password_reset_tokens`, `email_change_requests` |
| Events | `events`, `event_types`, `event_registrations` |
| Volunteering | `volunteer_opportunities`, `volunteer_categories`, `volunteer_registrations` |
| Facilities | `facilities`, `facility_reservations` |
| Feedback and contact | `feedback`, `feedback_categories`, `feedback_attachments`, `contact_messages` |
| Platform | `notifications`, `settings`, `audit_logs`, `mail_queue`, `rate_limits` |

- **Migrations** are tracked by file name and applied with `php bin/console migrate`. The command with schema rights is separate from the website's account.
- **Integrity rules live in the schema as well as in PHP:** unique keys on event and volunteer registrations, `CHECK` constraints on time ordering, capacity and hours, and foreign keys with explicit delete behaviour. Actor columns use `SET NULL` so users and audit rows survive deletions.
- **Concurrency:** seat allocation, booking approval and volunteer decisions use row locks (`SELECT … FOR UPDATE`) and status-guarded updates, so simultaneous requests cannot over-book or double-apply.
- **Reference data** (roles, event types, volunteer categories, facilities, feedback categories, departments, settings) is seeded by migration 002 and contains no personal data.

## Security measures

Full list, with how each one is tested, in [`SECURITY.md`](SECURITY.md). In short:

- **Passwords and sessions:** Argon2id (bcrypt fallback), hardened session cookies (HttpOnly, SameSite, Secure on HTTPS, strict mode, ID regeneration), idle and absolute timeouts, and a version counter that ends other sessions after a password or email change.
- **Remember me and resets:** selector / validator tokens stored only as SHA-256 and rotated on use; password-reset and email-change links are single-use, expiring and stored hashed; reset requests give the same response whether or not the address exists.
- **Input and output:** prepared statements only, declarative validation (scalar-only, UTF-8 checked), HTML-escaped output, a strict Content-Security-Policy without inline scripts, and CSRF protection on every non-GET request.
- **Access control:** the role is reloaded from the database on every request; ownership checks answer 404; mass assignment is prevented by whitelisting; admin self-protection and a last-admin guard; an audit log.
- **Abuse protection:** database-backed atomic rate limits on login, registration, password reset, contact, feedback, bookings and password re-checks.
- **Uploads:** content-sniffed type, image decode or PDF signature check, random names, private attachments outside the web root, no PHP execution in `public/uploads/`.
- **Configuration:** no secrets in the repository, production-safe defaults, `root` refused as the database user outside local development, least-privilege database accounts checked by `php bin/console db:check`.

**Known limitations** (also in `SECURITY.md`): there is no two-factor authentication; registration does not verify the email address; the per-account login limit can be used to lock a known account out temporarily; Google Fonts and an optional Google Maps frame are third-party requests allowed by the CSP.

---

## Quick start (local, XAMPP on Windows)

Prerequisites: PHP 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo` and `json`; MariaDB 10.4+ or MySQL 8; Apache 2.4 with `mod_rewrite`; Composer.

1. **Put the project under `C:\xampp\htdocs\`**, for example `C:\xampp\htdocs\<project folder>`, so it is served at `http://localhost/<project folder>`. The root `.htaccess` routes everything into `public/`.
2. **Keep the development server off your network.** In `C:\xampp\apache\conf\httpd.conf` change `Listen 80` to `Listen 127.0.0.1:80`, then restart Apache.
3. **Install dependencies:** `composer install` (this also copies Bootstrap and the icons into `public/assets/vendor`).
4. **Create the database and accounts.** Copy `database/setup/create_app_user.sql` to `create_app_user.local.sql` (ignored by git), replace the two `CHANGE_ME` values with long random passwords, and run it as the MariaDB administrator. It creates the database and two accounts: one with data access only for the website, one with schema rights for migrations. For quick local work you may use XAMPP's `root`, which is accepted only with `APP_ENV=local`.
5. **Configure the environment:** `copy .env.example .env`, then set at least:

   ```ini
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost/<project folder>
   MAIL_DRIVER=log
   DB_DATABASE=<your database>
   DB_USERNAME=<website account>
   DB_PASSWORD=<its password>
   DB_TEST_DATABASE=<a separate database for the tests>
   ```

   `.env` is git-ignored. `.env.example` contains no secrets.
6. **Create the schema:** run `php bin/console migrate` (with the migration account, as described in the [installation guide](docs/04-installation-and-operations.md)), then `php bin/console db:check` to confirm the website account has data access only.
7. **Create the first administrator:** `php bin/console admin:create` (the password is typed with hidden input, never passed as an argument).
8. *(Optional, local only)* **Demo data:** `php database/demo/seed.php`. Demo passwords come from your own `.env` or are generated and printed once; none is stored in the repository.
9. Open `http://localhost/<project folder>`.

With `MAIL_DRIVER=log`, emails (such as password-reset links) are written to `storage/mail/` and can be read at `/_dev/mail`. That viewer answers only for an admin on `127.0.0.1` with `APP_ENV=local`.

Production installation (document root, HTTPS, Apache and nginx examples, scheduled tasks, hiding server versions, deployment checklist), all console commands and every environment variable are in the **[installation guide](docs/04-installation-and-operations.md)**.

## Testing

```bash
composer test                     # PHPUnit: unit + integration (uses DB_TEST_DATABASE)
php tests/smoke/flows.php         # end-to-end behaviour over HTTP against the running site
php tests/smoke/security.php      # CSRF and role matrix, XSS, sessions, tokens, uploads, rate limits, exposure
php tests/smoke/crawl.php         # every page as guest, student and admin, in Arabic and English
php bin/console i18n:check        # every translation key exists in both languages
```

- **PHPUnit:** 15 unit and 17 integration test files. The integration tests run against a real, disposable database (they drop and re-create `DB_TEST_DATABASE` and refuse to run if it equals `DB_DATABASE`). Several start parallel PHP processes to show that the rate limits, reservation decisions, last-admin guard, activity deletion and Remember Me hold under concurrency.
- **Smoke suites** need `APP_ENV=local`, the running site and the demo data; they tag their records and delete them afterwards.
- **Last full run** (2026-10-01): 181 PHPUnit tests (868 assertions), 97 end-to-end checks, 43 security checks, 104 pages crawled, all passing; `composer audit` reported no advisories.

## Screenshots

Screenshots are not included yet. They will be added under `docs/screenshots/` (public home, event list and detail, student dashboard, facility booking form, admin dashboard and reservations queue, in Arabic and English).

## Development status

All the features above are implemented and covered by the suites described in [Testing](#testing). The project is maintained as a portfolio piece; the remaining ideas are listed in the known limitations (two-factor authentication, email verification at registration) and in [`docs/03-decisions.md`](docs/03-decisions.md).

## Project history

The original project (a small procedural PHP site) was audited and replaced by this application. The importer in `app/Console/LegacyImporter.php` (`php bin/console migrate:legacy`) can read the old database and only reads it. The original source and its database dump are **not** part of this repository because they contain personal data and insecure code.

## License

All rights reserved. See [LICENSE](LICENSE). Third-party components keep their own licenses.

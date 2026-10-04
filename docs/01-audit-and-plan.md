# Technical College Platform — Audit, Architecture & Migration Plan

Status: **Phase 1–4 deliverable (analysis & design). No implementation yet.**
Date: 2026-09-24

Everything below was verified by reading the source and querying the live `project` database on this machine (MariaDB 10.4.32, PHP 8.2.12, XAMPP). Where the live database differs from `project.sql`, the live database is treated as the source of truth.

---

## A. Existing Project Audit

### A.1 Technology stack (as found)

| Layer | Found |
|---|---|
| Server | XAMPP: Apache 2.4 (mod_rewrite on, `AllowOverride All`), PHP 8.2.12 |
| Database | MariaDB 10.4.32, DB `project`, `utf8mb4_general_ci`, **non-strict** `sql_mode` (`NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`) |
| DB access | `mysqli`, mostly prepared statements, some raw `query()` |
| UI | Bootstrap **5.3.0 and 5.3.1** (two versions), Bootstrap Icons **and** Font Awesome 4.7, custom `style.css` (1,565 lines) and `style1.css` |
| Dependencies | Composer → `phpmailer/phpmailer ^7.1` (used only by forgot-password) |
| PHP extensions | `pdo_mysql` ✔, `intl` ✘ (not loaded), `sodium` ✘, Argon2id ✔ |
| Tooling | Composer ✔, Git ✔ (repo has **zero commits**), **Node.js not installed** |

### A.2 Folder structure (as found)

```
project/
├── *.php (18 page scripts, flat, each mixes HTML + SQL + logic)
├── admin/ dashboard.php, events.php, complaints.php   ← second admin panel
├── admin.php                                           ← first admin panel
├── css/ style.css, style1.css
├── images/ 14 images (logo, TVTC svg, facility photos, service icons)
├── vendor/ (PHPMailer)
├── project.sql (out of sync with the live DB)
└── .vscode/
```

### A.3 Pages and what they do

| File | Access | Purpose | Writes to |
|---|---|---|---|
| `index.php` | public | Landing page: hero, 4 volunteering areas (events mgmt, design, translation, tech support), suggestions/complaints teaser, 4 facilities (event hall, stadium, gym, theater), events teaser, contact info + contact form | `contact` (via contact.php) |
| `login.php` | public | Login with trainee/trainer radio, Remember Me checkbox (non-functional) | session |
| `signup.php` | public | Register (username, email, password, confirm) | `users` |
| `forgot-password.php` / `reset-password.php` | public | Reset via emailed token | `users.reset_token` |
| `home.php` | login | Student home: welcome, lists **all** events | — |
| `hopage.php` | login | Near-duplicate of `home.php` (dead page, not linked) | — |
| `tadwapage.php` → `reg.php` | login | Volunteering: lists opportunities; separate form to apply by *type* (not a specific opportunity) | `register` |
| `revers.php` | login | Facility reservation form (facility dropdown + free text) | `hajz` |
| `issue.php` | login | Suggestions & complaints form | `problem` |
| `contactpage.php` → `contact.php` | login / public | Contact form | `contact` |
| `edit.php` | login | Edit name/email/password | `users` |
| `logout.php` | any | Destroy session | — |
| `admin.php` | trainer | Admin panel #1: add events/opportunities, last 10 registrations & reservations | `events`, `volunteer_opportunities` |
| `admin/dashboard.php`, `events.php`, `complaints.php` | trainer | Admin panel #2: add activities, list complaints | `events`, `volunteer_opportunities` |

### A.4 Existing database tables (live)

| Table | Rows | Structure notes |
|---|---|---|
| `users` | 2 (1 trainer with the college admin address, 1 trainee) | `username`, `email` (UNIQUE **twice** — duplicate index), `password` (bcrypt `$2y$`, good), `role enum(trainee,trainer)`, plaintext `reset_token` |
| `events` | 2 | `title`, `description`, `event_date` (date only, no time/location/capacity) |
| `volunteer_opportunities` | 0 | same shape as events |
| `register` | 0 | volunteer applications: `name,email,major,id,msg` — **no PK**, no FK to user or opportunity |
| `hajz` | 1 | reservations: `name,email,hgz,id,msg` — **no PK, no date/time, no status** |
| `problem` | 0 | complaints: `name,email,type,msg varchar(100)` — **no PK, no date, no status** |
| `contact` | 2 | `name,email,subject,message varchar(255)` — no date, no status |

### A.5 Authentication mechanism (as found)

- PHP native file sessions, default cookie settings (**`cookie_httponly` empty, no SameSite, `use_strict_mode=0`**).
- Login: `SELECT … WHERE email=? AND role=?` then `password_verify`. The selected role *is* checked against the database (good), but the error messages distinguish "Wrong Password" from "Wrong Email", which leaks which accounts exist.
- `security.php` provides CSRF tokens, `e()` escaping, `require_login()` (re-reads user from DB) and `require_role()`.
- `admin/*.php` does **not** use `require_role()`; it only trusts `$_SESSION['role']`, so a demoted trainer keeps admin access until the session ends.
- Remember Me: checkbox only, **no implementation** (and its `<label for="remember">` points to a non-existent id).
- Password reset: works in principle, but see A.7.

### A.6 What works reasonably well (credit where due)

- Passwords hashed with `password_hash()` (bcrypt).
- Most writes use prepared statements; CSRF tokens exist on forms; output mostly escaped with `htmlspecialchars`.
- `session_regenerate_id(true)` on login.
- Forgot-password gives a generic response (no email enumeration there).

### A.7 Verified bugs and broken functionality

| # | Where | Problem | Effect |
|---|---|---|---|
| 1 | `login.php` | Remember Me not implemented; label `for` mismatch | Feature is fake |
| 2 | `login.php`, `signup.php` | Password eye toggle calls `classList.replace("fa-eye-slash","fa-eye")` on an element that is already `fa-eye`; confirm-password eye has no handler | Icon never changes; second eye is dead |
| 3 | `signup.php` | Any validation failure (short password, empty name, bad email) shows "Password does not match" | Misleading errors |
| 4 | `revers.php` / `hajz` | Reservation has **no date or time** | Feature can't actually reserve anything; conflicts impossible to detect |
| 5 | `revers.php`, `reg.php` | Dropdowns store `first/second/third/fourth` instead of meaningful IDs | Data is meaningless without the source code |
| 6 | `tadwapage.php` / `register` | Student applies to a *type*, not to an opportunity; no link to the user | Can't track who volunteered where, or hours |
| 7 | `issue.php`, `contact.php` | `msg varchar(100)` / `message varchar(255)` with **non-strict** SQL mode | Longer messages are **silently truncated** (data loss) |
| 8 | `admin.php` | Inserts volunteer opportunity without `event_date` (column is NOT NULL) | Stores `0000-00-00`/warning; bad data |
| 9 | `edit.php` | Changing email to an existing one throws an uncaught `mysqli_sql_exception` | HTTP 500 with `display_errors=1` → stack trace shown |
| 10 | `edit.php` | Password change needs no current password and has no length rule | Session hijack → account takeover; weak passwords |
| 11 | `forgot-password.php` | Link built from `HTTP_HOST` header and hard-coded `http://…/project/` | Host-header poisoning; wrong URL in other setups |
| 12 | `forgot-password.php` | SMTP creds via `getenv()` but nothing loads a `.env` | Email never sends locally; error swallowed silently |
| 13 | `reset-password.php` | Token stored in plaintext; other sessions not invalidated after reset | DB leak = account takeover; attacker stays logged in |
| 14 | `reg.php`, `contact.php` | CSRF check runs before `isset($_POST['submit'])`; students type name/email freely | Anyone logged in can submit on behalf of any email |
| 15 | Navbars | Edit-profile link shows `edit.php?id=N` (ignored by the code) | Misleading, looks like an IDOR |
| 16 | `home.php` etc. | `SELECT *` from users (incl. password hash) just to show the name | Needless exposure |
| 17 | `home.php`, `admin/*` | Lists **all** rows, no pagination, past events shown as upcoming | Won't scale; wrong info |
| 18 | `logout.php` | `session_destroy()` without clearing the cookie; logout via GET | Stale cookie; CSRF-able logout |
| 19 | Two admin panels | `admin.php` and `admin/` do the same thing differently | Confusing, inconsistent authz |
| 20 | `project.sql` | Differs from the live DB (e.g. `AUTO_INCREMENT`, duplicate email index) | Fresh installs don't match production |
| 21 | `index.php` | `<html lang="en">` without `dir="rtl"` on an Arabic page | Wrong screen-reader language, bidi issues |

## B. Problems Found (by category)

**Security**
- No login rate limiting → password brute force.
- Session cookie not `HttpOnly`/`SameSite`; `use_strict_mode` off → session fixation risk.
- `display_errors=1` → stack traces and paths are exposed (bug #9).
- Admin authorization inconsistent (#19, A.5).
- Plaintext reset tokens, host-header poisoning (#11, #13).
- No current-password check on profile change (#10).
- Unauthenticated identity fields on forms (#14).
- Login error messages allow account enumeration.
- SQL injection: **no exploitable injection found.** The two raw queries interpolate `(int)` casts or constants. They will still be replaced with prepared statements.

**Database design**
- 4 of 7 tables have no primary key; no foreign keys at all; no timestamps on most tables.
- Codes stored as `first…fourth`; free-text types; `varchar(100)` for messages.
- No statuses (reservation, complaint, contact), no link between registrations and users or opportunities.
- Duplicate `UNIQUE(email)` index. Non-strict SQL mode hides errors.
- Events/opportunities lack time, location, capacity, bilingual content.

**UX / UI**
- Arabic-only, hard-coded text; English error messages mixed into Arabic pages ("Wrong Password", "Go Back").
- Navbar copy-pasted in 7 files; two icon libraries; two Bootstrap versions.
- Result pages are dead ends ("Go Back" buttons pointing to the wrong page).
- Every page title is "Homepage"; `<center>` tag; placeholders used instead of labels (accessibility).
- No loading, empty or error states; no feedback on submission status.

**Maintainability**
- Presentation, SQL and logic mixed in every file; ~70% duplicated markup.
- No config/env separation (DB credentials hard-coded in `connection.php`).
- No tests, no git history.

## C. Recommended Architecture

### C.1 Options compared

| Criterion | **A) Modern PHP (refactor)** | B) React + Node/Express + Prisma |
|---|---|---|
| Runs on the current machine | ✔ Yes, XAMPP as-is | ✘ Node.js isn't installed; needs a new toolchain, a build step and 2 processes |
| Reuse of existing work | ✔ Keeps PHP, MariaDB, Bootstrap, images, PHPMailer, bcrypt hashes | Partial: DB and images only |
| Deployment for a college project | ✔ Any shared PHP host / XAMPP | Needs a Node host + static host + CORS/cookie config |
| Complexity (1–2 developers) | Moderate | High: two codebases, TS types on both sides, SPA auth, SEO |
| Security surface | Server-rendered, same-origin cookies, simple CSRF | Token/cookie handling across origins, CORS |
| Learning value | Real MVC, service layer, DI, migrations, PHPUnit | Modern JS ecosystem |
| RTL/i18n | Bootstrap 5 ships an official RTL build | Tailwind logical properties + i18next; equally good |
| Future SPA / mobile app | Possible later: services are UI-agnostic, so a JSON API layer can be added | Native |

### C.2 Recommendation: **Option A — refactor into a modern, layered PHP 8.2 application**

Why: the project is small to medium (~15 screens), the college environment is XAMPP, Node isn't available, and the existing data, hashes and assets are PHP/MariaDB native. The quality problems come from **structure**, not from the language: no layers, no config, no i18n, no schema. A disciplined PHP architecture fixes all of them with far less risk and deploys anywhere. The design keeps a clean service layer, so a REST/JSON API (and a React front end) can be added later without rewriting business logic.

**Stack**

| Concern | Choice |
|---|---|
| Language | PHP 8.2 (`strict_types`, enums, readonly properties, typed properties) |
| Structure | Small custom MVC with Composer PSR-4 autoloading: front controller → router → middleware → controller → service → repository (PDO) → MariaDB |
| DB access | PDO, prepared statements only, `ERRMODE_EXCEPTION`, `STRICT_ALL_TABLES` set per connection, transactions with `SELECT … FOR UPDATE` for capacity/conflict checks |
| Views | Plain PHP templates with layouts, partials and reusable components; auto-escaping helper |
| CSS | Bootstrap 5.3 (**`bootstrap.rtl.min.css` when Arabic**) + one design-token stylesheet; Bootstrap Icons only; served locally via Composer (no Node) |
| JS | Small vanilla ES modules (password toggle, confirm dialogs, toasts, fetch-enhanced forms); every form still works without JS |
| i18n | JSON files `locales/{ar,en}/*.json`, `t()` helper with parameters and Arabic plural rules, custom date/time formatter (`intl` isn't loaded) |
| Mail | PHPMailer. `MAIL_DRIVER=log` writes emails to `storage/mail/` in development (with a dev-only viewer); `smtp` in production |
| Config | `.env` + `.env.example` (`vlucas/phpdotenv`) |
| Tests | PHPUnit: unit tests (status logic, validation, i18n, token logic) and integration tests (against a `*_test` database) |

A framework like Laravel was considered. It would also be a valid choice, but it hides most of the architecture you're meant to build and learn in a college project and adds a large dependency tree. The custom core is small (~10 classes), fully typed, and documented. If you'd prefer Laravel, the schema and plans below carry over unchanged.

## D. ERD Description

```
roles 1───* users *───1 departments
users 1───* auth_remember_tokens
users 1───* password_reset_tokens
users 1───* notifications

event_types 1───* events *───1 users(created_by)
events 1───* event_registrations *───1 users

volunteer_categories 1───* volunteer_opportunities *───1 users(created_by)
volunteer_opportunities 1───* volunteer_registrations *───1 users
                                          volunteer_registrations *───1 users(reviewed_by)

facilities 1───* facility_reservations *───1 users(requester)
                                    facility_reservations *───1 users(decided_by)

feedback_categories 1───* feedback *───1 users(submitter)
feedback 1───* feedback_attachments

contact_messages *───0..1 users (if sent while logged in)
settings (key/value), rate_limits, audit_logs *───1 users(actor)
```

Rules:
- A student registers **at most once** per event or opportunity (`UNIQUE(event_id,user_id)`); a cancelled registration can be re-activated.
- Capacity is enforced inside a transaction that locks the parent row.
- A facility can't have two **APPROVED** reservations that overlap in time.
- Volunteer hours = `SUM(hours_awarded)` over `COMPLETED` volunteer registrations. Hours are awarded only when an admin approves completion.

## E. New Database Schema

New database: **`college_platform`** — **21 application tables + `schema_migrations`** (22 in total). The legacy `project` DB stays untouched as a read-only source (see F). All tables are InnoDB, `utf8mb4_unicode_ci`, with `created_at`/`updated_at` on mutable tables.

```sql
roles(id TINYINT PK, code VARCHAR(20) UNIQUE)                -- STUDENT, ADMIN

departments(id PK, name_ar, name_en, is_active, timestamps)

users(
  id INT PK, full_name VARCHAR(120), student_id VARCHAR(20) NULL UNIQUE,
  email VARCHAR(190) UNIQUE, phone VARCHAR(20) NULL,
  password_hash VARCHAR(255), role_id FK→roles, department_id FK→departments NULL,
  preferred_locale ENUM('ar','en') DEFAULT 'ar',
  is_active BOOL DEFAULT 1, auth_version INT DEFAULT 1,       -- bumped on password change → kills other sessions
  last_login_at DATETIME NULL, created_at, updated_at,
  INDEX(role_id), INDEX(is_active))

auth_remember_tokens(id PK, user_id FK CASCADE, selector CHAR(24) UNIQUE,
  validator_hash CHAR(64), expires_at, user_agent VARCHAR(255), last_used_at, created_at,
  INDEX(user_id), INDEX(expires_at))

password_reset_tokens(id PK, user_id FK CASCADE, token_hash CHAR(64) UNIQUE,
  expires_at, used_at NULL, requested_ip VARBINARY(16), created_at, INDEX(user_id))

event_types(id PK, code UNIQUE, name_ar, name_en)             -- lecture, workshop, sports, cultural, competition, other

events(
  id PK, title_ar, title_en, description_ar TEXT, description_en TEXT,
  image_path NULL, event_type_id FK, location_ar, location_en,
  start_datetime DATETIME, end_datetime DATETIME, capacity INT UNSIGNED,
  cancelled_at DATETIME NULL, cancel_reason NULL,
  created_by FK→users, created_at, updated_at,
  CHECK (end_datetime > start_datetime), CHECK (capacity > 0),
  INDEX(start_datetime), INDEX(end_datetime), INDEX(event_type_id))

event_registrations(
  id PK, event_id FK CASCADE, user_id FK CASCADE,
  status ENUM('REGISTERED','ATTENDED','CANCELLED'),
  registered_at, cancelled_at NULL, updated_at,
  UNIQUE(event_id,user_id), INDEX(user_id,status))

volunteer_categories(id PK, code UNIQUE, name_ar, name_en)    -- event_management, design, translation, tech_support (from legacy)

volunteer_opportunities(
  id PK, category_id FK, title_ar, title_en, description_ar, description_en,
  location_ar, location_en, start_datetime, end_datetime,
  volunteer_hours DECIMAL(5,2), capacity INT UNSIGNED,
  cancelled_at NULL, created_by FK, created_at, updated_at, CHECKs, INDEXes as events)

volunteer_registrations(
  id PK, opportunity_id FK CASCADE, user_id FK CASCADE,
  status ENUM('REGISTERED','ATTENDED','COMPLETED','CANCELLED'),
  motivation TEXT NULL,                                        -- legacy "msg"
  hours_awarded DECIMAL(5,2) NULL, reviewed_by FK→users NULL, completed_at NULL,
  created_at, updated_at, UNIQUE(opportunity_id,user_id), INDEX(user_id,status))

facilities(
  id PK, code UNIQUE, name_ar, name_en, description_ar, description_en,
  location_ar, location_en, capacity INT UNSIGNED NULL, image_path NULL,
  is_active BOOL, created_at, updated_at)                      -- seeded: theater, event hall, stadium, gym

facility_reservations(
  id PK, facility_id FK, user_id FK, purpose VARCHAR(200), notes TEXT NULL,
  start_datetime, end_datetime, expected_attendees INT UNSIGNED NULL,
  status ENUM('PENDING','APPROVED','REJECTED','CANCELLED'),
  admin_note TEXT NULL, decided_by FK NULL, decided_at NULL, created_at, updated_at,
  CHECK(end_datetime > start_datetime),
  INDEX(facility_id,status,start_datetime,end_datetime), INDEX(user_id))

feedback_categories(id PK, code UNIQUE, name_ar, name_en)     -- academic, facilities, services, events, other

feedback(
  id PK, user_id FK NULL, submitter_name NULL, submitter_email NULL,  -- snapshot for legacy rows only
  type ENUM('SUGGESTION','COMPLAINT'), category_id FK, subject VARCHAR(200), message TEXT,
  status ENUM('OPEN','IN_PROGRESS','RESOLVED','CLOSED') DEFAULT 'OPEN',
  admin_reply TEXT NULL, replied_by FK NULL, replied_at NULL,
  archived_at NULL, created_at, updated_at, INDEX(status,type,created_at), INDEX(user_id))

feedback_attachments(id PK, feedback_id FK CASCADE, original_name, stored_name UNIQUE,
  mime_type, size_bytes, created_at)                           -- stored outside the web root, served via an authz check

contact_messages(id PK, user_id FK NULL, name, email, subject, message TEXT,
  status ENUM('UNREAD','READ','RESOLVED') DEFAULT 'UNREAD', ip VARBINARY(16) NULL,
  created_at, updated_at, INDEX(status,created_at))

notifications(id PK, user_id FK CASCADE, type VARCHAR(50), data JSON, link VARCHAR(255) NULL,
  read_at NULL, created_at, INDEX(user_id,read_at,created_at))
  -- stores a translation key + params, rendered in the reader's current language

settings(`key` VARCHAR(64) PK, value TEXT, updated_at)          -- contact phone/email/address (from legacy homepage), site name
rate_limits(bucket VARCHAR(128) PK, hits INT, reset_at DATETIME)
audit_logs(id PK, actor_id FK NULL, action VARCHAR(64), subject_type, subject_id, meta JSON, created_at)
```

**Status design decision (events and opportunities).** The requirement asks for statuses `UPCOMING / ONGOING / COMPLETED / CANCELLED`, with automatic calculation and a CANCELLED override. Storing a computed status would go stale unless a cron job ran. Instead, the only *stored* fact is `cancelled_at`; the status is derived in one place, as a SQL `CASE` expression used for filtering and sorting and mirrored by a PHP enum method:

```
CASE WHEN cancelled_at IS NOT NULL THEN 'CANCELLED'
     WHEN NOW() < start_datetime   THEN 'UPCOMING'
     WHEN NOW() <= end_datetime    THEN 'ONGOING'
     ELSE 'COMPLETED' END
```

Reservations use the same idea for `COMPLETED` (an approved reservation whose end time has passed). All five statuses show in the UI and filters, but none can drift.

## F. Migration Plan

**Approach:** new database `college_platform` created from versioned SQL migrations (`database/migrations/NNN_*.sql`, tracked in a `schema_migrations` table). Then an idempotent **legacy importer** (`php bin/console migrate:legacy`) reads from the untouched `project` DB and prints a report. Rollback = point `.env` back to `project` and restore the legacy files from git.

| Legacy | → New | Mapping and reasoning |
|---|---|---|
| `users` (2) | `users` | `username→full_name`, `email` kept, `password→password_hash` (existing bcrypt hashes are valid and upgraded to Argon2id on next login), `trainer→ADMIN`, `trainee→STUDENT` — **mapped based on legacy authorization behavior**: in the old code `trainer` was the only role admitted to the admin panel (`legacy/admin.php:5`, `legacy/admin/*.php:5`) and was redirected there at login (`legacy/login.php:50`). The legacy database only defines `enum('trainee','trainer')`; no requirement document defines what a trainer is. Only one legacy account was mapped to ADMIN: id 62, the college admin address (role `trainer`). The mapping is unchanged pending confirmation. `student_id`/`phone` left NULL and the student is prompted to complete their profile. `reset_token`s are **discarded** (plaintext, insecure). |
| `events` (2) | `events` | `title→title_ar` **and** `title_en` (copied, flagged "needs translation"), same for description. `event_date` → `start_datetime = date 08:00`, `end_datetime = date 14:00` (college day), `location = "TBD / يُحدَّد لاحقًا"`, `capacity = 100`, `event_type = other`, `created_by` = first admin. Every row is listed in the migration report so an admin can correct times and location. |
| `volunteer_opportunities` (0) | `volunteer_opportunities` | Same as events; `volunteer_hours = 0` flagged. Rows with `0000-00-00` dates are skipped and reported. |
| `register` (0) | `volunteer_registrations` | Legacy rows apply to a *category*, not an opportunity. The importer creates one archived, cancelled opportunity per category, "Legacy applications — <category>", and attaches rows matched to a user by email (`first→event_management, second→design, third→translation, fourth→tech_support`). `msg→motivation`, `id→users.student_id` if empty. Unmatched rows are reported. |
| `hajz` (1) | `contact_messages` (archive) | `hgz` code → facility name (`first=theater, second=event hall, third=stadium, fourth=gym`, from `revers.php`). **Legacy requests have no date/time and no status**, both of which a real reservation requires. Inventing dates would create fake bookings that block real ones in conflict checks. So each row is preserved as a `contact_messages` record (status `READ`, subject "Legacy facility request — <facility>", body = name, student ID and original message, `user_id` linked when the email matches). Admins can see it and ask the student to rebook. Nothing is lost, and no fake reservation or account is created. |
| `problem` (0) | `feedback` | `type` text containing "اقتراح"/"suggest" → `SUGGESTION`, otherwise `COMPLAINT`; `type` text → `subject`; `msg → message`; category `other`; status `OPEN`; user matched by email, else `submitter_name/email` snapshot. |
| `contact` (2) | `contact_messages` | Direct copy; status `READ` (they pre-date the system); `created_at` = import time (the original date is unknown and is noted in the report). |

## G. Authentication Flow

1. **Register** (`POST /register`): validate full name (2–120), student ID (pattern, unique), email (valid, unique), phone (Saudi/intl pattern), password policy (≥ 8 chars, letters + digits, not equal to email), confirmation, optional department. Stored with `password_hash(PASSWORD_ARGON2ID)`, role always `STUDENT`, then auto-login and a welcome notification.
2. **Login** (`POST /login`) with identifier = email **or** student ID, password, selected role (Student/Admin), and Remember Me:
   1. Rate-limit check: 5 failures per (identifier + IP) per 15 min, 30 per IP per 15 min → HTTP 429 message.
   2. Look up by email or student ID. If not found, still run `password_verify` against a dummy hash (constant timing).
   3. Wrong password or not found → one generic message, "Invalid credentials".
   4. Account disabled → "Account disabled".
   5. **Password correct but the selected role ≠ the DB role** → rejected: "This account does not have administrator access" (or the student equivalent). No session is created. The frontend choice is only a *claim*; the DB role is the authority.
   6. Success: `session_regenerate_id(true)`; store `user_id`, `auth_version`, `login_at`; rehash if needed; `last_login_at`; issue a remember token if requested; redirect by **DB role** (`/admin/dashboard` or `/student/dashboard`), or to a safe same-origin `?next=`.
3. **Every request**: the middleware loads the user by `user_id`, then checks `is_active` and `auth_version` equals the session's value, plus an idle timeout (2 h) and an absolute timeout (12 h) for normal sessions. Any failure logs the user out.
4. **Logout** (`POST /logout` + CSRF): delete the current remember token, clear the session and cookie, regenerate the ID.
5. **Session cookie hardening** (set in code): `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS, `use_strict_mode=1`, `use_only_cookies=1`, custom cookie name.

## H. Authorization Flow

- Route groups declare middleware: `guest` (login/register pages), `auth` (any logged-in user), `role:STUDENT`, `role:ADMIN`.
- `/admin/*` → `auth` + `role:ADMIN`. The role is always read from the DB on each request, never from the form or a cached session value. An unauthenticated visitor is redirected to `/login?next=…`; an authenticated non-admin gets a translated **403 page**.
- Ownership checks live in services, e.g. a student can cancel only **their own** registration or reservation, and can view only their own feedback and attachments.
- **Admin self-protection** (UserService): an admin can't change their own role or deactivate themselves, and the system refuses any change that would leave **zero active admins**. Role changes require a confirm dialog, need CSRF, and are written to `audit_logs`.
- All state-changing requests are `POST` with CSRF; `PUT`/`DELETE` semantics use a method-override field.

## I. Password Reset Flow

1. `GET/POST /forgot-password`: the email is validated; rate limit is 3 per email and 10 per IP per hour. The response is **always the same** ("If the email is registered, a link has been sent").
2. If the user exists and is active: delete their previous unused tokens, generate `token = bin2hex(random_bytes(32))`, store **only `sha256(token)`** with `expires_at = now + 60 min`.
3. Email (in the user's preferred language) with `APP_URL/reset-password/{token}`. `APP_URL` comes from config, never from the `Host` header.
4. `GET /reset-password/{token}`: hash, look up, check not expired and `used_at IS NULL` → show the form, otherwise a translated "link invalid or expired" page with a "Request a new link" button. The page sends `Referrer-Policy: no-referrer` so the token doesn't leak.
5. `POST`: validate password policy + confirmation. In one transaction: update `password_hash`, set `used_at`, delete all remember tokens, and bump `auth_version` (logs out every other session). Then a notification "Your password was changed" and a redirect to `/login` with a success flash.
6. **Dev mail**: `MAIL_DRIVER=log` saves each email as HTML + `.eml` in `storage/mail/`. A dev-only page, `/_dev/mail`, lists them. It answers only when `APP_ENV=local` **and** the request comes from loopback (127.0.0.1/::1, so never via the LAN IP) **and** the visitor is a logged-in ADMIN; otherwise 404/login/403. Mail files are never web-served. No real SMTP is needed.

## J. Remember Me Flow

- **Unchecked** → session cookie with no `Expires` (ends when the browser closes) + server idle timeout (2 h).
- **Checked** → after login, create `selector` (12 random bytes, hex) and `validator` (32 random bytes). The DB stores `selector` + `sha256(validator)`, expiring in 30 days. The cookie `remember=selector:validator` is `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, `Path=/project`, and lasts 30 days.
- **Auto-login**: when there is no session but the cookie is present → look up by selector, compare with `hash_equals(sha256(validator), stored)`, check expiry and that the user is active:
  - match → new session (regenerate ID), **rotate** the validator (new value in the cookie and DB), mark `remembered=true`;
  - selector found but validator mismatch → treat as **token theft**: delete *all* of that user's remember tokens and clear the cookie;
  - otherwise → clear the cookie.
- **Invalidation**: logout deletes that token; password change/reset deletes all of the user's tokens; deactivation blocks them; expired rows are purged.
- **Security implications**: the cookie grants access for 30 days on that device, so it is only a bearer secret, never the password. It can't be read by JS (HttpOnly). Only a hash is stored server-side, so a DB leak can't be replayed. Rotation shortens a stolen cookie's lifetime, and theft detection revokes all of the user's devices. Users on shared computers should leave it unchecked, and the login page has a hint saying so. Remember-me sessions can be restricted from sensitive actions (password change requires the current password anyway).

## K. Frontend Page Map (public + student)

| Route | Page | Notes |
|---|---|---|
| `/` | Home | Header, Hero, Upcoming Events (real DB), Volunteering, College Services (volunteering / facilities / feedback / contact, keeping the legacy homepage concepts and images), Statistics (real counts), How It Works, CTA, Footer |
| `/login` | Login | Student/Admin segmented choice, identifier, password + show/hide, Remember Me, Forgot, Create account, language switcher |
| `/register` | Create account | |
| `/forgot-password`, `/reset-password/{token}` | Password reset | |
| `/events`, `/events/{id}` | Events list/detail | Search, type, date range, status, location, pagination; register/cancel button with capacity `35 / 50` |
| `/volunteering`, `/volunteering/{id}` | Opportunities list/detail | Search, category, date, status, location; hours shown |
| `/facilities`, `/facilities/{id}` | Facilities (public view) | "Request reservation" → login |
| `/about` | About | |
| `/contact` | Contact form + college contact info (from `settings`) | Works for guests and logged-in users (prefilled) |
| `/student/dashboard` | Dashboard | Welcome, stats (registered events, completed events, volunteer hours), upcoming registered events, suggested opportunities, recent activity, notifications |
| `/student/registrations` | My event registrations | Tabs: Upcoming / Ongoing / Completed / Cancelled |
| `/student/volunteering` | My volunteering | Status + awarded hours, total hours |
| `/student/reservations`, `/student/reservations/new` | My reservations / new request | Facility, date, start and end time, purpose, attendees; conflict feedback |
| `/student/feedback`, `/student/feedback/new`, `/student/feedback/{id}` | Suggestions & complaints | Type, category, subject, message, attachment; status + admin reply |
| `/profile` | Profile (students and admins) | Edit details, change password (requires current), language preference. `/student/profile` 301-redirects here |
| `/notifications` | Notifications | Mark read / all read; bell with unread count in the header |

Legacy URLs 301-redirect to the new routes: `login.php→/login`, `signup.php→/register`, `home.php→/student/dashboard`, `tadwapage.php→/volunteering`, `revers.php→/student/reservations/new`, `issue.php→/student/feedback/new`, `contactpage.php→/contact`, `edit.php→/student/profile`, `admin.php` & `admin/*→/admin/dashboard`.

## L. Admin Page Map

| Route | Page |
|---|---|
| `/admin/dashboard` | KPI cards (all real queries): total students, total events, upcoming events, total registrations, volunteer opportunities, awarded volunteer hours, pending reservations, open complaints, unread messages. Plus latest pending reservations and latest feedback. |
| `/admin/events` (+ `/create`, `/{id}`, `/{id}/edit`) | Table: Event, Date, Location, Capacity, Registered, Status, Actions (view, edit, cancel with reason, delete with confirm; delete is blocked when registrations exist, so admins cancel instead). Bilingual form with image upload. |
| `/admin/events/{id}/registrations` | Attendees; mark attended; CSV export |
| `/admin/volunteering` (+ create/edit/view) | Same as events + `/admin/volunteering/{id}/registrations`: mark attended, **approve completion** (awards hours, defaulting to the opportunity's hours, adjustable), cancel |
| `/admin/registrations` | Cross-event registration search |
| `/admin/reservations` | Filters (date, facility, status); approve (re-checks conflicts in a transaction), reject (with note), cancel |
| `/admin/facilities` | Manage facilities (create/edit/activate) |
| `/admin/feedback`, `/admin/feedback/{id}` | Filter by type/status/date/category; update status; reply (notifies the student); archive |
| `/admin/messages`, `/admin/messages/{id}` | Sender, email, subject, date, status; mark read/unread/resolved |
| `/admin/users` | Name, student ID, email, role, created at, status; search; activate/deactivate; change role (with self-protection) |
| `/admin/settings` | Contact info, site name, lookup lists (event types, volunteer categories, feedback categories, departments) |

## M. HTTP Route / API Map

The application is server-rendered, so there is no separate API server; routes are plain HTTP handlers. All mutating routes are `POST` + CSRF. The small set of JSON endpoints for progressive enhancement returns the envelope `{"success":true,"data":{…}}` / `{"success":false,"message":"…"}`.

```
Auth       GET/POST /login · GET/POST /register · POST /logout
           GET/POST /forgot-password · GET/POST /reset-password/{token}
Public     GET / · /about · /events · /events/{id} · /volunteering · /volunteering/{id}
           GET /facilities · /facilities/{id} · GET/POST /contact · GET /lang/{ar|en}
Student    POST /events/{id}/register · POST /events/{id}/unregister
           POST /volunteering/{id}/register · POST /volunteering/{id}/unregister
           GET /student/{dashboard,registrations,volunteering}
Account    GET/POST /profile · POST /profile/password
           GET/POST /student/reservations[/new] · POST /student/reservations/{id}/cancel
           GET/POST /student/feedback[/new] · GET /student/feedback/{id}
           GET /attachments/{id}
Notif.     GET /notifications · POST /notifications/{id}/read · POST /notifications/read-all
           GET /api/notifications/unread-count (JSON)
Admin      GET /admin/dashboard
           GET/POST /admin/events[/create] · GET /admin/events/{id}[/edit] · POST /admin/events/{id}
           POST /admin/events/{id}/cancel · POST /admin/events/{id}/delete
           GET /admin/events/{id}/registrations · POST /admin/event-registrations/{id}/attend
           (same pattern for /admin/volunteering, plus POST /admin/volunteer-registrations/{id}/complete)
           GET /admin/reservations · POST /admin/reservations/{id}/{approve|reject|cancel}
           GET/POST /admin/facilities[...]
           GET /admin/feedback[/{id}] · POST /admin/feedback/{id}/{status|reply|archive}
           GET /admin/messages[/{id}] · POST /admin/messages/{id}/status
           GET /admin/users · POST /admin/users/{id}/{role|toggle-active}
           GET/POST /admin/settings
Dev only   GET /_dev/mail[/{id}] (APP_ENV=local + loopback client + ADMIN)
```

List endpoints take `?page=1&limit=10` (limit clamped to 5–50) plus the filters from requirement 45. Pagination is done in SQL with `LIMIT/OFFSET` and a `COUNT(*)`.

## N. Folder Structure

```
project/
├── public/                     ← only web-reachable directory
│   ├── index.php               ← front controller
│   ├── .htaccess
│   └── assets/ {css, js, img, vendor/bootstrap, vendor/bootstrap-icons}
├── app/
│   ├── Core/        Router, Request, Response, View, Session, Csrf, Database, Config,
│   │                Translator, Validator, Mailer, RateLimiter, Paginator, Flash, ErrorHandler
│   ├── Http/
│   │   ├── Controllers/ {Public, Auth, Student, Admin}/*Controller.php
│   │   └── Middleware/  Authenticate, RequireRole, Guest, VerifyCsrf, SetLocale, RememberMe
│   ├── Services/    Auth, RememberMe, PasswordReset, Event, Volunteer, Reservation,
│   │                Feedback, Contact, Notification, Dashboard, User, Upload
│   ├── Repositories/ one per aggregate (PDO, prepared statements only)
│   ├── Domain/      Enums (Role, EventStatus, RegistrationStatus, ReservationStatus, …), value objects
│   └── Support/     DateFormatter (ar/en), helpers (e(), t(), url(), asset())
├── views/
│   ├── layouts/     app.php, admin.php, auth.php
│   ├── partials/    header, footer, sidebar, flash, pagination, breadcrumbs
│   ├── components/  button, input, select, card, event-card, volunteer-card, badge, table,
│   │                modal, confirm-dialog, alert, empty-state, spinner, stat-card
│   └── pages/       public/, auth/, student/, admin/, errors/
├── locales/ ar/{common,auth,events,volunteering,reservations,feedback,admin,validation,emails}.json
│            en/{…same…}
├── config/          app.php, database.php, mail.php, routes.php
├── database/        migrations/*.sql, seeds/*.php, legacy/LegacyImporter.php
├── bin/console      migrate, seed, migrate:legacy, purge:tokens
├── storage/         logs/, mail/, uploads/ (not web-reachable)
├── tests/           Unit/, Integration/
├── docs/            this document, final test report
├── legacy/          original PHP pages (archived after the baseline commit; blocked by .htaccess)
├── .htaccess        routes /project/* → public/, denies app/, .env, storage/, …
├── .env.example  · .gitignore · composer.json · phpunit.xml · README.md
```

## O. Implementation Roadmap

**Step 0 — Backup (before any change)**
1. `.gitignore` (vendor/, .env, storage/*) → **baseline commit** of the untouched legacy project + tag `legacy-v1`.
2. Zip copy: `<web root>/project_backup_2026-09-24.zip`.
3. `mysqldump` of the **live** `project` DB → `<backup folder>/project_live_2026-09-24.sql` (moved out of the web root on 2026-09-28) (the live DB differs from `project.sql`).
4. New work goes to a new DB (`college_platform`); the legacy DB is never written to.

| Phase | Deliverable | Checkpoint |
|---|---|---|
| 5 Core | Front controller, router, config/env, PDO, error handler (no leaks), session hardening, CSRF, view layer, translator, layouts, header/footer, design tokens, component partials | `/` renders in AR (RTL) and EN (LTR) |
| 3→6 DB | Migrations + seeds (roles, lookups, facilities, settings, demo admin/student) + legacy importer | `bin/console migrate && seed && migrate:legacy` report |
| 4 Auth | Register, login (role check), logout, Remember Me, forgot/reset, dev mail viewer, rate limiting | Auth test suite green |
| 6–7 Student | Events & volunteering browse/register, dashboard, registrations tabs, reservations, feedback, contact, profile, notifications | Manual + integration tests |
| 8 Admin | Dashboard KPIs, CRUD events/volunteering, attendance & hours approval, reservations, feedback, messages, users, settings | Manual + integration tests |
| 9–10 | i18n completeness check (script diffing `ar` vs `en` keys), RTL/LTR audit, responsive pass (360 / 768 / 1280 px) | Zero missing keys |
| 11 | Security review: headers (CSP, X-Frame-Options, nosniff, Referrer-Policy), upload validation, authz review of every route | Checklist in docs |
| 12 | PHPUnit + scripted end-to-end HTTP checks for requirement 61 | Test report `docs/02-test-report.md` |
| 13 | Archive legacy pages to `legacy/` + redirects, README, `.env.example`, cleanup | Fresh-clone install works |

Each phase ends with a commit (when you approve commits) so any step can be reverted.

## P. Post-review changes and observations (2026-09-25)

### P.1 Unexpected change to the legacy database (documented, not reverted)
- `project.users` (legacy DB) was **modified on 2026-09-25 at 04:47:03** (MariaDB `update_time` and the `users.ibd` file timestamp). Row **63** (a trainee's personal address, role `trainee`) is no longer present; row 62 (the college admin address, `trainer`) is unchanged.
- The application never writes to the legacy database: its only use is `LegacyImporter`, which runs `SELECT` statements through `legacyRows()`. The change was made outside this project.
- Nothing was restored or modified. The row still exists in the pre-migration dump `<backup folder>/project_live_2026-09-24.sql` (moved out of the web root on 2026-09-28) and in the ZIP backup, and it had already been imported into `college_platform` as user id 2 (STUDENT).
- At 04:49:08 a new account (a personal address) self-registered in `college_platform` (role STUDENT, Argon2id hash, no role changes in `audit_logs`).
- Policy: the legacy `project` database stays untouched; investigate legacy data from the backup.

### P.2 Legacy role mapping
`trainer → ADMIN`, `trainee → STUDENT`, mapped based on legacy authorization behavior (see §F). It applies to exactly one ADMIN account (legacy id 62, the college admin address) and is kept unchanged until the owner confirms it.

### P.3 Security fixes after review
- **Backup exposure:** the pre-migration ZIP had been placed in `<web root>` and was downloadable from the LAN. It was moved to `<backup folder>` (not deleted); the web root holds no archives or dumps.
- **Development mailbox:** now requires `APP_ENV=local` + a loopback client + an ADMIN login. `.env.example` defaults to `APP_ENV=production`, `MAIL_DRIVER=smtp`.
- **Demo accounts:** credentials come from `DEMO_*` in `.env` (random if empty). `db:seed` refuses to run unless `APP_ENV=local`, and no admin password is published.
- **Registration** is rate-limited: 60 submissions per IP per 15 min, 30 new accounts per IP per hour.
- **HTTPS/proxy:** `TRUSTED_PROXIES` controls whether `X-Forwarded-Proto/For` are honored (ignored by default). An `https://` `APP_URL` forces `Secure` cookies.
- **Token purge:** expired tokens are removed when new ones are issued, and `tokens:purge` has a documented daily schedule.
- **Uploads:** images must decode (`getimagesize`) and PDFs must carry the `%PDF-` signature. PHP execution is disabled and SVG/HTML are denied in `public/uploads`.

### P.4 Schema size
`college_platform` has **21 application tables + `schema_migrations`** (22 tables). An earlier chat summary said "17 tables"; that was wrong, and these documents give the correct count.

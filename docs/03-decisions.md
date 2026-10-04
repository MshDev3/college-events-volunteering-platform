# Decision Log

Decisions taken while finishing the security-hardening round (2026-09-30). The owner set the goals (numbered **O1–O10** below) and asked for every other choice to be made during the development process, choosing the safest option that follows security best practice and keeps all tests passing. Each entry says what was decided and why, so it can be reviewed or reversed.

## Owner decisions (as given)

| # | Decision |
|---|---|
| O1 | Merge `security-hardening` into `main` after a full passing test run |
| O2 | Replace the phone number with a clearly fake demo number (`+966500000000`) everywhere in the public code |
| O3 | License: all rights reserved; add a `LICENSE` file and a README note: "Personal portfolio project. Not the official website of any institution." |
| O4 | Public repository commits use a GitHub noreply email |
| O5 | Restore the legacy backup into a temporary database only, report a summary, never overwrite the original |
| O6 | Build a new hardened client delivery ZIP; keep the old one renamed with `-old` |
| O7 | Port the UI improvements from the client copy into this code base |
| O8 | Apply `ServerTokens Prod`, `ServerSignature Off`, `expose_php=Off` locally after backing up `httpd.conf` and `php.ini` |
| O9 | Verify a new email address by link before it replaces the old one, with tests |
| O10 | Stop the standalone MariaDB process cleanly so it can be restarted from the XAMPP panel |

## Decisions taken while implementing

### D1 — Local server configuration (O8)
- **Backups first:** `httpd.conf` and `php.ini` were copied to a dated backup folder outside the web root, and the copies were verified by hash before editing.
- **Where the directives live:** `ServerTokens Prod`, `ServerSignature Off` and `TraceEnable Off` were appended to the end of `httpd.conf`, not to `extra/httpd-default.conf`. XAMPP does not include that file (its `Include` line is commented out), and the last directive in the main file wins. `TraceEnable Off` was added as well: it disables HTTP TRACE, which has no use on this site and is a classic hardening item.
- **Restart:** Apache was restarted gracefully by signalling the running console instance's restart event. The parent process, and therefore the XAMPP control panel's tracking, is unchanged; a full stop and start from the panel was not needed.
- **Result:** `Server: Apache` (no version), no `X-Powered-By`, no version on error pages, `TRACE` → 405.

### D2 — Legacy user table (O5)
- The backup was restored into a **new** database, `<scratch database>`, with the dump's own `DROP/CREATE TABLE` statements confined to it. The dump was checked beforehand: it has no `CREATE DATABASE` or `USE` statement that could touch the original.
- **Nothing was written to the original `project` database.** The temporary database is kept until the owner decides; delete it with `DROP DATABASE <scratch database>;` when no longer needed. It contains personal data and password hashes, so it must not be exported or shared.
- **What the backup contains** (summary only, without passwords or hashes; this public file doesn't name the people either):
  - `users`: **2 accounts**, both with bcrypt hashes and no pending reset tokens:
    - the legacy administrator (role `trainer`, the college admin address);
    - one trainee with a personal address.
  - Both accounts were already imported into the new application's database on 2026-09-24, so the new system is not missing them.
  - The live legacy `users` table is **empty**; it was cleared by hand in phpMyAdmin on 2026-09-28 (Apache log), not by this project.
  - The other six legacy tables are **identical** in the backup and the live database (same checksums), so `users` is the only difference.
- **Restoring the original `project.users` table is waiting for the owner's approval** (see "Waiting for approval").

### D3 — Demo contact details in public seed data (O2)
- The first contact phone in the seed migration is now `+966500000000`.
- **The second number was replaced too**, by `+966110000000`. At first it was kept, because it has only six subscriber digits after the Riyadh area code and so isn't a valid landline. The final privacy scan of the public copy flagged it: it is still the client's own number, and the owner's rule is fake numbers everywhere in the public code. The seed, the validator test and the old typo fix in `003` no longer contain it. That fix only rewrote the old seeded value, and existing installations have already applied it.
- **The same scan found two other items**, now fixed:
  - the laptop's real LAN IP in a unit test (replaced by an RFC 5737 documentation address);
  - the legacy administrator's address on the real college domain in `docs/01` (replaced by a description).
- **The seed contact emails were also replaced**, with `info@college.example` and `admin@college.example`. They pointed at a real third-party domain the project does not control, which contradicts the new "not an official website" notice, and messages sent from a fresh installation would reach a stranger. The `.example` top-level domain is reserved for documentation (RFC 2606), so these addresses can never deliver to anyone.
- **Existing migration edited in place, no new migration.** The migrator tracks migrations by file name only, so editing `002` is safe for new installations. A corrective migration would have had to name the old values in its `WHERE` clause, publishing them again.
- **The local development database was updated to the same demo values**, so screenshots and demos from this machine match what a fresh installation shows. A full dump of that database taken just before is kept in the local backup folder. Contact details remain editable in Admin › Settings.
- **The client delivery (O6) keeps the contact details its owner approved.** They come from the client's own database export, not from the public code.

### D4 — License and notice (O3)
- `LICENSE` states "All rights reserved", matching `"license": "proprietary"` in `composer.json`. It explicitly allows viewing on the hosting platform (which that platform's terms grant anyway) and grants nothing else.
- Third-party components keep their own licenses (Composer packages, flatpickr under MIT, credited photographs), and `LICENSE` says so, so the "all rights reserved" claim does not overreach.
- The copyright holder is the git author name used in this repository. The same notice sits at the top of the README, together with the fact that the seed contact details are fictitious.

### D5 — UI improvements ported from the client copy (O7)
- **What was ported, in three commits:**
  - wide tables scroll inside their card; long values wrap in the stacked phone layout; admin-grid children may shrink;
  - the site name on the navy login/register panel is readable (white);
  - on phones narrower than 576 px, the header brand shrinks and wraps so the header buttons stay on one row; the About page uses a smaller gutter below the lg breakpoint.
  - After the port, `app.css` is identical to the client copy's.
- **Not ported, on purpose:**
  - The client copy **removed** the dev-mailbox hint on the forgot-password page. It stays here: it only renders when `APP_ENV=local` and `MAIL_DRIVER=log`, and the mailbox exists only in development builds.
  - The client's `mailto:` links are older than the encoded `mailto_href()` links here (L-7), so the dev versions were kept.
- **Client copy:** read only, never modified.

### D6 — Email change confirmed by link (O9)
- **Design:** the current password is still required first, then the new address has to prove itself by a link. The email only changes after both checks: knowing the password proves who asks, the link proves the new address belongs to them.
- **Token handling:** the token is 32 random bytes, stored only as SHA-256, valid for 60 minutes (`EMAIL_CHANGE_MINUTES`) and single use. This is the same design as the password-reset tokens, so one review covers both.
- **Confirming needs the same account to be signed in**, rather than working for anyone holding the link. A leaked link alone can't change anything, and a guest is sent to the login page and brought back.
- **GET shows, POST confirms.** Many mail security scanners open every link in an email; a GET that changed the email would let them confirm on the user's behalf.
- **Mail sending:** the verification email is sent synchronously, not through the queue used for password resets. The user is signed in and has just proved the password, so timing reveals nothing, and a delivery failure can be reported immediately (the request is then discarded).
- **Limits:**
  - 3 verification emails per account per hour (on top of the current-password throttle), so the site can't be used to send mail to arbitrary addresses.
  - One pending request per account; a new request replaces the old link.
- **Cancellation:** a password change, a password reset or a deactivation deletes a pending request. After a takeover, the owner's usual recovery step (reset the password) also stops the attacker's move to their own address.
- **Unchanged from before:** after confirmation, other sessions and remembered devices end and the old address is warned (the earlier L-6 behaviour, moved to the confirmation step).

### D7 — Test-suite robustness
- An aborted run of the HTTP flows suite left its tagged records behind (including an approved booking), which made the next run fail. Those leftovers were deleted by their run tag only, and the suite now cleans up in a shutdown function, so a crash can't leave data behind again.
- A throttling test written for the old "email changes immediately" behaviour was updated to the confirm-by-link flow. It now also asserts that nothing changes before confirmation.

### D8 — Merge into `main` (O1), and one incident
- **Merge:** after a full passing run on the branch (112 PHPUnit tests, 97 end-to-end, 37 security, 104 crawled pages, i18n and `composer audit` clean), `security-hardening` was merged into `main` with an explicit merge commit (`--no-ff`), so the hardening round stays visible as one unit in the history. The same checks were repeated on `main` afterwards.
- **Incident, fixed:** the first merge attempt failed after `git checkout main` had already succeeded. This git version doesn't read the message from standard input with `-F -`.
  - For about a minute the working tree held the old `main`, which is the legacy baseline. The merge was then completed.
  - The checkout overwrote the ignored `.vscode/` editor settings (still tracked in old `main`), and the merge deleted them. They were restored from history, byte-identical (same blob hashes).
  - Nothing else was affected: `.env`, `vendor/`, uploads and logs are not tracked in either branch.
  - **Lesson:** stay on the branch and merge in a single command with a message file.

### D9 — New client delivery package (O6)
- **Code:** built from `main` at the merge commit `3863fbc` with `git archive`. The later commits on `main` change only documentation, tests and the public seed contact values, which the package's database import replaces with the client's own details anyway. Developer-only parts are left out: `tests/`, `docs/`, `database/demo/` (the demo script must never ship, see L-11), `phpunit.xml` and `.gitattributes`. PHP dependencies are installed with `--no-dev --optimize-autoloader`, so no test tools ship.
- **Files replaced for the client:**
  - a client README (installation with the dedicated database accounts, an Arabic quick start, a presentation guide, updating, backups, troubleshooting);
  - a client `.env.example` (the client's folder URL, no test, legacy or demo settings).
  - No `.env` is included.
- **Database:**
  - Rebuilt from the migrations (001–006), not taken from the old dump, so it has the hardened schema.
  - It includes the presentation content (5 events, 4 opportunities) and the contact details the client approved, copied from the client database. Facilities and reference data were checked and are identical to the seed data.
  - It ships **no user accounts**, and therefore no registrations, bookings, notifications or logs; the content's creator reference is cleared.
  - The administrator is created with `admin:create`, and the presentation student through the normal registration page. This follows H-1: no account and no password ship in the package.
- **License:** the portfolio `LICENSE` is **not** included in the client package. It says the project is not the official website of any institution, which contradicts a delivery to an institution. The terms for the client are the owner's decision (see "Waiting for approval").
- **Tested as delivered:**
  - The ZIP itself was extracted and installed exactly as its README describes, with fresh random passwords: setup script, import, `.env`, `db:check`, `admin:create`.
  - It was served on 127.0.0.1 only and checked over HTTP, 13 checks: content, contact details, security headers, the old demo logins no longer exist, all admin sections, student registration, event registration, a booking reaching the admin, `.env` not served.
  - The migration account worked, and the application account was refused DDL, as designed.
  - The test database, accounts and files were removed afterwards.
- **Packaging:** the ZIP is built with Windows' `tar.exe` so entry names use `/`; .NET's `ZipFile` in Windows PowerShell 5.1 writes `\`, which some tools mishandle.
- **Old package:** renamed to `…-old.zip`; its SHA-256 was verified unchanged before and after the rename. It is not deleted.

### D10 — Screenshots and walkthrough without sharing passwords
- The earlier screenshot harness put the demo passwords in a web-served file for a few seconds. The new driver logs in from PHP and gives the headless browser only short-lived session IDs, which are logged out afterwards. It also removes its temporary files, the temporary pending email change it created, and the locale preference changes.

### D11 — Publishing to GitHub (O4)
- The public copy is regenerated from `main` with `git archive`, which leaves out `legacy/` (`export-ignore`) and every git-ignored file, then scanned for personal data and secrets.
- No repository was created or pushed. A helper script outside the public copy creates the repository **only with a GitHub noreply address passed as a parameter** (format `<ID>+<USERNAME>@users.noreply.github.com`, from GitHub › Settings › Emails). This avoids baking a placeholder address into the first commit. The script refuses anything else, sets that address for this repository only, commits, adds the remote if given, and does **not** push.

### D12 — Stopping MariaDB (O10)
- MariaDB was running as a standalone process (not through the XAMPP Control Panel). After all database work, it is stopped with `mysqladmin shutdown`, a clean shutdown that flushes InnoDB, so it can be started again from the XAMPP Control Panel. Apache keeps running; it was restarted gracefully (D1) and is managed by the panel as before.

## Owner approvals and outcomes (2026-09-30, late evening)

The owner answered the "Waiting for approval" list; this is what was done. Before any change:
- every database was dumped;
- the Apache and phpMyAdmin configuration files were copied, and the copies checked by hash;
- cold copies of the MariaDB data directory were taken while the server was stopped.

All backups are in `<backup folder>` on the local machine.

| # | Decision | Outcome |
|---|---|---|
| W1 | Do not restore the legacy `users` table | Not restored; both accounts already exist in the new system |
| W2 | Delete `<scratch database>` | Dropped (it remains inside the full dump taken just before) |
| W3 | Delete the old delivery ZIP | Deleted, after its SHA-256 matched the recorded value |
| W4 | The owner publishes to GitHub | Nothing pushed; the public copy and the helper are ready |
| W5 | No license file in the client package | Unchanged: the package has none |
| W6 | No pre-made demo accounts in the client package | Unchanged: the package has none |
| R1 | Replace the installed client copy with the new package | Done; details below |
| R2 | Apache on 127.0.0.1 only | `Listen 127.0.0.1:80`, plus `Listen 127.0.0.1:443` in `httpd-ssl.conf`, so HTTPS isn't left open either. After a graceful restart the LAN address refuses connections; localhost works over HTTP and HTTPS |
| R3 | Move the old copy `<old site folder>` to `<backup folder>` | Done last. It is also the editor's workspace, so folders an editor still holds open may remain as empty folders until the editor is closed |
| R4 | Strong MariaDB root password; phpMyAdmin must keep working | Done; details below |
| R5 | Restore phpMyAdmin's `pma` grants | Done: `SELECT, INSERT, UPDATE, DELETE` on `phpmyadmin.*`, plus a password of its own |
| R6 | Export the legacy `project` database, verify, drop | Done; details below |

**R1 — client copy.**
- **Backups:** the old folder was copied to the backups first and verified file by file against the hashes recorded that morning (5,489 files plus the parent `.htaccess`). Its two databases were dumped.
- **Install:** the new package was installed exactly as its README describes:
  - setup script with new random passwords (the filled-in copy was deleted);
  - import as administrator, then `.env`;
  - `db:check`: data access only; `migrate`: refused for the website account, a no-op with the migration account;
  - `admin:create`. The package's `MAIL_DRIVER=log` presentation setting was chosen because the laptop has no SMTP.
- **Verified through Apache, 12/12 checks:**
  - the two previously published demo passwords no longer log in, and neither does the student one on the admin account;
  - the new administrator opens all 10 admin sections;
  - 13 protected paths return 403/404;
  - the site isn't reachable from the LAN;
  - content, contact details and security headers are correct.
- **Kept:** the old client databases (`<client database>`, `<client test database>`) and their `<client DB account>` account were left in place, since dropping them was not requested. No code uses them any more (N3).

**R4 — root password.**
- **Password:** a random root password was set on `root@localhost`, `root@127.0.0.1` and `root@::1`. It is stored only in a local secrets file for the owner's password manager, never in a project or configuration file.
- **phpMyAdmin:** switched from automatic root login (`auth_type = config` with an empty password) to its login form (`cookie`), with a new 32-byte cookie secret and `AllowNoPassword = false`. Tested over HTTP: wrong and empty passwords are refused, root logs in, and there is no configuration-storage or control-user warning.
- **Development site:** it used root without a password. Rather than copy the root password into a project file, it now uses two restricted accounts, like the production setup: `<dev app account>` (data access) and `<dev migration account>` (schema).
  - Checks: `db:check` passes. PHPUnit (112), end-to-end (97), security (37) and the crawl (104 pages) all pass with these accounts.
  - Test change: the security suite now treats "the LAN address refuses connections" as the stronger pass (R2).

**R6 — legacy database.** The export (`<backup folder>/legacy-project-database-2026-09-30.sql`) was imported into a scratch database and compared with the original. All 7 tables matched on row count, `CHECKSUM TABLE` and definition. Then the scratch database and `project` were dropped.

### D13 — MariaDB repairs during this round
- **First start failed:** the system table `mysql.proxies_priv` had a corrupt index (it had already warned that morning). The server was started once with `--skip-grant-tables --skip-networking` (no TCP port, local named pipe only). The table was rebuilt with `REPAIR TABLE … USE_FRM`, keeping its single standard row.
- **One development table was damaged:** `college_platform.password_reset_tokens` no longer matched the data dictionary after the crashed start attempts. It only holds short-lived reset tokens, so it was dropped and re-created empty from migration 001. The damaged file is preserved in the cold copy.
- **Unclean stop:** the server ran as a background task, which the task runner stopped abruptly at its time limit. After another cold copy, it recovered on restart, and every table in every database checked OK.
- **Root cause, very likely:** XAMPP's own **MySQL "Stop" button kills `mysqld.exe`** (`mysql_stop.bat` → `killprocess.bat`) instead of shutting it down. Every such kill is an unclean shutdown. Stop it with `mysqladmin -u root -p shutdown` instead (N5).

### Still waiting for approval (found while doing the above)

| # | Item | Why it matters | Suggested action |
|---|---|---|---|
| N1 | `<other legacy-site folder>` is another copy of the **legacy site**, connecting as `root` | Its `project.sql` dump (personal data) was downloadable over HTTP. Since R2 it is reachable from this laptop only. It also stopped working after R4 (no root password) and R6 (database dropped) | Move it to the backups like R3, or delete it |
| N2 | `<other project folder>` (another project) connects to `<other project database>` as `root` with an empty password | It can no longer connect after R4 | Create a restricted account for it and put that in its `.env` (like the development site), or give it the root password (not recommended) |
| N3 | Old client databases `<client database>`, `<client test database>` and account `<client DB account>` | Unused after R1; they still hold the old demo accounts | Drop them (both are in the backups) |
| N4 | MariaDB listens on all network interfaces (port 3306) | Remote logins are refused (no account matches a remote host), but binding it to `127.0.0.1` removes the exposure entirely | Add `bind-address=127.0.0.1` under `[mysqld]` in `mysql\bin\my.ini` |
| N5 | XAMPP's MySQL "Stop" button kills the server | Repeated unclean shutdowns corrupted system tables three times this week | Stop MariaDB with `mysqladmin -u root -p shutdown` (a small `.bat` shortcut could be added) |

**Outcomes (approved by the owner, 2026-09-30).** The configuration files were backed up first.

| # | Outcome |
|---|---|
| N1 | `<other legacy-site folder>` was copied to the backups, verified by hash (33 files, 0 differences), then removed from `htdocs` |
| N2 | New account `<restricted account>@localhost` with `SELECT, INSERT, UPDATE, DELETE` on `<other project database>` only; its `.env` points to it (password in the local secrets file). **Verified through Laravel:** it connects as that account, reads its data, and writes/deletes a cache entry. Schema changes and other databases are refused. `artisan db:show` can't read server statistics, as expected. Future `artisan migrate` runs need a schema-capable account |
| N3 | Both databases exported to the backups and verified by re-import: every table matched on row count, checksum and definition. Then both databases and the `<client DB account>` account were dropped |
| N4 | `bind-address=127.0.0.1` in `my.ini`: the server listens on `127.0.0.1` only, and port 3306 on the LAN address refuses connections |
| N5 | Unchanged: stop MariaDB with `mysqladmin` rather than the panel's Stop button |

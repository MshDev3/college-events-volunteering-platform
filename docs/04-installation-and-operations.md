# Installation, deployment and operations guide

> Reference guide for installing, configuring and operating the platform. The [README](../README.md) gives the overview and a short quick start.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `json` (Argon2id support is standard in PHP builds with libargon2; otherwise bcrypt is used).
- MariaDB 10.4+ or MySQL 8.
- Apache 2.4 with `mod_rewrite` (or nginx + PHP-FPM).
- Composer.

## Installation (server or any shared machine)

The application runs with a **restricted database account** that can only read and write rows. The schema is installed separately with an account that may create tables. Nothing in the repository contains a password.

### 1. Code and dependencies

```bash
git clone <this repository> tvtc-portal
cd tvtc-portal
composer install --no-dev --optimize-autoloader   # also publishes Bootstrap + icons to public/assets/vendor
```

### 2. Database accounts (least privilege)

`database/setup/create_app_user.sql` creates the database and two accounts:

| Account | Privileges | Used by |
|---|---|---|
| `tvtc_app` | `SELECT, INSERT, UPDATE, DELETE` on the application database only | the website and every console command except `migrate` |
| `tvtc_migrate` | the above + `CREATE, ALTER, DROP, INDEX, REFERENCES` on that database only | `php bin/console migrate` |

```bash
cp database/setup/create_app_user.sql database/setup/create_app_user.local.sql   # *.local.sql is git-ignored
# edit the copy: replace both CHANGE_ME values with long random passwords, e.g.
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
mysql -u root -p < database/setup/create_app_user.local.sql
rm database/setup/create_app_user.local.sql
```

### 3. Configuration

```bash
cp .env.example .env
```

`.env.example` is safe for production by default (`APP_ENV=production`, `APP_DEBUG=false`, SMTP mail). Fill in at least:

```ini
APP_URL=https://portal.example.edu      # public URL, no trailing slash
DB_DATABASE=tvtc_portal
DB_USERNAME=tvtc_app
DB_PASSWORD=<the tvtc_app password>
MAIL_HOST=... MAIL_USERNAME=... MAIL_PASSWORD=... MAIL_FROM_ADDRESS=...
```

If `.env` is missing, the site answers **503 "Service not configured"** (details go to the PHP error log) and the console prints how to create it. `DB_USERNAME=root` is refused unless `APP_ENV=local`.

### 4. Schema

Give the migration credentials only to the command that needs them, rather than storing them in the web server's `.env`:

```bash
# Linux / macOS
DB_MIGRATE_USERNAME=tvtc_migrate DB_MIGRATE_PASSWORD='<password>' php bin/console migrate
```
```powershell
# Windows PowerShell
$env:DB_MIGRATE_USERNAME='tvtc_migrate'; $env:DB_MIGRATE_PASSWORD='<password>'; php bin/console migrate
Remove-Item Env:DB_MIGRATE_USERNAME, Env:DB_MIGRATE_PASSWORD
```

(Alternatively set `DB_MIGRATE_USERNAME` / `DB_MIGRATE_PASSWORD` in `.env`; when they are empty, `migrate` uses `DB_USERNAME`.) Then confirm the runtime account is least-privilege:

```bash
php bin/console db:check
# OK: the application account has data access only (SELECT, INSERT, UPDATE, DELETE).
```

### 5. First administrator

No account exists after installation. Create the first administrator from the command line; the password is typed twice with hidden input (never passed as an argument):

```bash
php bin/console admin:create
# scripted installs: printf '%s\n' "$ADMIN_PASSWORD" | php bin/console admin:create --name="Full Name" --email=admin@college.edu --password-stdin
```

It applies the normal password policy and refuses once an active admin exists. Further admins are promoted from **Admin → Users** by an existing admin.

### 6. Web server: document root = `public/`

Only `public/` should be reachable over HTTP. Point the virtual host's document root at it.

**Apache**

```apache
<VirtualHost *:443>
    ServerName portal.example.edu
    DocumentRoot "/var/www/tvtc-portal/public"

    <Directory "/var/www/tvtc-portal/public">
        Options -Indexes -MultiViews
        AllowOverride All          # public/.htaccess (front controller) and public/uploads/.htaccess (no PHP)
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/portal.pem
    SSLCertificateKeyFile /etc/ssl/private/portal.key
</VirtualHost>

<VirtualHost *:80>
    ServerName portal.example.edu
    Redirect permanent / https://portal.example.edu/
</VirtualHost>
```

If you run with `AllowOverride None`, copy the rules of `public/.htaccess` and `public/uploads/.htaccess` into the `<Directory>` blocks: the uploads rules are what stop PHP from ever executing there.

**nginx + PHP-FPM**

```nginx
server {
    listen 443 ssl;
    server_name portal.example.edu;
    root /var/www/tvtc-portal/public;
    index index.php;

    location / { try_files $uri /index.php$is_args$args; }
    location ^~ /uploads/ {                      # user uploads: static files only, never PHP
        location ~* \.(php\d?|phtml|phar|html?|svg)$ { return 403; }
        add_header X-Content-Type-Options nosniff;
    }
    location ~ /\. { deny all; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ \.php$ { return 404; }
}
```

The repository's root `.htaccess` exists only for hosts where the project root itself is web-served (e.g. a XAMPP folder): it routes everything into `public/` and explicitly denies dotfiles, dumps, logs, config and application directories. It is a second line of defence, not a substitute for the document root.

### 7. Hide server versions (php.ini / Apache)

The application already removes `X-Powered-By`. The web server's own banner must be configured on the server:

```ini
; php.ini
expose_php = Off
display_errors = Off
log_errors = On
```
```apache
# httpd.conf (XAMPP: C:\xampp\apache\conf\extra\httpd-default.conf)
ServerTokens Prod
ServerSignature Off
TraceEnable Off
```

### 8. Scheduled tasks

| Task | When | Command |
|---|---|---|
| Purge expired remember-me / reset tokens, email-change links and old rate-limit rows | daily | `php bin/console tokens:purge` |
| Send queued emails | every minute, **only** if `MAIL_QUEUE=cron` | `php bin/console mail:work` |

```cron
0 3 * * *  php /var/www/tvtc-portal/bin/console tokens:purge >/dev/null 2>&1
* * * * *  php /var/www/tvtc-portal/bin/console mail:work    >/dev/null 2>&1   # MAIL_QUEUE=cron only
```
```bat
:: Windows Task Scheduler (administrator terminal)
schtasks /Create /TN "TVTC Portal token purge" /SC DAILY /ST 03:00 /TR "C:\xampp\php\php.exe C:\path\to\tvtc-portal\bin\console tokens:purge"
```

With the default `MAIL_QUEUE=after_response`, password-reset emails are sent right after the page has been delivered, so no scheduler is needed for them.

### Deployment checklist

- [ ] Document root is `public/`; HTTPS with a valid certificate; `APP_URL` starts with `https://`.
- [ ] `.env` created on the server from `.env.example` (never committed or shipped): `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `php bin/console db:check` reports data access only; MariaDB root has a password and listens on 127.0.0.1.
- [ ] First admin created with `admin:create`; no demo accounts or demo SQL on the server.
- [ ] SMTP configured and a password reset tested end to end.
- [ ] `storage/` and `public/uploads/` writable by the web server; everything else read-only.
- [ ] `expose_php=Off`, `ServerTokens Prod`, `ServerSignature Off`.
- [ ] `tokens:purge` scheduled; database backups scheduled and stored outside the web root.
- [ ] `composer audit` clean.

## Local development (XAMPP on Windows)

1. Put the project under `C:\xampp\htdocs\` (e.g. `C:\xampp\htdocs\<project folder>`, served at `http://localhost/<project folder>`). XAMPP has `mod_rewrite` and `AllowOverride All`; the root `.htaccess` routes into `public/`.
2. **Keep the development server off your network.** XAMPP's Apache listens on every interface by default. In `C:\xampp\apache\conf\httpd.conf` change `Listen 80` to `Listen 127.0.0.1:80` (and `Listen 127.0.0.1:443` in `extra\httpd-ssl.conf`), then restart Apache.
3. `composer install`
4. Create the database accounts as in step 2 above (recommended even locally), or use XAMPP's `root`, which is accepted **only** with `APP_ENV=local`.
5. `cp .env.example .env`, then for local work:
   ```ini
   APP_ENV=local           # dev mailbox, demo seed script, smoke tests
   APP_DEBUG=true          # error details in the browser (local only)
   APP_URL=http://localhost/<project folder>
   MAIL_DRIVER=log         # emails are written to storage/mail instead of being sent
   DB_TEST_DATABASE=tvtc_portal_test   # for the integration tests
   ```
6. `php bin/console migrate` (with migration credentials as in step 4 above).
7. *(Optional)* Import the old system's data from the legacy database (read-only, idempotent; prints a report). Imported administrators must reset their password before first use:
   ```bash
   php bin/console migrate:legacy
   ```
8. *(Optional, local only)* Demo data — see below.
9. Open the site and create your administrator with `php bin/console admin:create` (or use the demo admin).

### Demo data (local development only)

Demo accounts, events, opportunities, bookings and feedback are **not** part of the application. They come from a separate script that refuses to run unless `APP_ENV=local`:

```bash
php database/demo/seed.php
```

- Account emails: `admin@college.test` (Admin), `student@college.test`, `student2…7@college.test`, `demo0…16@college.test` (Student).
- Passwords come from `DEMO_ADMIN_PASSWORD` / `DEMO_STUDENT_PASSWORD` in **your** `.env`. They must be strong (12+ characters with upper and lower case, a digit and a symbol); leave them empty and the script generates random ones and prints them once. **No demo password is stored in this repository.**
- Never run it on a server and never ship `database/demo/` in a production package.

### Development mailbox (`/_dev/mail`)

With `MAIL_DRIVER=log`, emails (e.g. password-reset links) are written to `storage/mail/` (never web-served). The viewer at `/_dev/mail` answers only when **all** of these hold, otherwise it is a 404: `APP_ENV=local`, the request comes from `127.0.0.1` / `::1`, and the visitor is logged in as an admin.

## Console commands

| Command | Purpose |
|---|---|
| `php bin/console migrate` | Apply new migrations (uses `DB_MIGRATE_*` when set) |
| `php bin/console migrate --fresh` | Drop and re-create the database — refused unless `APP_ENV=local` |
| `php bin/console db:check` | Report whether the runtime DB account has more than data access |
| `php bin/console admin:create` | Create the first administrator (hidden password prompt; refused if an active admin exists) |
| `php bin/console migrate:legacy` | One-time import from the legacy database |
| `php bin/console mail:work` | Send queued emails (for `MAIL_QUEUE=cron`) |
| `php bin/console tokens:purge` | Delete expired tokens and rate-limit rows |
| `php bin/console assets:publish` | Copy Bootstrap and icons into `public/assets/vendor` |
| `php bin/console i18n:check` | Report translation keys missing in Arabic or English |
| `php database/demo/seed.php` | Local demo data (not part of the application) |

## Environment variables (`.env`)

| Variable | Purpose |
|---|---|
| `APP_ENV` | `production` (default) or `local`. `local` enables `/_dev/mail`, the demo seed script, `migrate --fresh`, the smoke tests, and allows `DB_USERNAME=root`. Never use `local` on a server |
| `APP_DEBUG` | Exception details on error pages, honoured **only** with `APP_ENV=local` |
| `APP_URL` | Public base URL for links in emails and the cookie path (never the `Host` header). `https://` makes cookies always `Secure` |
| `TRUSTED_PROXIES` | IPs of reverse proxies you control. Only requests *from these IPs* may use `X-Forwarded-Proto` / `X-Forwarded-For`. Empty = ignored |
| `APP_TIMEZONE`, `APP_DEFAULT_LOCALE` | College timezone (the DB session uses the same offset) and default language |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Runtime connection (restricted account). No defaults: the app refuses to start without them |
| `DB_MIGRATE_USERNAME`, `DB_MIGRATE_PASSWORD` | Schema account for `migrate` only; empty = use `DB_USERNAME` |
| `LEGACY_DB_DATABASE` | Old database read by `migrate:legacy` |
| `DB_TEST_DATABASE` | Separate database the PHPUnit integration tests drop and re-create (development only) |
| `SESSION_NAME`, `SESSION_IDLE_MINUTES`, `SESSION_ABSOLUTE_MINUTES` | Session cookie name and timeouts (default 120 / 720 minutes) |
| `REMEMBER_ME_DAYS`, `PASSWORD_RESET_MINUTES`, `EMAIL_CHANGE_MINUTES` | Remember-me lifetime (30 days), reset-link lifetime (60 minutes) and new-email confirmation-link lifetime (60 minutes) |
| `MAIL_DRIVER` | `smtp` (default) or `log` (local development) |
| `MAIL_QUEUE` | `after_response` (default) or `cron` — when queued password-reset emails are sent |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | SMTP settings |
| `UPLOAD_MAX_MB` | Maximum attachment/image size |
| `DEMO_*` | Demo account emails/passwords, read only by `database/demo/seed.php` and the smoke tests |

`.env` is git-ignored; never commit it.

## Accounts and roles

- **Students** self-register at `/register` and always get the STUDENT role.
- **Administrators:** the first one with `admin:create`; others are promoted from Admin → Users. Choosing "Admin" on the login form grants nothing: the backend compares it with the role stored in the database. Admins can't demote or deactivate themselves or remove the last active admin.
- **Legacy accounts** imported by `migrate:legacy`: roles were mapped based on the legacy authorization behaviour (`trainer → ADMIN`, `trainee → STUDENT`; details in the audit, §F). Students keep their bcrypt hash (upgraded to Argon2id at the next login); **imported admins get no usable password** and must use "Forgot password" first.

## Security

See **[SECURITY.md](../SECURITY.md)** for the full list: Argon2id, prepared statements only, CSRF on every form, strict CSP and security headers (HSTS on HTTPS), hardened sessions and Remember Me, atomic rate limits (per account, per IP, and per account across IPs), status-guarded state changes, verified uploads outside the web root, least-privilege database accounts, and how each is tested.

## Testing

```bash
composer test                     # PHPUnit: unit + integration (integration uses DB_TEST_DATABASE)
php tests/smoke/flows.php         # end-to-end behaviour over HTTP against the running site + DB
php tests/smoke/security.php      # CSRF/role matrix, XSS, sessions, reset tokens, uploads, rate limits, exposure
php tests/smoke/crawl.php         # every page as guest/student/admin in AR and EN
php bin/console i18n:check        # every key exists in both languages
```

- **Integration tests** drop and re-create `DB_TEST_DATABASE` (and a throw-away `<DB_TEST_DATABASE>_legacy`) and refuse to run if it equals `DB_DATABASE`. Several start real parallel PHP processes to prove the rate limits, reservation decisions and Remember Me hold under concurrency. They pass with the restricted `tvtc_app` / `tvtc_migrate` accounts.
- **Smoke suites** require `APP_ENV=local`, the site at `APP_URL`, and demo data from `php database/demo/seed.php` with the `DEMO_*` passwords in `.env`. They tag their records and delete them afterwards.

## Maintenance

- **Translations:** add each key to both `locales/ar/*.json` and `locales/en/*.json`, then run `i18n:check`.
- **Schema changes:** add `database/migrations/NNN_description.sql`, then run `migrate` with the migration account.
- **Default images** live in `public/assets/img/defaults/` (free Unsplash photos credited in `defaults/CREDITS.md`, men only or no people, since the college is for male students). `events/<type code>-N.jpg`, `volunteering/<category code>-N.jpg`, `about.jpg`; when a type has several photos, the record id picks one (`id % count`). Admin uploads in `public/uploads/` always take priority.
- **Contact map:** when an address is set in Settings, the Contact page embeds a Google Maps frame; the CSP allows only `frame-src https://www.google.com`.


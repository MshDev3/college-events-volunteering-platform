-- =====================================================================
-- One-time database setup: least-privilege accounts for TVTC Campus.
--
-- Copy it to database/setup/create_app_user.local.sql (git-ignored), replace every CHANGE_ME value
-- with a long random password (e.g. `php -r "echo bin2hex(random_bytes(24));"`), run the copy as a
-- MariaDB/MySQL administrator (e.g. root), then delete the copy:
--
--   mysql -u root -p < database/setup/create_app_user.local.sql
--
-- Then put the same values in .env (DB_USERNAME / DB_PASSWORD and
-- DB_MIGRATE_USERNAME / DB_MIGRATE_PASSWORD) and run `php bin/console migrate`.
--
-- Rename `tvtc_portal` below if you use a different DB_DATABASE. In GRANT statements "_" is a
-- wildcard, so it is written as "\_" there.
-- This file contains no real credentials; never commit a copy that does.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `tvtc_portal` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Runtime account used by the web application: data access only.
-- It cannot create, alter or drop tables, read other databases, or grant privileges.
CREATE USER IF NOT EXISTS 'tvtc_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_app_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON `tvtc\_portal`.* TO 'tvtc_app'@'localhost';

-- Schema account used only by `php bin/console migrate`.
-- Keep its password out of the web server's environment if you can: fill in
-- DB_MIGRATE_* only while migrating, or run the console with those variables set.
CREATE USER IF NOT EXISTS 'tvtc_migrate'@'localhost' IDENTIFIED BY 'CHANGE_ME_migrate_password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON `tvtc\_portal`.* TO 'tvtc_migrate'@'localhost';

-- If PHP connects over TCP to 127.0.0.1 and your server resolves it separately from
-- 'localhost', repeat the two CREATE USER / GRANT pairs with @'127.0.0.1'.

-- Optional, development machines only: the integration tests drop and re-create
-- DB_TEST_DATABASE (and a throw-away "<DB_TEST_DATABASE>_legacy"), so the migration account
-- needs the same rights there; the tests run the app queries with DB_USERNAME.
-- GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
--     ON `tvtc\_portal\_test`.* TO 'tvtc_migrate'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
--     ON `tvtc\_portal\_test\_legacy`.* TO 'tvtc_migrate'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `tvtc\_portal\_test`.* TO 'tvtc_app'@'localhost';
-- GRANT SELECT ON `tvtc\_portal\_test\_legacy`.* TO 'tvtc_app'@'localhost';

-- Optional, only while importing the legacy system with `migrate:legacy`:
-- GRANT SELECT ON `project`.* TO 'tvtc_app'@'localhost';
-- ...and revoke it afterwards:
-- REVOKE SELECT ON `project`.* FROM 'tvtc_app'@'localhost';

FLUSH PRIVILEGES;

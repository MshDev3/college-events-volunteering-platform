-- =====================================================================
-- 001 — Core schema for the Technical College platform
-- Engine: InnoDB, utf8mb4_unicode_ci. All FKs explicit.
-- Replaces the legacy tables users/register/hajz/problem/contact
-- (see docs/01-audit-and-plan.md §E–F for the reasoning).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Lookups
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id          TINYINT UNSIGNED NOT NULL,
  code        VARCHAR(20)      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB;

INSERT INTO roles (id, code) VALUES (1, 'STUDENT'), (2, 'ADMIN');

CREATE TABLE departments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_ar     VARCHAR(120) NOT NULL,
  name_en     VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE event_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)  NOT NULL,
  name_ar     VARCHAR(80)  NOT NULL,
  name_en     VARCHAR(80)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_types_code (code)
) ENGINE=InnoDB;

CREATE TABLE volunteer_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)  NOT NULL,
  name_ar     VARCHAR(80)  NOT NULL,
  name_en     VARCHAR(80)  NOT NULL,
  icon        VARCHAR(40)  NOT NULL DEFAULT 'bi-heart',
  PRIMARY KEY (id),
  UNIQUE KEY uq_volunteer_categories_code (code)
) ENGINE=InnoDB;

CREATE TABLE feedback_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)  NOT NULL,
  name_ar     VARCHAR(80)  NOT NULL,
  name_en     VARCHAR(80)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_feedback_categories_code (code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Users & authentication
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  full_name         VARCHAR(120)      NOT NULL,
  student_id        VARCHAR(20)       NULL,
  email             VARCHAR(190)      NOT NULL,
  phone             VARCHAR(20)       NULL,
  password_hash     VARCHAR(255)      NOT NULL,
  role_id           TINYINT UNSIGNED  NOT NULL DEFAULT 1,
  department_id     INT UNSIGNED      NULL,
  preferred_locale  ENUM('ar','en')   NOT NULL DEFAULT 'ar',
  is_active         TINYINT(1)        NOT NULL DEFAULT 1,
  -- Incremented on password change/reset; sessions carrying an older value are rejected.
  auth_version      INT UNSIGNED      NOT NULL DEFAULT 1,
  last_login_at     DATETIME          NULL,
  created_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_student_id (student_id),     -- NULLs allowed (admins, legacy users)
  KEY ix_users_role_active (role_id, is_active),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id),
  CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE auth_remember_tokens (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  selector        CHAR(24)     NOT NULL,        -- public lookup half (hex)
  validator_hash  CHAR(64)     NOT NULL,        -- sha256 of the secret half; the secret is never stored
  user_agent      VARCHAR(255) NULL,
  expires_at      DATETIME     NOT NULL,
  last_used_at    DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY ix_remember_user (user_id),
  KEY ix_remember_expires (expires_at),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    CHAR(64)     NOT NULL,          -- sha256 of the emailed token
  expires_at    DATETIME     NOT NULL,
  used_at       DATETIME     NULL,
  requested_ip  VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_token_hash (token_hash),
  KEY ix_reset_user (user_id),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rate_limits (
  bucket    CHAR(64)     NOT NULL,              -- sha256 of the limiter key
  hits      INT UNSIGNED NOT NULL DEFAULT 0,
  reset_at  DATETIME     NOT NULL,
  PRIMARY KEY (bucket),
  KEY ix_rate_limits_reset (reset_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Events
-- ---------------------------------------------------------------------
CREATE TABLE events (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title_ar        VARCHAR(200) NOT NULL,
  title_en        VARCHAR(200) NOT NULL,
  description_ar  TEXT         NOT NULL,
  description_en  TEXT         NOT NULL,
  image_path      VARCHAR(255) NULL,
  event_type_id   INT UNSIGNED NOT NULL,
  location_ar     VARCHAR(200) NOT NULL,
  location_en     VARCHAR(200) NOT NULL,
  start_datetime  DATETIME     NOT NULL,
  end_datetime    DATETIME     NOT NULL,
  capacity        INT UNSIGNED NOT NULL,
  -- The only stored status fact. UPCOMING/ONGOING/COMPLETED are derived from the clock.
  cancelled_at    DATETIME     NULL,
  cancel_reason   VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_events_start (start_datetime),
  KEY ix_events_end (end_datetime),
  KEY ix_events_type (event_type_id),
  CONSTRAINT fk_events_type FOREIGN KEY (event_type_id) REFERENCES event_types (id),
  CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT ck_events_time CHECK (end_datetime > start_datetime),
  CONSTRAINT ck_events_capacity CHECK (capacity > 0)
) ENGINE=InnoDB;

CREATE TABLE event_registrations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id       INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  status         ENUM('REGISTERED','ATTENDED','CANCELLED') NOT NULL DEFAULT 'REGISTERED',
  registered_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at   DATETIME     NULL,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_registration (event_id, user_id),   -- no duplicate registrations
  KEY ix_event_registrations_user (user_id, status),
  KEY ix_event_registrations_event_status (event_id, status),
  CONSTRAINT fk_event_reg_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_event_reg_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Volunteering
-- ---------------------------------------------------------------------
CREATE TABLE volunteer_opportunities (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id      INT UNSIGNED NOT NULL,
  title_ar         VARCHAR(200) NOT NULL,
  title_en         VARCHAR(200) NOT NULL,
  description_ar   TEXT         NOT NULL,
  description_en   TEXT         NOT NULL,
  location_ar      VARCHAR(200) NOT NULL,
  location_en      VARCHAR(200) NOT NULL,
  start_datetime   DATETIME     NOT NULL,
  end_datetime     DATETIME     NOT NULL,
  volunteer_hours  DECIMAL(5,2) NOT NULL,
  capacity         INT UNSIGNED NOT NULL,
  cancelled_at     DATETIME     NULL,
  cancel_reason    VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_volunteer_start (start_datetime),
  KEY ix_volunteer_end (end_datetime),
  KEY ix_volunteer_category (category_id),
  CONSTRAINT fk_volunteer_category FOREIGN KEY (category_id) REFERENCES volunteer_categories (id),
  CONSTRAINT fk_volunteer_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT ck_volunteer_time CHECK (end_datetime > start_datetime),
  CONSTRAINT ck_volunteer_capacity CHECK (capacity > 0),
  CONSTRAINT ck_volunteer_hours CHECK (volunteer_hours >= 0)
) ENGINE=InnoDB;

CREATE TABLE volunteer_registrations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  opportunity_id  INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  status          ENUM('REGISTERED','ATTENDED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'REGISTERED',
  motivation      TEXT         NULL,
  -- Set only when an admin approves completion. Hours are never awarded on registration.
  hours_awarded   DECIMAL(5,2) NULL,
  reviewed_by     INT UNSIGNED NULL,
  completed_at    DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_volunteer_registration (opportunity_id, user_id),
  KEY ix_volunteer_reg_user (user_id, status),
  KEY ix_volunteer_reg_opp_status (opportunity_id, status),
  CONSTRAINT fk_volunteer_reg_opp FOREIGN KEY (opportunity_id) REFERENCES volunteer_opportunities (id) ON DELETE CASCADE,
  CONSTRAINT fk_volunteer_reg_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_volunteer_reg_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT ck_volunteer_reg_hours CHECK (hours_awarded IS NULL OR hours_awarded >= 0),
  CONSTRAINT ck_volunteer_reg_completed CHECK (status <> 'COMPLETED' OR hours_awarded IS NOT NULL)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Facilities & reservations
-- ---------------------------------------------------------------------
CREATE TABLE facilities (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(40)  NOT NULL,
  name_ar         VARCHAR(150) NOT NULL,
  name_en         VARCHAR(150) NOT NULL,
  description_ar  TEXT         NOT NULL,
  description_en  TEXT         NOT NULL,
  location_ar     VARCHAR(200) NOT NULL,
  location_en     VARCHAR(200) NOT NULL,
  capacity        INT UNSIGNED NULL,
  image_path      VARCHAR(255) NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_facilities_code (code)
) ENGINE=InnoDB;

CREATE TABLE facility_reservations (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  facility_id         INT UNSIGNED NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  purpose             VARCHAR(200) NOT NULL,
  notes               TEXT         NULL,
  start_datetime      DATETIME     NOT NULL,
  end_datetime        DATETIME     NOT NULL,
  expected_attendees  INT UNSIGNED NULL,
  status              ENUM('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  admin_note          TEXT         NULL,
  decided_by          INT UNSIGNED NULL,
  decided_at          DATETIME     NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_reservations_conflict (facility_id, status, start_datetime, end_datetime),
  KEY ix_reservations_user (user_id, created_at),
  CONSTRAINT fk_reservation_facility FOREIGN KEY (facility_id) REFERENCES facilities (id),
  CONSTRAINT fk_reservation_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_reservation_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT ck_reservation_time CHECK (end_datetime > start_datetime)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Suggestions & complaints
-- ---------------------------------------------------------------------
CREATE TABLE feedback (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NULL,                -- NULL only for imported legacy rows
  submitter_name   VARCHAR(120) NULL,                -- legacy snapshot when no user matched
  submitter_email  VARCHAR(190) NULL,
  type             ENUM('SUGGESTION','COMPLAINT') NOT NULL,
  category_id      INT UNSIGNED NOT NULL,
  subject          VARCHAR(200) NOT NULL,
  message          TEXT         NOT NULL,
  status           ENUM('OPEN','IN_PROGRESS','RESOLVED','CLOSED') NOT NULL DEFAULT 'OPEN',
  admin_reply      TEXT         NULL,
  replied_by       INT UNSIGNED NULL,
  replied_at       DATETIME     NULL,
  archived_at      DATETIME     NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_feedback_filters (archived_at, status, type, created_at),
  KEY ix_feedback_user (user_id, created_at),
  CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_feedback_category FOREIGN KEY (category_id) REFERENCES feedback_categories (id),
  CONSTRAINT fk_feedback_replier FOREIGN KEY (replied_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE feedback_attachments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  feedback_id    INT UNSIGNED NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  stored_name    CHAR(36)     NOT NULL,            -- random name on disk, outside the web root
  mime_type      VARCHAR(100) NOT NULL,
  size_bytes     INT UNSIGNED NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attachment_stored (stored_name),
  KEY ix_attachment_feedback (feedback_id),
  CONSTRAINT fk_attachment_feedback FOREIGN KEY (feedback_id) REFERENCES feedback (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Contact messages
-- ---------------------------------------------------------------------
CREATE TABLE contact_messages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  name        VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  message     TEXT         NOT NULL,
  status      ENUM('UNREAD','READ','RESOLVED') NOT NULL DEFAULT 'UNREAD',
  ip_address  VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_contact_status (status, created_at),
  CONSTRAINT fk_contact_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Notifications (stored as translation key + params; rendered in the reader's language)
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED    NOT NULL,
  type        VARCHAR(50)     NOT NULL,
  data        LONGTEXT        NOT NULL CHECK (JSON_VALID(data)),
  link        VARCHAR(255)    NULL,
  read_at     DATETIME        NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_notifications_user (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Settings & audit
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  `key`       VARCHAR(64) NOT NULL,
  value       TEXT        NOT NULL,
  updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id      INT UNSIGNED    NULL,
  action        VARCHAR(64)     NOT NULL,
  subject_type  VARCHAR(40)     NULL,
  subject_id    INT UNSIGNED    NULL,
  meta          LONGTEXT        NULL CHECK (meta IS NULL OR JSON_VALID(meta)),
  ip_address    VARCHAR(45)     NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_subject (subject_type, subject_id),
  KEY ix_audit_actor (actor_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

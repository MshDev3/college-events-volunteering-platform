-- =====================================================================
-- 005 — Mail queue for password-reset emails.
--   Sending mail during the "forgot password" request made it measurably slower for registered
--   addresses than for unknown ones (account enumeration by timing). The request now only records
--   a job; the email is sent after the response (or by `php bin/console mail:work` from cron).
--   A job holds no secret: the reset token is created when the job is processed, so the database
--   still only ever contains sha256(token).
-- =====================================================================

CREATE TABLE mail_queue (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind          VARCHAR(40)  NOT NULL,              -- e.g. password_reset
  user_id       INT UNSIGNED NOT NULL,
  requested_ip  VARCHAR(45)  NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  available_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until  DATETIME     NULL,                  -- claimed by a worker until then
  last_error    VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_mail_queue_available (available_at),
  CONSTRAINT fk_mail_queue_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

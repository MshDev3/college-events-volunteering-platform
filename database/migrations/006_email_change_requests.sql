-- =====================================================================
-- 006 — Email change confirmation.
--   A new login email is stored here until its owner opens the link sent to it; only then does
--   it replace users.email. One pending request per user (a new request replaces the old one).
--   Only sha256(token) is stored; the token itself exists only in the email.
-- =====================================================================

CREATE TABLE email_change_requests (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  new_email   VARCHAR(190) NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_change_user (user_id),
  UNIQUE KEY uq_email_change_token (token_hash),
  KEY ix_email_change_expires (expires_at),
  CONSTRAINT fk_email_change_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

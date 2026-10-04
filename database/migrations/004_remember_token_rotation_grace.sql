-- =====================================================================
-- 004 — Remember-me rotation grace window.
--   Two requests sent at the same moment with the same remember cookie (e.g. a browser restoring
--   several tabs) used to look like a stolen, replayed cookie and revoked every token of the user.
--   The previous validator hash is now kept for a few seconds after each rotation and accepted
--   (without rotating again) during that window; any other mismatch is still treated as theft.
-- =====================================================================

ALTER TABLE auth_remember_tokens
  ADD COLUMN previous_hash CHAR(64) NULL AFTER validator_hash,
  ADD COLUMN rotated_at DATETIME NULL AFTER previous_hash;

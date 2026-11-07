-- 009: e-mail verification, password reset and session revocation (phone OTP login has been removed).
-- Run in phpMyAdmin BEFORE deploying the matching code, after 008. Works on MySQL 5.7/8 and MariaDB.
-- RE-RUN SAFETY: each ALTER/INDEX runs only if it is missing, and the one data-changing statement (grandfathering existing
-- accounts as verified) runs ONLY in the execution that actually added users.email_verified_at. Running the file again
-- later therefore cannot mark anyone's unverified e-mail as verified.

-- 1) users.email_verified_at -----------------------------------------------------------------------------
SET @bk_missing := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at');
SET @bk_sql := IF(@bk_missing, 'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL', 'SELECT 1');
PREPARE bk_stmt FROM @bk_sql; EXECUTE bk_stmt; DEALLOCATE PREPARE bk_stmt;

-- Existing accounts keep working: every account that already has an e-mail address is treated as verified (it was
-- created before verification existed, or came from Google). New registrations start NULL and must click the link.
SET @bk_sql := IF(@bk_missing, 'UPDATE users SET email_verified_at = NOW() WHERE email IS NOT NULL AND email <> ''''', 'SELECT 1');
PREPARE bk_stmt FROM @bk_sql; EXECUTE bk_stmt; DEALLOCATE PREPARE bk_stmt;

-- 2) users.tokens_valid_after: sessions issued before this moment are rejected (set by password reset) ------------
SET @bk_missing := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tokens_valid_after');
SET @bk_sql := IF(@bk_missing, 'ALTER TABLE users ADD COLUMN tokens_valid_after DATETIME NULL', 'SELECT 1');
PREPARE bk_stmt FROM @bk_sql; EXECUTE bk_stmt; DEALLOCATE PREPARE bk_stmt;

-- 3) Single-use e-mail tokens. Only the SHA-256 of a token is stored. -----------------------------------------------
CREATE TABLE IF NOT EXISTS auth_tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  purpose    VARCHAR(20)  NOT NULL,              -- verify_email | password_reset
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  created_at DATETIME     NOT NULL,
  UNIQUE KEY uq_auth_token_hash (token_hash),
  KEY idx_auth_token_user (user_id, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) One account per e-mail address, enforced by the database -- but ONLY if existing data allows it, so this file can
--    never fail on old duplicates. If duplicates exist the index is skipped; list them with the query below and merge/clear
--    them by hand, then run this file again.
--      SELECT LOWER(email) e, COUNT(*) n FROM users WHERE email IS NOT NULL AND email<>'' GROUP BY LOWER(email) HAVING n>1;
SET @bk_has_idx := (SELECT COUNT(*) > 0 FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email');
SET @bk_dups := (SELECT COUNT(*) FROM (SELECT LOWER(email) FROM users WHERE email IS NOT NULL GROUP BY LOWER(email) HAVING COUNT(*) > 1) d);
SET @bk_sql := IF(@bk_has_idx OR @bk_dups > 0, 'SELECT 1', 'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
PREPARE bk_stmt FROM @bk_sql; EXECUTE bk_stmt; DEALLOCATE PREPARE bk_stmt;
SELECT IF(@bk_dups > 0, CONCAT('NOTICE: ', @bk_dups, ' duplicate e-mail address(es) exist; the unique index was NOT created.'), 'OK: no duplicate e-mails') AS note;

-- ROLLBACK (only together with reverting the code):
--   ALTER TABLE users DROP COLUMN email_verified_at, DROP COLUMN tokens_valid_after;   -- and DROP INDEX uq_users_email if created
--   DROP TABLE auth_tokens;

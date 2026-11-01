-- 010: admin-controlled ON/OFF switches (bookings, payments) and admin two-factor (TOTP) storage.
-- Run in phpMyAdmin BEFORE deploying the matching code, after 008 and 009. MySQL 5.7/8 and MariaDB.
-- RE-RUN SAFETY: each ALTER runs only if its column is missing; the two settings rows are inserted only if absent
-- (an existing ON/OFF choice is never overwritten); nothing is dropped or rewritten.

-- 1) app_settings: tiny key/value store for the switches. 'bookings_enabled' and 'payments_enabled' ('1' = on, '0' = off).
CREATE TABLE IF NOT EXISTS app_settings (
  k          VARCHAR(60)  NOT NULL PRIMARY KEY,
  v          VARCHAR(255) NOT NULL,
  updated_at DATETIME     NOT NULL,
  updated_by INT          NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO app_settings (k, v, updated_at, updated_by) VALUES ('bookings_enabled', '1', NOW(), NULL);
INSERT IGNORE INTO app_settings (k, v, updated_at, updated_by) VALUES ('payments_enabled', '1', NOW(), NULL);

-- 2) users.* columns for admin two-factor authentication (all NULL/0 for everyone until an admin enrols).
SET @bk_m := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'admin_totp_secret');
SET @bk_s := IF(@bk_m, 'ALTER TABLE users ADD COLUMN admin_totp_secret VARCHAR(255) NULL', 'SELECT 1');
PREPARE bk_x FROM @bk_s; EXECUTE bk_x; DEALLOCATE PREPARE bk_x;

SET @bk_m := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'admin_totp_enabled');
SET @bk_s := IF(@bk_m, 'ALTER TABLE users ADD COLUMN admin_totp_enabled TINYINT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE bk_x FROM @bk_s; EXECUTE bk_x; DEALLOCATE PREPARE bk_x;

SET @bk_m := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'admin_totp_last_step');
SET @bk_s := IF(@bk_m, 'ALTER TABLE users ADD COLUMN admin_totp_last_step BIGINT NULL', 'SELECT 1');
PREPARE bk_x FROM @bk_s; EXECUTE bk_x; DEALLOCATE PREPARE bk_x;

SET @bk_m := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'admin_recovery_hashes');
SET @bk_s := IF(@bk_m, 'ALTER TABLE users ADD COLUMN admin_recovery_hashes TEXT NULL', 'SELECT 1');
PREPARE bk_x FROM @bk_s; EXECUTE bk_x; DEALLOCATE PREPARE bk_x;

-- EMERGENCY ACCESS (an admin lost their authenticator AND their recovery codes): switch 2FA off for that one admin directly.
--   UPDATE users SET admin_totp_enabled = 0, admin_totp_secret = NULL, admin_recovery_hashes = NULL WHERE email = 'admin@example.com' AND role = 'admin';

-- ROLLBACK (only together with reverting the code):
--   ALTER TABLE users DROP COLUMN admin_totp_secret, DROP COLUMN admin_totp_enabled, DROP COLUMN admin_totp_last_step, DROP COLUMN admin_recovery_hashes;
--   DROP TABLE app_settings;

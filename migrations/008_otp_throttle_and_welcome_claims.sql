-- 008: OTP brute-force throttling + one-time welcome-credit ledger.
-- Run ONCE in phpMyAdmin against the production database, BEFORE deploying the matching code.
-- Safe to re-run: every statement is idempotent (IF NOT EXISTS / INSERT IGNORE).
-- Requires InnoDB (the default). Does not modify any existing table or row.

-- 1) Attempt counters used by verify_otp (per account and per IP). The application also creates this table
--    lazily if it is missing, but creating it here avoids a first-request DDL.
CREATE TABLE IF NOT EXISTS auth_throttle (
  bucket       VARCHAR(40)  NOT NULL,
  k            VARCHAR(190) NOT NULL,
  hits         INT          NOT NULL DEFAULT 0,
  window_start DATETIME     NOT NULL,
  PRIMARY KEY (bucket, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Durable welcome-credit claims. A claim is the ledger entry for the credit: the application inserts the claim and
--    credits the wallet in ONE transaction, and the two UNIQUE keys make a second credit impossible for the same
--    account (user_id) or for the same verified identity (identity_key = sha256('<type>:<value>')).
CREATE TABLE IF NOT EXISTS welcome_credit_claims (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT          NOT NULL,
  identity_type VARCHAR(20)  NOT NULL,           -- phone | google | legacy
  identity_key  CHAR(64)     NOT NULL,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  source        VARCHAR(20)  NOT NULL DEFAULT 'signup',   -- signup | backfill
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_welcome_user (user_id),
  UNIQUE KEY uq_welcome_identity (identity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) BACKFILL: every customer that exists today already received the old unconditional credit, so mark them as
--    claimed (amount 0, source 'backfill'). Without this they would be credited AGAIN at their next phone login.
--    Phone identities use the same canonical form as the PHP code (last 10 digits after stripping + space - ( )).
--    Pass 1 claims the phone identity (so re-registering the same number later cannot claim again); where two old
--    accounts share a number, pass 2 closes the remaining account(s) under a per-user 'legacy' key.
INSERT IGNORE INTO welcome_credit_claims (user_id, identity_type, identity_key, amount, source, created_at)
SELECT id, 'phone',
       SHA2(CONCAT('phone:', RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''), 10)), 256),
       0, 'backfill', NOW()
FROM users
WHERE role = 'customer' AND phone IS NOT NULL AND phone <> ''
ORDER BY id;

INSERT IGNORE INTO welcome_credit_claims (user_id, identity_type, identity_key, amount, source, created_at)
SELECT id, 'legacy', SHA2(CONCAT('legacy:', id), 256), 0, 'backfill', NOW()
FROM users
WHERE role = 'customer';

-- Verify (expect 0 rows): customers that are NOT closed against a second credit.
--   SELECT u.id FROM users u LEFT JOIN welcome_credit_claims c ON c.user_id = u.id WHERE u.role='customer' AND c.id IS NULL;

-- ROLLBACK (only if you also revert the code; the credit rule then falls back to whatever the old code did):
--   DROP TABLE welcome_credit_claims;
--   DROP TABLE auth_throttle;

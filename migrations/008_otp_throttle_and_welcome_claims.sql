-- 008: attempt counters + one-time welcome-credit ledger.
-- Run in phpMyAdmin against the production database BEFORE deploying the matching code (and before 009).
-- RE-RUN SAFETY: every statement is idempotent, and the one data-changing statement (the back-fill) only runs while
-- welcome_credit_claims is still EMPTY, i.e. on the first run. Running this file again later changes nothing.
-- Requires InnoDB (the default). Does not modify any existing table or row.

-- 1) Attempt counters (login / admin-login throttling). The application also creates this table lazily if missing.
CREATE TABLE IF NOT EXISTS auth_throttle (
  bucket       VARCHAR(40)  NOT NULL,
  k            VARCHAR(190) NOT NULL,
  hits         INT          NOT NULL DEFAULT 0,
  window_start DATETIME     NOT NULL,
  PRIMARY KEY (bucket, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Durable welcome-credit claims. The application inserts a claim and credits the wallet in ONE transaction; the two
--    UNIQUE keys make a second credit impossible for the same account (user_id) or the same verified identity
--    (identity_key = sha256('email:<canonical address>') or sha256('google:<subject id>')).
CREATE TABLE IF NOT EXISTS welcome_credit_claims (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT           NOT NULL,
  identity_type VARCHAR(20)   NOT NULL,                       -- email | google | legacy
  identity_key  CHAR(64)      NOT NULL,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  source        VARCHAR(20)   NOT NULL DEFAULT 'signup',     -- signup | backfill
  created_at    DATETIME      NOT NULL,
  UNIQUE KEY uq_welcome_user (user_id),
  UNIQUE KEY uq_welcome_identity (identity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) BACK-FILL, FIRST RUN ONLY. Every customer that exists today already received the old unconditional credit, so mark
--    them claimed (amount 0, source 'backfill'); otherwise they could be credited a second time.
--    The guard is evaluated once, before the INSERT, so the statement is atomic and a re-run inserts nothing.
SET @bk_first_run := (SELECT COUNT(*) = 0 FROM welcome_credit_claims);
INSERT INTO welcome_credit_claims (user_id, identity_type, identity_key, amount, source, created_at)
SELECT u.id, 'legacy', SHA2(CONCAT('legacy:', u.id), 256), 0, 'backfill', NOW()
FROM users u
WHERE u.role = 'customer' AND @bk_first_run = 1;

-- Verify (expect 0 rows right after the first run): customers that are NOT closed against a second credit.
--   SELECT u.id FROM users u LEFT JOIN welcome_credit_claims c ON c.user_id = u.id WHERE u.role='customer' AND c.id IS NULL;

-- ROLLBACK (only together with reverting the code):
--   DROP TABLE welcome_credit_claims;
--   DROP TABLE auth_throttle;

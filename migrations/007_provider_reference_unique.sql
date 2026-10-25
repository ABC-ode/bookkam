-- 007: make payments.provider_reference unique (one provider reference = one payment row).
--
-- REQUIRES 006 to have been applied first. Run ONCE against the existing MySQL database.
--
-- STEP 1 (read-only): this must return zero rows. If it returns rows, resolve the
-- duplicates by hand BEFORE running step 2 (the ALTER fails, leaving the table unchanged,
-- if duplicates exist).
--   SELECT provider_reference, COUNT(*) AS n
--   FROM payments
--   WHERE provider_reference IS NOT NULL
--   GROUP BY provider_reference HAVING n > 1;
--
-- STEP 2: single atomic ALTER (NULLs stay allowed and may repeat: cash and wallet rows
-- have no provider reference).
ALTER TABLE payments
  DROP INDEX idx_payments_provider_reference,
  ADD UNIQUE KEY uq_payments_provider_reference (provider_reference);

-- ROLLBACK (safe; restores the 006 state):
--   ALTER TABLE payments
--     DROP INDEX uq_payments_provider_reference,
--     ADD INDEX idx_payments_provider_reference (provider_reference);

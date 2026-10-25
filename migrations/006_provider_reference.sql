-- Introduce provider-neutral references while preserving legacy data/column.
-- Run once against the existing MySQL database before enabling the new payment code.
ALTER TABLE payments
  ADD COLUMN provider_reference VARCHAR(255) NULL;

UPDATE payments
SET provider_reference = paystack_reference
WHERE provider_reference IS NULL
  AND paystack_reference IS NOT NULL;

CREATE INDEX idx_payments_provider_reference
  ON payments (provider_reference);

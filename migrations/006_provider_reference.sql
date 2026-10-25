-- Forward migration: introduce provider-neutral transaction references.
-- Keep the legacy column for backwards compatibility with existing deployments.
ALTER TABLE payments
  ADD COLUMN provider_reference VARCHAR(255) NULL;

UPDATE payments
SET provider_reference = paystack_reference
WHERE provider_reference IS NULL
  AND paystack_reference IS NOT NULL;

CREATE INDEX idx_payments_provider_reference
  ON payments (provider_reference);

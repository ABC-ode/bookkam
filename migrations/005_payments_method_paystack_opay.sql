-- Run this once in phpMyAdmin against your BOOKKAM database.
-- OPay is the supported online payment provider. Existing 'card' values
-- remain available for historical records; Paystack is not enabled.

ALTER TABLE payments
  MODIFY COLUMN method ENUM('cash','wallet','card','dva','test','opay')
  NOT NULL DEFAULT 'cash';

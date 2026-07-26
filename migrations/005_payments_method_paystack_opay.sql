-- Run this once in phpMyAdmin against your excenvbq_bookkam database.
-- payments.php (FEATURE 4) inserts method values 'paystack' and 'opay',
-- neither of which exist in the current enum('cash','wallet','card','dva','test').
-- Every card or Opay payment attempt would fail on this column right now.
-- Existing 'card' rows are left as-is for history; new Paystack payments
-- will be recorded as 'paystack' going forward.

ALTER TABLE payments
  MODIFY COLUMN method ENUM('cash','wallet','card','dva','test','paystack','opay')
  NOT NULL DEFAULT 'cash';

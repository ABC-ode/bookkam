-- INFERRED pre-migration shape of the `payments` table, used ONLY to exercise migrations
-- 003_payments_booking_kind, 005, 006 and 007 against MySQL in CI. The real production
-- schema is not in this repository: before running the migrations in production, compare
-- with `SHOW CREATE TABLE payments;` (see docs/payments.md).
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  method ENUM('cash','wallet','card','dva','test','paystack','opay') NOT NULL DEFAULT 'cash',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  paystack_reference VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO payments (booking_id, amount, method, status, paystack_reference) VALUES
  (1, 15000, 'paystack', 'success', 'BKM-WEBSITE-1-1700000001'),
  (2, 20000, 'paystack', 'pending', 'BKM-WEBSITE-2-1700000002'),
  (3, 5000,  'cash',     'pending', NULL),
  (4, 9000,  'wallet',   'success', NULL),
  (5, 7000,  'cash',     'pending', NULL);

-- Run this once in phpMyAdmin against your excenvbq_bookkam database.
-- payments.php needs to know whether a payment belongs to a website booking
-- (bookings table) or an event booking (event_bookings table) so webhooks
-- and verify calls can confirm the right record.

ALTER TABLE payments
  ADD COLUMN booking_kind VARCHAR(10) NOT NULL DEFAULT 'website' AFTER booking_id;

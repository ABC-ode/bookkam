-- TEST-ONLY MySQL/MariaDB schema, reconstructed from the columns the PHP code references.
-- It is NOT the production schema. Used by tests/functional/otp_welcome_tests.py (BK_BACKEND=mysql);
-- migrations/008_otp_throttle_and_welcome_claims.sql is applied on top by the runner.
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS users, drivers, cars, car_media, pricing, bookings, payments, notifications, promo_codes,
  driver_payouts, driver_locations, messages, damage_reports, car_location_pricing, event_bookings,
  event_package_pricing, event_package_cars, event_packages, auth_throttle, auth_revocations, welcome_credit_claims, cities;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(30), email VARCHAR(120), name VARCHAR(120), role VARCHAR(20), city VARCHAR(60),
  wallet_balance DECIMAL(12,2) DEFAULT 0, password_hash VARCHAR(255), google_id VARCHAR(100), apple_id VARCHAR(100), otp_code VARCHAR(10),
  otp_expires_at DATETIME, otp_attempts INT DEFAULT 0, otp_last_sent DATETIME, is_blacklisted TINYINT DEFAULT 0, blacklist_reason VARCHAR(255),
  photo_url VARCHAR(255), referral_code VARCHAR(20), created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE drivers(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, status VARCHAR(20), is_online TINYINT DEFAULT 0, bank_name VARCHAR(60),
  account_number VARCHAR(30), account_name VARCHAR(80), rating DECIMAL(3,2) DEFAULT 5, total_trips INT DEFAULT 0);
CREATE TABLE cars(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120), car_type VARCHAR(20), category VARCHAR(30), city VARCHAR(60), plate_number VARCHAR(30),
  color VARCHAR(30), year INT, make VARCHAR(40), model VARCHAR(40), transmission VARCHAR(20), fuel_type VARCHAR(20), seats INT, driver_id INT,
  availability_status VARCHAR(20) DEFAULT 'available', is_available TINYINT DEFAULT 1, surge_multiplier DECIMAL(4,2) DEFAULT 1, security_deposit DECIMAL(12,2) DEFAULT 0,
  description TEXT, mileage_limit_per_day INT, expected_return_at DATETIME, created_by_admin TINYINT DEFAULT 0);
CREATE TABLE car_media(id INT AUTO_INCREMENT PRIMARY KEY, car_id INT, media_type VARCHAR(10), url VARCHAR(500), status VARCHAR(20), sort_order INT DEFAULT 0, rejection_reason VARCHAR(255));
CREATE TABLE pricing(id INT AUTO_INCREMENT PRIMARY KEY, city VARCHAR(60), booking_type VARCHAR(30), per_hour_price DECIMAL(12,2), per_day_price DECIMAL(12,2), base_price DECIMAL(12,2), per_km_price DECIMAL(12,2));
CREATE TABLE bookings(id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT, driver_id INT, car_id INT, booking_type VARCHAR(20), car_type VARCHAR(20),
  status VARCHAR(20) DEFAULT 'pending', pickup_address VARCHAR(500), dropoff_address VARCHAR(500), pickup_coords VARCHAR(100), dropoff_coords VARCHAR(100),
  duration_hours DECIMAL(8,2), duration_days INT, total_price DECIMAL(12,2), security_deposit DECIMAL(12,2) DEFAULT 0, commission_amount DECIMAL(12,2) DEFAULT 0,
  city VARCHAR(60), start_time DATETIME, end_time DATETIME, payment_status VARCHAR(20) DEFAULT 'unpaid', deposit_released TINYINT DEFAULT 0,
  deposit_refund_amount DECIMAL(12,2), created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE payments(id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, amount DECIMAL(12,2), deposit_amount DECIMAL(12,2) DEFAULT 0, method VARCHAR(20),
  status VARCHAR(20), is_test TINYINT DEFAULT 0, paystack_reference VARCHAR(100), booking_kind VARCHAR(20) DEFAULT 'website', created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, title VARCHAR(200), body TEXT, type VARCHAR(30), created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE promo_codes(id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30), discount_percent DECIMAL(5,2), max_uses INT NULL, used_count INT DEFAULT 0,
  expiry_date DATE NULL, city_restriction VARCHAR(60) NULL, is_active TINYINT DEFAULT 1);
CREATE TABLE driver_payouts(id INT AUTO_INCREMENT PRIMARY KEY, driver_id INT, amount DECIMAL(12,2), period_start DATETIME, period_end DATETIME,
  status VARCHAR(20), paid_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE driver_locations(id INT AUTO_INCREMENT PRIMARY KEY, driver_id INT, lat DOUBLE, lng DOUBLE, heading DOUBLE, booking_id INT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE messages(id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, sender_id INT, message_text TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE damage_reports(id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, description TEXT, repair_cost DECIMAL(12,2), reported_by INT, created_at DATETIME);
CREATE TABLE cities(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80), state VARCHAR(80), is_active TINYINT DEFAULT 1, secret_note VARCHAR(80));

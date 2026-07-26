-- Run this once in phpMyAdmin against your excenvbq_bookkam database.
-- CRITICAL: event_bookings does not exist in the live database at all.
-- Every event booking submission (Grovve Yard / Carribbean Vibes) has been
-- failing silently with a database error since event-booking.php was written.
-- This creates the table with every column that code already assumes.

CREATE TABLE IF NOT EXISTS event_bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(50) NOT NULL,
  event_name VARCHAR(150) NOT NULL,
  pickup_address VARCHAR(500) NOT NULL,
  dropoff_address VARCHAR(500) NOT NULL,
  pickup_zone VARCHAR(100) NOT NULL,
  normalized_zone VARCHAR(50) NOT NULL DEFAULT 'municipal',
  pickup_lng DECIMAL(10,7) DEFAULT NULL,
  pickup_lat DECIMAL(10,7) DEFAULT NULL,
  event_date DATE NOT NULL,
  date_display VARCHAR(100) DEFAULT NULL,
  pickup_time TIME NOT NULL,
  passengers TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ride_type VARCHAR(30) DEFAULT 'car',
  package_id VARCHAR(50) DEFAULT NULL,
  bus_route_id VARCHAR(50) DEFAULT NULL,
  car_id INT UNSIGNED DEFAULT NULL,
  selected_car VARCHAR(150) DEFAULT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount_code VARCHAR(30) DEFAULT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  final_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  confirmed_by INT UNSIGNED DEFAULT NULL,
  confirmed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_event_key (event_key),
  KEY idx_status (status),
  KEY idx_car_id (car_id),
  CONSTRAINT fk_event_bookings_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE SET NULL,
  CONSTRAINT fk_event_bookings_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

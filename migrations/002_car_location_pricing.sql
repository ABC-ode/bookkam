-- Run this once in phpMyAdmin against your excenvbq_bookkam database.
-- Adds per-car, per-location pricing used by the admin car management page.

CREATE TABLE IF NOT EXISTS car_location_pricing (
  id INT AUTO_INCREMENT PRIMARY KEY,
  car_id INT NOT NULL,
  location VARCHAR(100) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY car_location_unique (car_id, location),
  CONSTRAINT fk_car_location_pricing_car
    FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
);

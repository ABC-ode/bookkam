-- Run this once in phpMyAdmin against your excenvbq_bookkam database.
-- Adds admin-manageable "package" tiers (Diamond/Gold/Silver etc.) for event
-- booking modals, each with its own location-based pricing and a list of cars
-- (with photos) the customer can pick from within that tier.

CREATE TABLE IF NOT EXISTS event_packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(50) NOT NULL,
  package_key VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  tagline VARCHAR(255) DEFAULT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY event_package_unique (event_key, package_key)
);

CREATE TABLE IF NOT EXISTS event_package_pricing (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  location VARCHAR(100) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  UNIQUE KEY package_location_unique (package_id, location),
  CONSTRAINT fk_event_package_pricing_package
    FOREIGN KEY (package_id) REFERENCES event_packages(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS event_package_cars (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  model VARCHAR(100) NOT NULL,
  year VARCHAR(10) DEFAULT NULL,
  photo_url VARCHAR(500) DEFAULT NULL,
  sort_order INT DEFAULT 0,
  CONSTRAINT fk_event_package_cars_package
    FOREIGN KEY (package_id) REFERENCES event_packages(id) ON DELETE CASCADE
);

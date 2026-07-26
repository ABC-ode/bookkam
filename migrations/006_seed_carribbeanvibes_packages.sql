-- Run this once in phpMyAdmin against your excenvbq_bookkam database, AFTER
-- migrations 002-005 have already been applied (event_packages etc. must exist).
--
-- Seeds the Carribbean Vibes package tiers you sent: Diamond, Gold, Silver,
-- Party Bus — each with its vehicles and location pricing.
--
-- IMPORTANT — location naming: prices are matched against whatever the
-- customer types into the pickup-zone field, using a loose two-way substring
-- match (see packageLocationPrice() in index.php). The zone datalist offers
-- "Municipal & Calabar South" and "8 Miles Route" as the two real options, so
-- pricing rows below use those exact labels rather than your JSON's
-- "municipal_calabar_south" / "eight_miles" keys — those snake_case keys
-- would never match anything a customer actually types and every package
-- would silently fall back to "Negotiate".
--
-- Vehicle years: where your list had "(2022)" etc. in the name, that's split
-- into model + year columns. Where no year was given, year is left NULL.
--
-- Photos: none were provided, so photo_url is NULL for every car — the cards
-- will show a placeholder icon until you upload real photos via
-- Admin → Event Packages → (car) → Edit.
--
-- Party Bus: your JSON gives a flat fare_per_person of 2000, but the booking
-- system has no per-passenger pricing anywhere — every package price is a
-- single flat amount per booking, regardless of passenger count. This script
-- applies 2000 as that flat per-booking price for both locations. If you
-- actually want it multiplied by passenger count, that needs a code change
-- in event-booking.php (say so and I'll build it) — right now it will not
-- automatically scale with the number of passengers entered.

-- ── Diamond ──────────────────────────────────────────────────────────────
INSERT INTO event_packages (event_key, package_key, name, tagline, sort_order)
VALUES ('carribbeanvibes', 'diamond', 'Diamond Package', 'Premium Luxury Experience', 10);

INSERT INTO event_package_pricing (package_id, location, price) VALUES
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='diamond'), 'Municipal & Calabar South', 10000),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='diamond'), '8 Miles Route', 15000);

INSERT INTO event_package_cars (package_id, model, year, sort_order) VALUES
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='diamond'), 'Toyota Land Cruiser', '2022', 0),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='diamond'), 'Mazda CX-9', NULL, 1),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='diamond'), 'Mercedes-Benz C300', NULL, 2);

-- ── Gold ─────────────────────────────────────────────────────────────────
INSERT INTO event_packages (event_key, package_key, name, tagline, sort_order)
VALUES ('carribbeanvibes', 'gold', 'Gold Package', 'Comfort Meets Class', 20);

INSERT INTO event_package_pricing (package_id, location, price) VALUES
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='gold'), 'Municipal & Calabar South', 8000),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='gold'), '8 Miles Route', 10000);

INSERT INTO event_package_cars (package_id, model, year, sort_order) VALUES
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='gold'), 'Toyota Prado', '2015', 0),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='gold'), 'Toyota Camry', NULL, 1),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='gold'), 'Suzuki', NULL, 2);

-- ── Silver ───────────────────────────────────────────────────────────────
INSERT INTO event_packages (event_key, package_key, name, tagline, sort_order)
VALUES ('carribbeanvibes', 'silver', 'Silver Package', 'Affordable & Reliable', 30);

INSERT INTO event_package_pricing (package_id, location, price) VALUES
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='silver'), 'Municipal & Calabar South', 5000),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='silver'), '8 Miles Route', 7000);

INSERT INTO event_package_cars (package_id, model, year, sort_order) VALUES
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='silver'), 'Toyota Camry Spider', NULL, 0),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='silver'), 'Honda Civic', NULL, 1),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='silver'), 'Hyundai Sonata', NULL, 2);

-- ── Party Bus ────────────────────────────────────────────────────────────
-- No vehicle list given for this one — it's presumably a shared shuttle, not
-- a specific car, so no event_package_cars rows are inserted. Note: with zero
-- cars, this package currently WON'T be selectable in the modal (car list is
-- shown per package and customers pick one) — add at least one row via
-- Admin → Event Packages if Party Bus should be pickable the same way as the
-- others, or say so if it should work differently (e.g. no car picker at all).
INSERT INTO event_packages (event_key, package_key, name, tagline, sort_order)
VALUES ('carribbeanvibes', 'party_bus', 'Party Bus', NULL, 40);

INSERT INTO event_package_pricing (package_id, location, price) VALUES
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='party_bus'), 'Municipal & Calabar South', 2000),
((SELECT id FROM event_packages WHERE event_key='carribbeanvibes' AND package_key='party_bus'), '8 Miles Route', 2000);

<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

// Everything here is safe for anonymous visitors: feature flags and a PUBLIC map token (only while Mapbox is enabled).
respond([
    "success" => true,
    "whatsapp_support" => BOOKKAM_WHATSAPP_SUPPORT,
    "bookings_enabled" => appSettingOn($pdo, "bookings_enabled"),
    "payments_enabled" => appSettingOn($pdo, "payments_enabled") && appSettingOn($pdo, "bookings_enabled"),
    "mapbox" => MAPBOX_ENABLED ? ["enabled" => true, "token" => MAPBOX_PUBLIC_TOKEN] : ["enabled" => false],
]);

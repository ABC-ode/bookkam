<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input = json_decode(file_get_contents("php://input"), true) ?? [];

$eventKey = trim($input["event"] ?? "");
$eventName = trim($input["event_name"] ?? "");
$pickup = trim($input["pickup_address"] ?? "");
$dropoff = trim($input["dropoff_address"] ?? "");
$zone = trim($input["zone"] ?? "municipal");
$zoneLabel = trim($input["zone_label"] ?? $zone);
$date = trim($input["date"] ?? "");
$dateDisplay = trim($input["date_display"] ?? "");
$time = trim($input["time"] ?? "");
$passengers = (int)($input["passengers"] ?? 1);
$rideType = trim($input["ride_type"] ?? "car");
$package = trim($input["package"] ?? "");
$busRoute = trim($input["bus_route"] ?? "");
$carId = (int)($input["car_id"] ?? 0);
$selectedCar = trim($input["selected_car"] ?? "");
$clientPrice = (float)($input["price"] ?? 0); // display only — never trusted
$pickupLng = $input["pickup_lng"] ?? null;
$pickupLat = $input["pickup_lat"] ?? null;

if (!$eventKey || !$eventName) respondError("Event is required");
if (!$pickup || !$dropoff || !$zoneLabel) respondError("Pickup, drop-off, and pickup zone are required");
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) respondError("Valid event date is required");
if (!$time || !preg_match('/^\d{2}:\d{2}$/', $time)) respondError("Valid pickup time is required");
if ($passengers < 1 || $passengers > 20) respondError("Valid passenger count is required");
if (!$selectedCar || ($package === "" && !$carId)) respondError("Select a car for this booking");

// Price comes from the database only (admin-set per-car or per-package location pricing),
// using the same zone matching the booking form uses. No match => the customer must negotiate.
function matchZonePrice(array $rows, string $zoneLabel) {
    $chosen = strtolower(trim($zoneLabel));
    foreach ($rows as $r) {
        $loc = strtolower(trim($r["location"] ?? ""));
        if ($loc !== "" && ($loc === $chosen || str_contains($chosen, $loc) || str_contains($loc, $chosen))) return (float)$r["price"];
    }
    return null;
}
if ($package !== "") {
    $q = $pdo->prepare("SELECT pp.location, pp.price FROM event_package_pricing pp JOIN event_packages p ON p.id=pp.package_id WHERE p.event_key=? AND p.package_key=?");
    $q->execute([$eventKey, $package]);
} else {
    $q = $pdo->prepare("SELECT location, price FROM car_location_pricing WHERE car_id=?");
    $q->execute([$carId]);
}
$price = matchZonePrice($q->fetchAll(), $zoneLabel);
if ($price === null || $price <= 0) respondError("No fixed price for this selection — please negotiate via WhatsApp", 422);

$normalizedZone = (str_contains(strtolower($zoneLabel), "8") || str_contains(strtolower($zoneLabel), "mile") || str_contains(strtolower($zoneLabel), "mcc"))
    ? "8miles"
    : "municipal";

$pdo->beginTransaction();
try {
    $discountCode = null;
    $discountPercent = 0;
    $promoId = null;
    $inputCode = trim($input["discount_code"] ?? "");
    if ($inputCode) {
        $promoStmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code=? AND is_active=1 LIMIT 1 FOR UPDATE");
        $promoStmt->execute([$inputCode]);
        $promo = $promoStmt->fetch();
        $withinLimit = $promo && ($promo["max_uses"] === null || (int)$promo["used_count"] < (int)$promo["max_uses"]);
        if ($promo && $withinLimit && (empty($promo["expiry_date"]) || strtotime($promo["expiry_date"]) >= strtotime(date("Y-m-d")))) {
            $discountCode = $promo["code"];
            $discountPercent = (float)$promo["discount_percent"];
            $promoId = $promo["id"];
        }
    }
    $finalPrice = $discountPercent > 0 ? round($price * (1 - ($discountPercent / 100)), 2) : $price;

    $stmt = $pdo->prepare("INSERT INTO event_bookings
        (event_key,event_name,pickup_address,dropoff_address,pickup_zone,normalized_zone,pickup_lng,pickup_lat,
         event_date,date_display,pickup_time,passengers,ride_type,package_id,bus_route_id,car_id,selected_car,
         price,discount_code,discount_percent,final_price,status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending')");
    $stmt->execute([
        $eventKey,$eventName,$pickup,$dropoff,$zoneLabel,$normalizedZone,$pickupLng,$pickupLat,
        $date,$dateDisplay,$time,$passengers,$rideType,$package ?: null,$busRoute ?: null,$carId ?: null,$selectedCar ?: null,
        $price,$discountCode,$discountPercent,$finalPrice
    ]);
    $bookingId = (int)$pdo->lastInsertId();

    if ($promoId) {
        $pdo->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=?")->execute([$promoId]);
    }

    $pdo->commit();

    respond([
        "success" => true,
        "booking_id" => $bookingId,
        "status" => "pending",
        "discount_code" => $discountCode,
        "discount_percent" => $discountPercent,
        "price" => $price,
        "final_price" => $finalPrice,
        "message" => "Booking received and awaiting confirmation",
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    respondError("Could not create booking", 500);
}
?>

<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$eventKey = trim($_GET["event"] ?? "grovveyard");

$stmt = $pdo->prepare("SELECT event_key, title, eyebrow, event_date FROM home_booking_cards WHERE event_key=? LIMIT 1");
$stmt->execute([$eventKey]);
$card = $stmt->fetch();

if (!$card) {
    $stmt = $pdo->query("SELECT event_key, title, eyebrow, event_date FROM home_booking_cards WHERE is_active=1 ORDER BY sort_order ASC, id ASC LIMIT 1");
    $card = $stmt->fetch();
}

if (!$card) {
    $card = [
        "event_key" => $eventKey,
        "title" => "Event",
        "eyebrow" => "Event shuttle",
        "event_date" => date("Y-m-d"),
    ];
}

$date = $card["event_date"] ?: date("Y-m-d");
$dateDisplay = date("l, F j, Y", strtotime($date));
$eventName = trim(str_replace(["· Event shuttle", "Event shuttle"], "", $card["eyebrow"] ?: ""));
if (!$eventName) $eventName = $card["title"] ?: "Event";

respond([
    "success" => true,
    "event" => [
        "name" => $eventName,
        "eyebrow" => $card["eyebrow"] ?: "Event shuttle",
        "date" => $date,
        "date_display" => $dateDisplay,
        "venue_lng" => 8.3150036,
        "venue_lat" => 5.0423748,
        "requires_access_code" => false,
        "party_bus" => ["enabled" => false, "routes" => []],
        "packages" => [],
    ],
]);
?>

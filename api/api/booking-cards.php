<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$action = $_GET["action"] ?? "get_active";

switch ($action) {
    case "get_active": {
        $s = $pdo->query("SELECT event_key, modal_id, title, subtitle, eyebrow, event_date, image_url
            FROM home_booking_cards
            WHERE is_active=1
            ORDER BY sort_order ASC, id ASC
            LIMIT 1");
        respond(["cards" => $s->fetchAll()]);
    }

    default: respondError("Invalid action");
}
?>

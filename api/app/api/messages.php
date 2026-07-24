<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);
if (!$user) respondError("Unauthorised", 401);

// Phone number pattern detector
function containsPhone($text) {
    return preg_match('/(\+?234|0)[789][01]\d{8}/', $text) ||
           preg_match('/\b0[789]\d{9}\b/', $text) ||
           preg_match('/\b\d{11}\b/', $text);
}

switch ($action) {

    case "send": {
        $bookingId  = (int)($input["booking_id"]  ?? 0);
        $text       = trim($input["message_text"] ?? "");
        if (!$bookingId || !$text) respondError("booking_id and message_text required");

        // Resolve receiver from the booking — never trust the client to pass the right user id
        $bs = $pdo->prepare("SELECT b.customer_id, d.user_id as driver_user_id
            FROM bookings b
            LEFT JOIN drivers d ON b.driver_id = d.id
            WHERE b.id = ?");
        $bs->execute([$bookingId]);
        $booking = $bs->fetch();
        if (!$booking) respondError("Booking not found", 404);

        // Receiver is whoever is NOT the sender
        if ($user["id"] == $booking["customer_id"]) {
            $receiverId = (int)$booking["driver_user_id"];
        } else {
            $receiverId = (int)$booking["customer_id"];
        }
        if (!$receiverId) respondError("Cannot determine message recipient — driver may not be assigned yet");

        // Detect phone numbers
        $flagged = containsPhone($text) ? 1 : 0;

        $pdo->prepare("INSERT INTO messages (booking_id,sender_id,receiver_id,message_text,is_flagged) VALUES (?,?,?,?,?)")
            ->execute([$bookingId,$user["id"],$receiverId,$text,$flagged]);

        $msgId = $pdo->lastInsertId();

        // If flagged — notify admin
        if ($flagged) {
            $pdo->prepare("INSERT INTO notifications (user_id,title,body,type)
                SELECT id,'⚠️ Flagged Message','A message may contain a phone number. Check the chat monitor.','flag'
                FROM users WHERE role='admin' LIMIT 1")->execute();
        }

        respond(["success"=>true,"id"=>$msgId,"flagged"=>$flagged]);
    }

    case "get": {
        $bookingId = (int)($_GET["booking_id"] ?? 0);
        if (!$bookingId) respondError("booking_id required");

        // Verify user is part of this booking
        $check = $pdo->prepare("SELECT b.customer_id, d.user_id as driver_user_id FROM bookings b LEFT JOIN drivers d ON b.driver_id=d.id WHERE b.id=?");
        $check->execute([$bookingId]);
        $b = $check->fetch();
        if (!$b) respondError("Booking not found");
        if ($user["role"] !== "admin" && $user["id"] != $b["customer_id"] && $user["id"] != $b["driver_user_id"]) {
            respondError("Access denied", 403);
        }

        $s = $pdo->prepare("SELECT m.*,u.name as sender_name,u.photo_url as sender_photo
            FROM messages m JOIN users u ON m.sender_id=u.id
            WHERE m.booking_id=? ORDER BY m.created_at ASC");
        $s->execute([$bookingId]);

        // Mark as read
        $pdo->prepare("UPDATE messages SET is_read=1 WHERE booking_id=? AND receiver_id=?")->execute([$bookingId,$user["id"]]);

        respond(["messages" => $s->fetchAll()]);
    }

    // ── Admin: get all conversations ──────────────────────────────────────────
    case "admin_get_all": {
        if ($user["role"] !== "admin") respondError("Admin access required", 403);
        $flaggedOnly = $_GET["flagged"] ?? "";

        $sql = "SELECT DISTINCT m.booking_id,
            cu.name as customer_name, du.name as driver_name,
            c.name as car_name,
            (SELECT message_text FROM messages WHERE booking_id=m.booking_id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM messages WHERE booking_id=m.booking_id ORDER BY created_at DESC LIMIT 1) as last_at,
            (SELECT COUNT(*) FROM messages WHERE booking_id=m.booking_id AND is_flagged=1) as flag_count
            FROM messages m
            JOIN bookings b ON m.booking_id=b.id
            JOIN users cu ON b.customer_id=cu.id
            JOIN cars c ON b.car_id=c.id
            LEFT JOIN drivers d ON b.driver_id=d.id
            LEFT JOIN users du ON d.user_id=du.id";
        if ($flaggedOnly) $sql .= " WHERE m.is_flagged=1";
        $sql .= " ORDER BY last_at DESC";

        $s = $pdo->query($sql);
        respond(["conversations" => $s->fetchAll()]);
    }

    // ── Unread count ──────────────────────────────────────────────────────────
    case "unread_count": {
        $s = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0");
        $s->execute([$user["id"]]);
        respond(["count" => (int)$s->fetchColumn()]);
    }

    default: respondError("Invalid action");
}
?>

<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);
if (!$user) respondError("Unauthorised", 401);

switch ($action) {
    case "get_all": {
        $s = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
        $s->execute([$user["id"]]);
        respond(["notifications" => $s->fetchAll()]);
    }
    case "get_unread_count": {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$user["id"]]);
        respond(["count" => (int)$s->fetchColumn()]);
    }
    case "mark_read": {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user["id"]]);
        respond(["success" => true]);
    }
    default: respondError("Invalid action");
}
?>

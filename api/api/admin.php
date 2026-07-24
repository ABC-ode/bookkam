<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);
if ($user && $user["role"] !== "admin") respondError("Admin access required", 403);

switch ($action) {

    case "get_stats": {
        $stats["total_customers"]  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
        $stats["total_drivers"]    = $pdo->query("SELECT COUNT(*) FROM drivers WHERE status='active'")->fetchColumn();
        $stats["total_bookings"]   = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
        $stats["today_bookings"]   = $pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $stats["active_trips"]     = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='active'")->fetchColumn();
        $stats["total_revenue"]    = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success'")->fetchColumn();
        $stats["today_revenue"]    = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success' AND DATE(created_at)=CURDATE()")->fetchColumn();
        $stats["pending_drivers"]  = $pdo->query("SELECT COUNT(*) FROM drivers WHERE status='pending'")->fetchColumn();
        $stats["pending_media"]    = $pdo->query("SELECT COUNT(*) FROM car_media WHERE status='pending'")->fetchColumn();
        $stats["total_cars"]       = $pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
        $stats["self_drive_cars"]  = $pdo->query("SELECT COUNT(*) FROM cars WHERE car_type='self_drive'")->fetchColumn();
        $stats["chauffeur_cars"]   = $pdo->query("SELECT COUNT(*) FROM cars WHERE car_type='chauffeur'")->fetchColumn();
        $stats["flagged_messages"] = $pdo->query("SELECT COUNT(*) FROM messages WHERE is_flagged=1 AND is_read=0")->fetchColumn();
        respond(["stats"=>$stats]);
    }

    case "get_home_booking_card": {
        if (!$user) respondError("Unauthorised", 401);
        $s = $pdo->query("SELECT * FROM home_booking_cards ORDER BY sort_order ASC, id ASC LIMIT 1");
        respond(["card"=>$s->fetch()]);
    }

    case "get_cloudinary_upload_config": {
        if (!$user) respondError("Unauthorised", 401);
        if (!CLOUDINARY_CLOUD || !CLOUDINARY_UPLOAD_PRESET) {
            respondError("Cloudinary is not configured", 500);
        }
        respond([
            "cloud_name" => CLOUDINARY_CLOUD,
            "upload_preset" => CLOUDINARY_UPLOAD_PRESET,
        ]);
    }

    case "update_home_booking_card": {
        if (!$user) respondError("Unauthorised", 401);
        $id       = (int)($input["id"] ?? 1);
        $eventKey = trim($input["event_key"] ?? "grovveyard");
        $modalId  = trim($input["modal_id"] ?? "gyOverlay-grovveyard");
        $title    = trim($input["title"] ?? "");
        $subtitle = trim($input["subtitle"] ?? "");
        $eyebrow  = trim($input["eyebrow"] ?? "");
        $eventDate = trim($input["event_date"] ?? "");
        $imageUrl = trim($input["image_url"] ?? "");
        $isActive = !empty($input["is_active"]) ? 1 : 0;

        if (!$title || !$subtitle) respondError("Title and subtitle are required");
        if (!preg_match('/^[a-z0-9_-]+$/i', $eventKey)) respondError("Event key can only contain letters, numbers, dashes, and underscores");
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $modalId)) respondError("Modal ID can only contain letters, numbers, dashes, and underscores");
        if ($eventDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) respondError("Event date must be YYYY-MM-DD");

        $pdo->prepare("INSERT INTO home_booking_cards
            (id,event_key,modal_id,title,subtitle,eyebrow,event_date,image_url,is_active,sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,0)
            ON DUPLICATE KEY UPDATE
                event_key=VALUES(event_key),
                modal_id=VALUES(modal_id),
                title=VALUES(title),
                subtitle=VALUES(subtitle),
                eyebrow=VALUES(eyebrow),
                event_date=VALUES(event_date),
                image_url=VALUES(image_url),
                is_active=VALUES(is_active)")
            ->execute([$id,$eventKey,$modalId,$title,$subtitle,$eyebrow,$eventDate ?: null,$imageUrl,$isActive]);

        respond(["success"=>true]);
    }

    case "get_drivers": {
        $status = $_GET["status"] ?? "";
        $sql = "SELECT d.*,u.name,u.phone,u.city,u.created_at as joined,
            (SELECT COUNT(*) FROM cars WHERE driver_id=d.id) as car_count
            FROM drivers d JOIN users u ON d.user_id=u.id";
        $params = [];
        if ($status) { $sql .= " WHERE d.status=?"; $params[] = $status; }
        $sql .= " ORDER BY d.id DESC";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond(["drivers"=>$s->fetchAll()]);
    }

    case "update_driver_status": {
        if (!$user) respondError("Unauthorised", 401);
        $driverId = (int)($input["driver_id"] ?? 0);
        $status   = $input["status"] ?? "";
        if (!in_array($status,["pending","active","suspended"])) respondError("Invalid status");
        $pdo->prepare("UPDATE drivers SET status=? WHERE id=?")->execute([$status,$driverId]);
        $ns = $pdo->prepare("SELECT u.id,u.phone FROM drivers d JOIN users u ON d.user_id=u.id WHERE d.id=?");
        $ns->execute([$driverId]);
        $d = $ns->fetch();
        if ($d) {
            $msg = match($status) {
                "active"    => "Your BOOKKAM driver account has been approved! You can now go online and accept trips.",
                "suspended" => "Your BOOKKAM driver account has been suspended. Please contact support.",
                default     => "Your BOOKKAM account status has been updated.",
            };
            $pdo->prepare("INSERT INTO notifications (user_id,title,body,type) VALUES (?,?,?,'account')")
                ->execute([$d["id"],"Account Update",$msg]);
            sendWhatsApp($d["phone"], "🚗 *BOOKKAM*\n\n$msg");
        }
        respond(["success"=>true]);
    }

    case "get_media_pending": {
        $s = $pdo->query("SELECT cm.*,c.name as car_name,c.plate_number,c.car_type,
            COALESCE(u.name,'Admin') as driver_name
            FROM car_media cm JOIN cars c ON cm.car_id=c.id
            LEFT JOIN drivers d ON c.driver_id=d.id LEFT JOIN users u ON d.user_id=u.id
            WHERE cm.status='pending' ORDER BY cm.created_at DESC");
        respond(["media"=>$s->fetchAll()]);
    }

    case "review_media": {
        if (!$user) respondError("Unauthorised", 401);
        $id     = (int)($input["media_id"] ?? 0);
        $status = $input["status"] ?? "";
        $reason = $input["reason"] ?? "";
        if (!in_array($status,["approved","rejected"])) respondError("Invalid status");
        $pdo->prepare("UPDATE car_media SET status=?,rejection_reason=? WHERE id=?")->execute([$status,$reason,$id]);
        respond(["success"=>true]);
    }

    case "get_bookings": {
        $status  = $_GET["status"]   ?? "";
        $carType = $_GET["car_type"] ?? "";
        $sql = "SELECT b.*,c.name as car_name,c.plate_number,c.car_type,
            cu.name as customer_name,cu.phone as customer_phone,
            COALESCE(du.name,'No Driver') as driver_name
            FROM bookings b JOIN cars c ON b.car_id=c.id
            JOIN users cu ON b.customer_id=cu.id
            LEFT JOIN drivers d ON b.driver_id=d.id LEFT JOIN users du ON d.user_id=du.id";
        $params = []; $wheres = [];
        if ($status)  { $wheres[] = "b.status=?";   $params[] = $status; }
        if ($carType) { $wheres[] = "b.car_type=?";  $params[] = $carType; }
        if ($wheres)  $sql .= " WHERE ".implode(" AND ",$wheres);
        $sql .= " ORDER BY b.created_at DESC LIMIT 100";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond(["bookings"=>$s->fetchAll()]);
    }

    case "get_event_bookings": {
        if (!$user) respondError("Unauthorised", 401);
        $status = $_GET["status"] ?? "";
        $sql = "SELECT eb.*, u.name AS confirmed_by_name
            FROM event_bookings eb
            LEFT JOIN users u ON eb.confirmed_by=u.id";
        $params = [];
        if ($status) {
            $sql .= " WHERE eb.status=?";
            $params[] = $status;
        }
        $sql .= " ORDER BY eb.created_at DESC LIMIT 100";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond(["bookings"=>$s->fetchAll()]);
    }

    case "confirm_event_booking": {
        if (!$user) respondError("Unauthorised", 401);
        $bookingId = (int)($input["booking_id"] ?? 0);
        $status = $input["status"] ?? "confirmed";
        if (!$bookingId) respondError("booking_id required");
        if (!in_array($status, ["confirmed","cancelled"])) respondError("Invalid status");
        $pdo->prepare("UPDATE event_bookings SET status=?, confirmed_by=?, confirmed_at=NOW() WHERE id=?")
            ->execute([$status,$user["id"],$bookingId]);
        respond(["success"=>true]);
    }

    case "set_car_availability": {
        if (!$user) respondError("Unauthorised", 401);
        $carId  = (int)($input["car_id"] ?? 0);
        $status = $input["status"] ?? "";
        $return = $input["expected_return"] ?? null;
        if (!$carId || !in_array($status,["available","in_use"])) respondError("car_id and valid status required");
        $pdo->prepare("UPDATE cars SET availability_status=?,expected_return_at=?,is_available=? WHERE id=?")
            ->execute([$status,$return,$status==="available"?1:0,$carId]);
        respond(["success"=>true]);
    }

    case "add_damage_report": {
        if (!$user) respondError("Unauthorised", 401);
        $bookingId   = (int)($input["booking_id"]   ?? 0);
        $description = $input["description"]         ?? "";
        $cost        = (float)($input["repair_cost"] ?? 0);
        if (!$bookingId || !$description) respondError("booking_id and description required");
        $pdo->prepare("INSERT INTO damage_reports (booking_id,description,repair_cost,reported_by,created_at) VALUES (?,?,?,?,NOW())")
            ->execute([$bookingId,$description,$cost,$user["id"]]);
        // Deduct from deposit if applicable
        if ($cost > 0) {
            $bs = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
            $bs->execute([$bookingId]);
            $b = $bs->fetch();
            if ($b && $b["security_deposit"] > 0) {
                $refund = max(0, $b["security_deposit"] - $cost);
                if ($refund > 0) {
                    $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?")->execute([$refund,$b["customer_id"]]);
                }
                $pdo->prepare("UPDATE bookings SET deposit_released=1,deposit_refund_amount=? WHERE id=?")->execute([$refund,$bookingId]);
            }
        }
        respond(["success"=>true,"id"=>$pdo->lastInsertId()]);
    }

    case "toggle_surge": {
        if (!$user) respondError("Unauthorised", 401);
        $carId      = (int)($input["car_id"]         ?? 0);
        $multiplier = (float)($input["multiplier"]   ?? 1.0);
        if (!$carId) respondError("car_id required");
        $pdo->prepare("UPDATE cars SET surge_multiplier=? WHERE id=?")->execute([$multiplier,$carId]);
        respond(["success"=>true]);
    }

    case "blacklist_customer": {
        if (!$user) respondError("Unauthorised", 401);
        $customerId = (int)($input["customer_id"] ?? 0);
        $reason     = $input["reason"] ?? "";
        if (!$customerId) respondError("customer_id required");
        $pdo->prepare("UPDATE users SET is_blacklisted=1,blacklist_reason=? WHERE id=?")->execute([$reason,$customerId]);
        respond(["success"=>true]);
    }

    case "get_customers": {
        $s = $pdo->query("SELECT u.*,(SELECT COUNT(*) FROM bookings WHERE customer_id=u.id) as booking_count
            FROM users u WHERE u.role='customer' ORDER BY u.created_at DESC");
        respond(["customers"=>$s->fetchAll()]);
    }

    case "get_revenue": {
        $period = $_GET["period"] ?? "month";
        $filter = $period === "week" ? "INTERVAL 7 DAY" : "INTERVAL 30 DAY";
        $daily  = $pdo->query("SELECT DATE(created_at) as date,SUM(amount) as total
            FROM payments WHERE status='success' AND created_at>=DATE_SUB(NOW(),$filter)
            GROUP BY DATE(created_at) ORDER BY date")->fetchAll();
        $byMethod = $pdo->query("SELECT method,SUM(amount) as total,COUNT(*) as count
            FROM payments WHERE status='success' GROUP BY method")->fetchAll();
        respond(["daily"=>$daily,"by_method"=>$byMethod]);
    }

    case "get_payouts": {
        $s = $pdo->query("SELECT dp.*,u.name,u.phone,d.bank_name,d.account_number,d.account_name
            FROM driver_payouts dp JOIN drivers d ON dp.driver_id=d.id JOIN users u ON d.user_id=u.id
            ORDER BY dp.id DESC");
        respond(["payouts"=>$s->fetchAll()]);
    }

    case "pay_payout": {
        if (!$user) respondError("Unauthorised", 401);
        $id = (int)($input["payout_id"] ?? 0);
        $pdo->prepare("UPDATE driver_payouts SET status='paid',paid_at=NOW() WHERE id=?")->execute([$id]);
        respond(["success"=>true]);
    }

    case "get_cities": {
        respond(["cities"=>$pdo->query("SELECT * FROM cities ORDER BY name")->fetchAll()]);
    }

    case "add_city": {
        if (!$user) respondError("Unauthorised", 401);
        $name=$input["name"]??""; $state=$input["state"]??"";
        if (!$name||!$state) respondError("name and state required");
        $pdo->prepare("INSERT INTO cities (name,state,is_active) VALUES (?,?,1)")->execute([$name,$state]);
        respond(["success"=>true,"id"=>$pdo->lastInsertId()]);
    }

    case "update_pricing": {
        if (!$user) respondError("Unauthorised", 401);
        $id=$input["id"]??0;
        $fields=["base_price","per_km_price","per_hour_price","per_day_price"];
        $sets=[]; $vals=[];
        foreach($fields as $f){if(isset($input[$f])){$sets[]="$f=?";$vals[]=$input[$f];}}
        if(!$sets) respondError("Nothing to update");
        $vals[]=$id;
        $pdo->prepare("UPDATE pricing SET ".implode(",",$sets)." WHERE id=?")->execute($vals);
        respond(["success"=>true]);
    }

    case "manage_promo": {
        if (!$user) respondError("Unauthorised", 401);
        $pdo->prepare("INSERT INTO promo_codes (code,discount_percent,max_uses,expiry_date,city_restriction,is_active) VALUES (?,?,?,?,?,1)")
            ->execute([$input["code"],$input["discount_percent"],$input["max_uses"],$input["expiry_date"],$input["city_restriction"]??null]);
        respond(["success"=>true]);
    }

    case "get_promos": {
        respond(["promos"=>$pdo->query("SELECT * FROM promo_codes ORDER BY id DESC")->fetchAll()]);
    }

    case "get_chat_monitor": {
        // Get all conversations for admin monitoring
        $flaggedOnly = $_GET["flagged"] ?? "";
        $sql = "SELECT DISTINCT m.booking_id,
            cu.name as customer_name, COALESCE(du.name,'N/A') as driver_name,
            c.name as car_name, c.car_type,
            (SELECT message_text FROM messages WHERE booking_id=m.booking_id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM messages WHERE booking_id=m.booking_id ORDER BY created_at DESC LIMIT 1) as last_at,
            (SELECT COUNT(*) FROM messages WHERE booking_id=m.booking_id AND is_flagged=1) as flag_count,
            (SELECT COUNT(*) FROM messages WHERE booking_id=m.booking_id) as msg_count
            FROM messages m
            JOIN bookings b ON m.booking_id=b.id
            JOIN users cu ON b.customer_id=cu.id
            JOIN cars c ON b.car_id=c.id
            LEFT JOIN drivers d ON b.driver_id=d.id
            LEFT JOIN users du ON d.user_id=du.id";
        if ($flaggedOnly) $sql .= " WHERE (SELECT COUNT(*) FROM messages WHERE booking_id=m.booking_id AND is_flagged=1)>0";
        $sql .= " ORDER BY last_at DESC LIMIT 50";
        respond(["conversations"=>$pdo->query($sql)->fetchAll()]);
    }

    default: respondError("Invalid action");
}
?>

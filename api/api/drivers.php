<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

switch ($action) {

    case "get_my_status": {
        if (!$user) respondError("Unauthorised", 401);
        $s = $pdo->prepare("SELECT d.*,c.id as car_id,c.name as car_name,c.make,c.model,c.year,c.color,c.plate_number,c.transmission,c.fuel_type,c.seats,c.category
            FROM drivers d LEFT JOIN cars c ON c.driver_id=d.id WHERE d.user_id=?");
        $s->execute([$user["id"]]);
        $driver = $s->fetch();
        if (!$driver) respond(["status"=>null]);
        respond(["status"=>$driver["status"],"driver"=>$driver,"driver_car"=>$driver]);
    }

    case "toggle_online": {
        if (!$user) respondError("Unauthorised", 401);
        $online = (int)($input["is_online"] ?? 0);
        $pdo->prepare("UPDATE drivers SET is_online=? WHERE user_id=?")->execute([$online,$user["id"]]);
        respond(["success"=>true,"is_online"=>$online]);
    }

    case "get_earnings": {
        if (!$user) respondError("Unauthorised", 401);
        $period = $_GET["period"] ?? "today";
        $ds = $pdo->prepare("SELECT id FROM drivers WHERE user_id=?");
        $ds->execute([$user["id"]]);
        $driver = $ds->fetch();
        if (!$driver) respondError("Driver not found");
        $dateFilter = match($period) {
            "week"  => "AND b.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            "month" => "AND b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "AND DATE(b.created_at) = CURDATE()",
        };
        $s = $pdo->prepare("SELECT COALESCE(SUM(b.total_price),0) as total,
            COALESCE(SUM(b.commission_amount),0) as commission,
            COUNT(*) as trips
            FROM bookings b WHERE b.driver_id=? AND b.status='completed' $dateFilter");
        $s->execute([$driver["id"]]);
        $earnings = $s->fetch();
        $earnings["net"] = $earnings["total"] - $earnings["commission"];
        respond(["earnings"=>$earnings]);
    }

    case "update_bank": {
        if (!$user) respondError("Unauthorised", 401);
        $bank    = $input["bank_name"]      ?? "";
        $acctNum = $input["account_number"] ?? "";
        $acctNm  = $input["account_name"]   ?? "";
        $pdo->prepare("UPDATE drivers SET bank_name=?,account_number=?,account_name=? WHERE user_id=?")
            ->execute([$bank,$acctNum,$acctNm,$user["id"]]);
        respond(["success"=>true]);
    }

    case "update_location": {
        if (!$user) respondError("Unauthorised", 401);
        $lat       = (float)($input["lat"]        ?? 0);
        $lng       = (float)($input["lng"]        ?? 0);
        $heading   = (float)($input["heading"]    ?? 0);
        $bookingId = (int)($input["booking_id"]   ?? 0);
        if (!$lat || !$lng) respondError("lat and lng required");
        $ds = $pdo->prepare("SELECT id FROM drivers WHERE user_id=?");
        $ds->execute([$user["id"]]);
        $driver = $ds->fetch();
        if (!$driver) respondError("Driver not found");
        $check = $pdo->prepare("SELECT id FROM driver_locations WHERE driver_id=?");
        $check->execute([$driver["id"]]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE driver_locations SET lat=?,lng=?,heading=?,booking_id=?,updated_at=NOW() WHERE driver_id=?")
                ->execute([$lat,$lng,$heading,$bookingId,$driver["id"]]);
        } else {
            $pdo->prepare("INSERT INTO driver_locations (driver_id,lat,lng,heading,booking_id) VALUES (?,?,?,?,?)")
                ->execute([$driver["id"],$lat,$lng,$heading,$bookingId]);
        }
        respond(["success"=>true]);
    }

    case "add_unavailable_date": {
        if (!$user) respondError("Unauthorised", 401);
        $date   = $input["date"]   ?? "";
        $reason = $input["reason"] ?? "";
        if (!$date) respondError("date required");
        $ds = $pdo->prepare("SELECT id FROM drivers WHERE user_id=?");
        $ds->execute([$user["id"]]);
        $driver = $ds->fetch();
        if (!$driver) respondError("Driver not found");
        $pdo->prepare("INSERT INTO driver_unavailability (driver_id,unavailable_date,reason) VALUES (?,?,?)")
            ->execute([$driver["id"],$date,$reason]);
        respond(["success"=>true]);
    }

    case "request_payout": {
        if (!$user) respondError("Unauthorised", 401);
        $ds = $pdo->prepare("SELECT id FROM drivers WHERE user_id=?");
        $ds->execute([$user["id"]]);
        $driver = $ds->fetch();
        if (!$driver) respondError("Driver not found");
        $amount = (float)($input["amount"] ?? 0);
        if ($amount <= 0) respondError("Invalid amount");
        $pdo->prepare("INSERT INTO driver_payouts (driver_id,amount,period_start,period_end,status) VALUES (?,?,DATE_SUB(NOW(),INTERVAL 7 DAY),NOW(),'pending')")
            ->execute([$driver["id"],$amount]);
        respond(["success"=>true,"message"=>"Payout request submitted"]);
    }

    default: respondError("Invalid action");
}
?>

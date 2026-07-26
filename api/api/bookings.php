<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

switch ($action) {

    case "create": {
        if (!$user) respondError("Unauthorised", 401);
        $carId    = (int)($input["car_id"]           ?? 0);
        $type     = $input["booking_type"]            ?? ""; // hourly|daily|trip
        $pickup   = $input["pickup_address"]          ?? "";
        $dropoff  = $input["dropoff_address"]         ?? "";
        $duration = (float)($input["duration_hours"]  ?? 1);
        $days     = (int)($input["duration_days"]     ?? 1);
        $city     = $input["city"]                    ?? "Calabar";
        $pickupCoords  = $input["pickup_coords"]  ?? null;
        $dropoffCoords = $input["dropoff_coords"] ?? null;
        if (!$carId || !$type) respondError("car_id and booking_type required");
        if (!$pickup) respondError("Pickup address required");

        // Get car
        $cs = $pdo->prepare("SELECT c.*, d.id as did FROM cars c LEFT JOIN drivers d ON c.driver_id=d.id WHERE c.id=?");
        $cs->execute([$carId]);
        $car = $cs->fetch();
        if (!$car) respondError("Car not found");

        // Check availability
        if ($car["car_type"] === "self_drive") {
            if ($car["availability_status"] !== "available") respondError("Car is currently in use");
        } else {
            if (!$car["is_available"]) respondError("Car is not available");
            if (!$car["did"]) respondError("No driver assigned to this car");
        }

        // Calculate price
        $total = 0;
        if ($type === "hourly") {
            $ps = $pdo->prepare("SELECT per_hour_price FROM pricing WHERE city=? AND booking_type='hourly' LIMIT 1");
            $ps->execute([$city]);
            $price = $ps->fetch();
            $total = $price ? ($price["per_hour_price"] * $duration) : ($duration * 5000);
        } elseif ($type === "daily") {
            $ps = $pdo->prepare("SELECT per_day_price FROM pricing WHERE city=? AND booking_type='daily' LIMIT 1");
            $ps->execute([$city]);
            $price = $ps->fetch();
            $total = $price ? ($price["per_day_price"] * $days) : ($days * 30000);
        } else { // trip
            $ps = $pdo->prepare("SELECT base_price FROM pricing WHERE city=? AND booking_type='chauffeur_trip' LIMIT 1");
            $ps->execute([$city]);
            $price = $ps->fetch();
            $total = $price ? $price["base_price"] : 15000;
        }

        // Apply surge pricing
        if ($car["surge_multiplier"] && $car["surge_multiplier"] > 1) {
            $total = $total * $car["surge_multiplier"];
        }

        // Add security deposit for self-drive
        $deposit = ($car["car_type"] === "self_drive") ? (float)($car["security_deposit"] ?? 0) : 0;
        $totalWithDeposit = $total + $deposit;
        $commission = round($total * PLATFORM_COMMISSION, 2);

        $driverId = $car["did"] ?? null;

        $pdo->prepare("INSERT INTO bookings (customer_id,driver_id,car_id,booking_type,car_type,status,
            pickup_address,dropoff_address,pickup_coords,dropoff_coords,
            duration_hours,duration_days,total_price,security_deposit,
            commission_amount,city,start_time)
            VALUES (?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$user["id"],$driverId,$carId,$type,$car["car_type"],
                $pickup,$dropoff,$pickupCoords,$dropoffCoords,
                $duration,$days,$total,$deposit,$commission,$city]);

        $bookingId = $pdo->lastInsertId();

        // Mark car unavailable
        if ($car["car_type"] === "self_drive") {
            $pdo->prepare("UPDATE cars SET availability_status='in_use',is_available=0 WHERE id=?")->execute([$carId]);
        } else {
            $pdo->prepare("UPDATE cars SET is_available=0 WHERE id=?")->execute([$carId]);
        }

        // Notify driver (chauffeur only)
        if ($driverId) {
            $nu = $pdo->prepare("SELECT u.id,u.phone,u.name FROM drivers d JOIN users u ON d.user_id=u.id WHERE d.id=?");
            $nu->execute([$driverId]);
            $driverUser = $nu->fetch();
            if ($driverUser) {
                $pdo->prepare("INSERT INTO notifications (user_id,title,body,type) VALUES (?,'New Booking Request','You have a new trip request. Please respond quickly.','booking')")
                    ->execute([$driverUser["id"]]);
                // WhatsApp notification to driver
                sendWhatsApp($driverUser["phone"], "🚗 *BOOKKAM - New Booking!*\n\nCustomer: {$user['name']}\nPickup: $pickup\nType: ".ucfirst($type)."\n\nOpen the app to accept.");
            }
        }

        // WhatsApp confirmation to customer
        sendWhatsApp($user["phone"], "✅ *BOOKKAM - Booking Confirmed!*\n\nBooking ID: #$bookingId\nCar: {$car['name']}\nPickup: $pickup\nType: ".ucfirst($type)."\nTotal: ₦".number_format($total).($deposit ? "\nDeposit: ₦".number_format($deposit) : "")."\n\nThank you for choosing BOOKKAM!");

        respond(["booking_id"=>$bookingId,"total"=>$total,"deposit"=>$deposit,"total_with_deposit"=>$totalWithDeposit,"message"=>"Booking created"]);
    }

    case "get_my_bookings": {
        if (!$user) respondError("Unauthorised", 401);
        $status  = $_GET["status"]   ?? "";
        $carType = $_GET["car_type"] ?? "";
        $sql = "SELECT b.*,c.name as car_name,c.category,c.plate_number,c.car_type,c.make,c.model,c.year,
            u.name as driver_name,u.phone as driver_phone,u.id as driver_user_id,
            (SELECT url FROM car_media WHERE car_id=c.id AND status='approved' ORDER BY sort_order LIMIT 1) as car_photo
            FROM bookings b
            JOIN cars c ON b.car_id=c.id
            LEFT JOIN drivers d ON b.driver_id=d.id
            LEFT JOIN users u ON d.user_id=u.id
            WHERE b.customer_id=?";
        $params = [$user["id"]];
        if ($status)  { $sql .= " AND b.status=?";   $params[] = $status; }
        if ($carType) { $sql .= " AND b.car_type=?"; $params[] = $carType; }
        $sql .= " ORDER BY b.created_at DESC";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond(["bookings" => $s->fetchAll()]);
    }

    case "get_one": {
        if (!$user) respondError("Unauthorised", 401);
        $id = (int)($_GET["id"] ?? 0);
        $s  = $pdo->prepare("SELECT b.*,c.name as car_name,c.category,c.color,c.plate_number,c.car_type,c.make,c.model,c.year,
            u.name as driver_name,u.phone as driver_phone,u.photo_url as driver_photo,
            d.rating as driver_rating,d.total_trips
            FROM bookings b JOIN cars c ON b.car_id=c.id
            LEFT JOIN drivers d ON b.driver_id=d.id
            LEFT JOIN users u ON d.user_id=u.id WHERE b.id=?");
        $s->execute([$id]);
        $booking = $s->fetch();
        if (!$booking) respondError("Booking not found", 404);
        respond(["booking" => $booking]);
    }

    case "update_status": {
        if (!$user) respondError("Unauthorised", 401);
        $id     = (int)($input["booking_id"] ?? 0);
        $status = $input["status"] ?? "";
        if (!$id || !$status) respondError("booking_id and status required");
        if (!in_array($status,["pending","confirmed","active","completed","cancelled"])) respondError("Invalid status");

        if ($status === "active") {
            $pdo->prepare("UPDATE bookings SET status=?, start_time=NOW() WHERE id=?")->execute([$status,$id]);
        } elseif ($status === "completed") {
            $pdo->prepare("UPDATE bookings SET status=?, end_time=NOW() WHERE id=?")->execute([$status,$id]);
        } else {
            $pdo->prepare("UPDATE bookings SET status=? WHERE id=?")->execute([$status,$id]);
        }

        if (in_array($status,["completed","cancelled"])) {
            $cs = $pdo->prepare("SELECT car_id,car_type FROM bookings WHERE id=?");
            $cs->execute([$id]);
            $b = $cs->fetch();
            if ($b) {
                if ($b["car_type"] === "self_drive") {
                    $pdo->prepare("UPDATE cars SET availability_status='available',is_available=1 WHERE id=?")->execute([$b["car_id"]]);
                } else {
                    $pdo->prepare("UPDATE cars SET is_available=1 WHERE id=?")->execute([$b["car_id"]]);
                }
            }
        }

        // WhatsApp status update to customer
        $bs = $pdo->prepare("SELECT b.*,u.phone,u.name,c.name as car_name FROM bookings b JOIN users u ON b.customer_id=u.id JOIN cars c ON b.car_id=c.id WHERE b.id=?");
        $bs->execute([$id]);
        $booking = $bs->fetch();
        if ($booking) {
            $msgs = [
                "confirmed"  => "✅ Your booking #{$id} for {$booking['car_name']} has been confirmed! Your driver is on the way.",
                "active"     => "🚗 Your trip has started! Booking #{$id}.",
                "completed"  => "🎉 Trip completed! Booking #{$id}. Thank you for choosing BOOKKAM!",
                "cancelled"  => "❌ Booking #{$id} has been cancelled. Contact support if this was unexpected.",
            ];
            if (isset($msgs[$status])) {
                sendWhatsApp($booking["phone"], "*BOOKKAM Update*\n\n".$msgs[$status]);
            }
        }

        respond(["success" => true]);
    }

    case "cancel": {
        if (!$user) respondError("Unauthorised", 401);
        $id = (int)($input["booking_id"] ?? 0);
        $s  = $pdo->prepare("SELECT * FROM bookings WHERE id=? AND customer_id=?");
        $s->execute([$id,$user["id"]]);
        $b = $s->fetch();
        if (!$b) respondError("Booking not found");
        if (!in_array($b["status"],["pending","confirmed"])) respondError("Cannot cancel at this stage");
        $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$id]);
        if ($b["car_type"] === "self_drive") {
            $pdo->prepare("UPDATE cars SET availability_status='available',is_available=1 WHERE id=?")->execute([$b["car_id"]]);
        } else {
            $pdo->prepare("UPDATE cars SET is_available=1 WHERE id=?")->execute([$b["car_id"]]);
        }
        respond(["success" => true]);
    }

    case "get_driver_bookings": {
        if (!$user) respondError("Unauthorised", 401);
        $ds = $pdo->prepare("SELECT id FROM drivers WHERE user_id=?");
        $ds->execute([$user["id"]]);
        $driver = $ds->fetch();
        if (!$driver) respondError("Driver not found");
        $s = $pdo->prepare("SELECT b.*,c.name as car_name,cu.name as customer_name,cu.phone as customer_phone
            FROM bookings b JOIN cars c ON b.car_id=c.id JOIN users cu ON b.customer_id=cu.id
            WHERE b.driver_id=? ORDER BY b.created_at DESC LIMIT 50");
        $s->execute([$driver["id"]]);
        respond(["bookings" => $s->fetchAll()]);
    }

    case "release_deposit": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $id = (int)($input["booking_id"] ?? 0);
        $bs = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
        $bs->execute([$id]);
        $b = $bs->fetch();
        if (!$b) respondError("Booking not found");
        if ($b["security_deposit"] > 0) {
            $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?")->execute([$b["security_deposit"],$b["customer_id"]]);
            $pdo->prepare("UPDATE bookings SET deposit_released=1,deposit_refund_amount=? WHERE id=?")->execute([$b["security_deposit"],$id]);
            // Fetch customer phone and send notification
            $cu = $pdo->prepare("SELECT phone,name FROM users WHERE id=?");
            $cu->execute([$b["customer_id"]]);
            $customer = $cu->fetch();
            if ($customer) {
                sendWhatsApp($customer["phone"], "* BOOKKAM - Security Deposit Returned*\n\nHi {$customer['name']}, your security deposit of #".number_format($b["security_deposit"])." for Booking #$id has been released to your wallet.");
            }
        }
        respond(["success"=>true]);
    }

    case "get_driver_location": {
        if (!$user) respondError("Unauthorised", 401);
        $bookingId = (int)($_GET["booking_id"] ?? 0);
        if (!$bookingId) respondError("booking_id required");
        $bs = $pdo->prepare("SELECT b.driver_id FROM bookings b WHERE b.id=? AND b.customer_id=?");
        $bs->execute([$bookingId,$user["id"]]);
        $booking = $bs->fetch();
        if (!$booking) respondError("Booking not found", 404);
        $ls = $pdo->prepare("SELECT lat,lng,heading,updated_at FROM driver_locations WHERE driver_id=?");
        $ls->execute([$booking["driver_id"]]);
        $location = $ls->fetch();
        respond(["location" => $location ?: null]);
    }

    default: respondError("Invalid action");
}
?>

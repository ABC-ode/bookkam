<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

switch ($action) {

    // ── Get all cars (chauffeur or self-drive) ────────────────────────────────
    case "get_all": {
        $city      = $_GET["city"]      ?? "Calabar";
        $type      = $_GET["type"]      ?? "chauffeur"; // chauffeur | self_drive
        $available = $_GET["available"] ?? "";

        if ($type === "self_drive") {
            // Self-drive: admin-owned cars, no driver needed
            $sql = "SELECT c.*,
                (SELECT url FROM car_media WHERE car_id=c.id AND status='approved' AND media_type='photo' ORDER BY sort_order LIMIT 1) as main_photo
                FROM cars c
                WHERE c.city=? AND c.car_type='self_drive'";
            $params = [$city];
            if ($available) { $sql .= " AND c.availability_status='available'"; }
        } else {
            // Chauffeur: only online drivers' cars
            $sql = "SELECT c.*, d.rating as driver_rating, d.total_trips, d.is_online,
                u.name as driver_name,
                (SELECT url FROM car_media WHERE car_id=c.id AND status='approved' AND media_type='photo' ORDER BY sort_order LIMIT 1) as main_photo
                FROM cars c
                JOIN drivers d ON c.driver_id=d.id
                JOIN users u ON d.user_id=u.id
                WHERE c.city=? AND c.car_type='chauffeur' AND d.status='active' AND d.is_online=1";
            $params = [$city];
        }
        $sql .= " ORDER BY c.id DESC";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond(["cars" => $s->fetchAll()]);
    }

    // ── Get one car with full details ─────────────────────────────────────────
    case "get_one": {
        $id = (int)($_GET["id"] ?? 0);
        if (!$id) respondError("Car ID required");

        $s = $pdo->prepare("SELECT c.*,
            d.rating as driver_rating, d.total_trips, d.is_online,
            u.name as driver_name, u.phone as driver_phone, u.photo_url as driver_photo
            FROM cars c
            LEFT JOIN drivers d ON c.driver_id=d.id
            LEFT JOIN users u ON d.user_id=u.id
            WHERE c.id=?");
        $s->execute([$id]);
        $car = $s->fetch();
        if (!$car) respondError("Car not found", 404);

        $ms = $pdo->prepare("SELECT * FROM car_media WHERE car_id=? AND status='approved' ORDER BY media_type,sort_order");
        $ms->execute([$id]);
        $car["media"] = $ms->fetchAll();

        $ps = $pdo->prepare("SELECT * FROM pricing WHERE city=?");
        $ps->execute([$car["city"]]);
        $car["pricing"] = $ps->fetchAll();

        respond(["car" => $car]);
    }

    // ── Get pricing ───────────────────────────────────────────────────────────
    case "get_pricing": {
        $city = $_GET["city"] ?? "Calabar";
        $type = $_GET["type"] ?? "";
        $sql  = "SELECT * FROM pricing WHERE city=?";
        $params = [$city];
        if ($type) { $sql .= " AND booking_type=?"; $params[] = $type; }
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond(["pricing" => $s->fetchAll()]);
    }

    // ── Admin: add a car ──────────────────────────────────────────────────────
    case "admin_add": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $name         = $input["name"]          ?? "";
        $carType      = $input["car_type"]      ?? "chauffeur"; // chauffeur | self_drive
        $category     = $input["category"]      ?? "sedan";
        $city         = $input["city"]          ?? "Calabar";
        $plate        = $input["plate_number"]  ?? "";
        $color        = $input["color"]         ?? "";
        $year         = (int)($input["year"]    ?? date("Y"));
        $make         = $input["make"]          ?? "";
        $model        = $input["model"]         ?? "";
        $transmission = $input["transmission"]  ?? "automatic";
        $fuel         = $input["fuel_type"]     ?? "petrol";
        $seats        = (int)($input["seats"]   ?? 5);
        $mileageLimit = (int)($input["mileage_limit_per_day"] ?? 0);
        $deposit      = (float)($input["security_deposit"] ?? 0);
        $driverId     = (int)($input["driver_id"] ?? 0);
        $description  = $input["description"]   ?? "";

        if (!$name || !$plate) respondError("name and plate_number required");

        $pdo->prepare("INSERT INTO cars (name,car_type,category,city,plate_number,color,year,make,model,
            transmission,fuel_type,seats,mileage_limit_per_day,security_deposit,driver_id,description,
            availability_status,is_available,created_by_admin)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'available',1,1)")
            ->execute([$name,$carType,$category,$city,$plate,$color,$year,$make,$model,
                $transmission,$fuel,$seats,$mileageLimit,$deposit,
                $driverId ?: null,$description]);

        respond(["success"=>true,"id"=>$pdo->lastInsertId()]);
    }

    // ── Admin: update car ─────────────────────────────────────────────────────
    case "admin_update": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $id = (int)($input["id"] ?? 0);
        if (!$id) respondError("id required");
        $fields = ["name","car_type","category","city","plate_number","color","year","make","model",
            "transmission","fuel_type","seats","mileage_limit_per_day","security_deposit",
            "driver_id","description","availability_status","is_available","surge_multiplier"];
        $sets = []; $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) { $sets[] = "$f=?"; $vals[] = $input[$f]; }
        }
        if (!$sets) respondError("Nothing to update");
        $vals[] = $id;
        $pdo->prepare("UPDATE cars SET ".implode(",",$sets)." WHERE id=?")->execute($vals);
        respond(["success"=>true]);
    }

    // ── Admin: set availability ───────────────────────────────────────────────
    case "set_availability": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $id     = (int)($input["car_id"] ?? 0);
        $status = $input["status"] ?? ""; // available | in_use
        $return = $input["expected_return"] ?? null;
        if (!$id || !in_array($status,["available","in_use"])) respondError("car_id and valid status required");
        $pdo->prepare("UPDATE cars SET availability_status=?, expected_return_at=?, is_available=? WHERE id=?")
            ->execute([$status,$return,$status==="available"?1:0,$id]);
        respond(["success"=>true]);
    }

    // ── Driver: add/update their own car ──────────────────────────────────────
    case "driver_save": {
        if (!$user || $user["role"] !== "driver") respondError("Driver access required", 403);
        $ds = $pdo->prepare("SELECT id FROM drivers WHERE user_id=?");
        $ds->execute([$user["id"]]);
        $driver = $ds->fetch();
        if (!$driver) respondError("Driver profile not found");

        // Check if driver already has a car
        $cs = $pdo->prepare("SELECT id FROM cars WHERE driver_id=?");
        $cs->execute([$driver["id"]]);
        $existing = $cs->fetch();

        $name         = $input["name"]         ?? "";
        $plate        = $input["plate_number"] ?? "";
        $color        = $input["color"]        ?? "";
        $year         = (int)($input["year"]   ?? date("Y"));
        $make         = $input["make"]         ?? "";
        $model        = $input["model"]        ?? "";
        $transmission = $input["transmission"] ?? "automatic";
        $fuel         = $input["fuel_type"]    ?? "petrol";
        $seats        = (int)($input["seats"]  ?? 5);
        $category     = $input["category"]     ?? "sedan";
        $city         = $user["city"] ?? "Calabar";

        if (!$name || !$plate) respondError("name and plate_number required");

        if ($existing) {
            $pdo->prepare("UPDATE cars SET name=?,plate_number=?,color=?,year=?,make=?,model=?,
                transmission=?,fuel_type=?,seats=?,category=?,car_type='chauffeur' WHERE id=?")
                ->execute([$name,$plate,$color,$year,$make,$model,$transmission,$fuel,$seats,$category,$existing["id"]]);
            respond(["success"=>true,"car_id"=>$existing["id"]]);
        } else {
            $pdo->prepare("INSERT INTO cars (name,car_type,category,city,plate_number,color,year,make,model,
                transmission,fuel_type,seats,driver_id,availability_status,is_available)
                VALUES (?,  'chauffeur',?,?,?,?,?,?,?,?,?,?,?,'available',1)")
                ->execute([$name,$category,$city,$plate,$color,$year,$make,$model,$transmission,$fuel,$seats,$driver["id"]]);
            $carId = $pdo->lastInsertId();
            // Update driver's car_id reference for quick access
            respond(["success"=>true,"car_id"=>$carId]);
        }
    }

    // ── Get admin cars ────────────────────────────────────────────────────────
    case "admin_get_all": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $type = $_GET["type"] ?? "";
        $sql  = "SELECT c.*, u.name as driver_name,
            (SELECT url FROM car_media WHERE car_id=c.id AND status='approved' ORDER BY sort_order LIMIT 1) as main_photo
            FROM cars c LEFT JOIN drivers d ON c.driver_id=d.id LEFT JOIN users u ON d.user_id=u.id";
        if ($type) $sql .= " WHERE c.car_type=?";
        $sql .= " ORDER BY c.id DESC";
        $s = $pdo->prepare($sql);
        $type ? $s->execute([$type]) : $s->execute();
        respond(["cars"=>$s->fetchAll()]);
    }

    // ── Get wishlist ──────────────────────────────────────────────────────────
    case "get_wishlist": {
        if (!$user) respondError("Unauthorised", 401);
        $s = $pdo->prepare("
            SELECT c.*,
                (SELECT url FROM car_media WHERE car_id=c.id AND status='approved' ORDER BY sort_order LIMIT 1) as main_photo,
                u.name as driver_name
            FROM wishlists w
            JOIN cars c ON c.id = w.car_id
            LEFT JOIN drivers d ON d.id = c.driver_id
            LEFT JOIN users u ON u.id = d.user_id
            WHERE w.user_id = ?
            ORDER BY w.id DESC");
        $s->execute([$user["id"]]);
        respond(["cars" => $s->fetchAll()]);
    }

    // ── Get wishlist IDs (for showing filled hearts on listing pages) ─────────
    case "get_wishlist_ids": {
        if (!$user) respond(["ids" => []]);
        $s = $pdo->prepare("SELECT car_id FROM wishlists WHERE user_id=?");
        $s->execute([$user["id"]]);
        respond(["ids" => array_column($s->fetchAll(), "car_id")]);
    }

    // ── Toggle wishlist ───────────────────────────────────────────────────────
    case "toggle_wishlist": {
        if (!$user) respondError("Unauthorised", 401);
        $carId = (int)($_GET["id"] ?? $input["car_id"] ?? 0);
        if (!$carId) respondError("Car ID required");
        $s = $pdo->prepare("SELECT id FROM wishlists WHERE user_id=? AND car_id=?");
        $s->execute([$user["id"], $carId]);
        if ($s->fetch()) {
            $pdo->prepare("DELETE FROM wishlists WHERE user_id=? AND car_id=?")->execute([$user["id"], $carId]);
            respond(["wishlisted" => false]);
        } else {
            $pdo->prepare("INSERT INTO wishlists (user_id, car_id) VALUES (?,?)")->execute([$user["id"], $carId]);
            respond(["wishlisted" => true]);
        }
    }

    default: respondError("Invalid action");
}
?>

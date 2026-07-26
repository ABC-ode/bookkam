<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

function eventPackagesForEvent($pdo, $eventKey) {
    $s = $pdo->prepare("SELECT * FROM event_packages WHERE event_key=? ORDER BY sort_order ASC, id ASC");
    $s->execute([$eventKey]);
    $packages = $s->fetchAll();
    if (!$packages) return [];

    $ids = array_column($packages, "id");
    $placeholders = implode(",", array_fill(0, count($ids), "?"));

    $cs = $pdo->prepare("SELECT * FROM event_package_cars WHERE package_id IN ($placeholders) ORDER BY sort_order ASC, id ASC");
    $cs->execute($ids);
    $carsByPackage = [];
    foreach ($cs->fetchAll() as $c) {
        $carsByPackage[$c["package_id"]][] = ["id" => (int)$c["id"], "model" => $c["model"], "year" => $c["year"], "photo" => $c["photo_url"]];
    }

    $ps = $pdo->prepare("SELECT * FROM event_package_pricing WHERE package_id IN ($placeholders)");
    $ps->execute($ids);
    $pricingByPackage = [];
    foreach ($ps->fetchAll() as $p) {
        $pricingByPackage[$p["package_id"]][] = ["location" => $p["location"], "price" => (float)$p["price"]];
    }

    foreach ($packages as &$pkg) {
        $pkg["cars"] = $carsByPackage[$pkg["id"]] ?? [];
        $pkg["location_pricing"] = $pricingByPackage[$pkg["id"]] ?? [];
    }
    unset($pkg);

    return $packages;
}

switch ($action) {

    // ── Public: list packages for an event, used by the booking modal ────────
    case "get_all": {
        $eventKey = $_GET["event"] ?? "";
        if (!$eventKey) respondError("event is required");
        respond(["packages" => eventPackagesForEvent($pdo, $eventKey)]);
        break;
    }

    // ── Admin: same data, requires login (used by the admin management page) ─
    case "admin_get_all": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $eventKey = $_GET["event"] ?? "";
        if (!$eventKey) respondError("event is required");
        respond(["packages" => eventPackagesForEvent($pdo, $eventKey)]);
        break;
    }

    // ── Admin: create a package tier ──────────────────────────────────────────
    case "admin_add_package": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $eventKey   = trim($input["event_key"] ?? "");
        $packageKey = trim($input["package_key"] ?? "");
        $name       = trim($input["name"] ?? "");
        $tagline    = trim($input["tagline"] ?? "");
        $sortOrder  = (int)($input["sort_order"] ?? 0);
        if (!$eventKey || !$packageKey || !$name) respondError("event_key, package_key and name are required");

        $stmt = $pdo->prepare("INSERT INTO event_packages (event_key,package_key,name,tagline,sort_order) VALUES (?,?,?,?,?)");
        $stmt->execute([$eventKey, $packageKey, $name, $tagline, $sortOrder]);
        $packageId = (int)$pdo->lastInsertId();

        foreach (($input["location_pricing"] ?? []) as $lp) {
            $loc = trim($lp["location"] ?? ""); $price = (float)($lp["price"] ?? 0);
            if ($loc && $price > 0) {
                $pdo->prepare("INSERT INTO event_package_pricing (package_id,location,price) VALUES (?,?,?)")->execute([$packageId, $loc, $price]);
            }
        }

        respond(["success" => true, "id" => $packageId]);
        break;
    }

    // ── Admin: update a package tier's name/tagline/pricing ───────────────────
    case "admin_update_package": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $id = (int)($input["id"] ?? 0);
        if (!$id) respondError("id is required");

        $sets = []; $vals = [];
        foreach (["name" => "name", "tagline" => "tagline", "sort_order" => "sort_order"] as $key => $col) {
            if (array_key_exists($key, $input)) { $sets[] = "$col=?"; $vals[] = $input[$key]; }
        }
        if ($sets) { $vals[] = $id; $pdo->prepare("UPDATE event_packages SET ".implode(",", $sets)." WHERE id=?")->execute($vals); }

        if (is_array($input["location_pricing"] ?? null)) {
            $pdo->prepare("DELETE FROM event_package_pricing WHERE package_id=?")->execute([$id]);
            foreach ($input["location_pricing"] as $lp) {
                $loc = trim($lp["location"] ?? ""); $price = (float)($lp["price"] ?? 0);
                if ($loc && $price > 0) {
                    $pdo->prepare("INSERT INTO event_package_pricing (package_id,location,price) VALUES (?,?,?)")->execute([$id, $loc, $price]);
                }
            }
        }

        respond(["success" => true]);
        break;
    }

    // ── Admin: delete a package tier (cascades to its cars/pricing) ───────────
    case "admin_delete_package": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $id = (int)($input["id"] ?? 0);
        if (!$id) respondError("id is required");
        $pdo->prepare("DELETE FROM event_packages WHERE id=?")->execute([$id]);
        respond(["success" => true]);
        break;
    }

    // ── Admin: add a car to a package (with photo) ────────────────────────────
    case "admin_add_car": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $packageId = (int)($input["package_id"] ?? 0);
        $model     = trim($input["model"] ?? "");
        $year      = trim($input["year"] ?? "");
        $photo     = trim($input["photo"] ?? "");
        $sortOrder = (int)($input["sort_order"] ?? 0);
        if (!$packageId || !$model) respondError("package_id and model are required");

        $pdo->prepare("INSERT INTO event_package_cars (package_id,model,year,photo_url,sort_order) VALUES (?,?,?,?,?)")
            ->execute([$packageId, $model, $year ?: null, $photo ?: null, $sortOrder]);
        respond(["success" => true, "id" => $pdo->lastInsertId()]);
        break;
    }

    // ── Admin: update a car within a package ──────────────────────────────────
    case "admin_update_car": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $id = (int)($input["id"] ?? 0);
        if (!$id) respondError("id is required");

        $sets = []; $vals = [];
        foreach (["model" => "model", "year" => "year", "sort_order" => "sort_order"] as $key => $col) {
            if (array_key_exists($key, $input)) { $sets[] = "$col=?"; $vals[] = $input[$key]; }
        }
        if (array_key_exists("photo", $input) && trim($input["photo"])) { $sets[] = "photo_url=?"; $vals[] = trim($input["photo"]); }
        if (!$sets) respondError("Nothing to update");
        $vals[] = $id;
        $pdo->prepare("UPDATE event_package_cars SET ".implode(",", $sets)." WHERE id=?")->execute($vals);
        respond(["success" => true]);
        break;
    }

    // ── Admin: remove a car from a package ─────────────────────────────────────
    case "admin_delete_car": {
        if (!$user || $user["role"] !== "admin") respondError("Admin access required", 403);
        $id = (int)($input["id"] ?? 0);
        if (!$id) respondError("id is required");
        $pdo->prepare("DELETE FROM event_package_cars WHERE id=?")->execute([$id]);
        respond(["success" => true]);
        break;
    }

    default:
        respondError("Unknown action");
}

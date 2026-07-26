<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$code = trim($_GET["code"] ?? "");
if (!$code) respondError("Code is required");

$stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code=? AND is_active=1 LIMIT 1");
$stmt->execute([$code]);
$promo = $stmt->fetch();

if (!$promo) respondError("Invalid or expired code");

if (!empty($promo["expiry_date"]) && strtotime($promo["expiry_date"]) < strtotime(date("Y-m-d"))) {
    respondError("This code has expired");
}

respond([
    "success" => true,
    "code" => $promo["code"],
    "discount_percent" => (float)$promo["discount_percent"],
]);

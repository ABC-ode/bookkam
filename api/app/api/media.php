<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$action = $_GET["action"] ?? $_POST["action"] ?? "";
$user   = getAuthUser($pdo);
if (!$user) respondError("Unauthorised", 401);

switch ($action) {

    case "upload": {
        $carId = (int)($_POST["car_id"] ?? $_GET["car_id"] ?? 0);
        $type  = $_POST["media_type"] ?? "photo";
        $cat   = $_POST["category"]   ?? "exterior";
        $label = $_POST["label"]      ?? "";

        if (!$carId) respondError("car_id required");
        if (!isset($_FILES["file"])) respondError("No file uploaded");

        $file = $_FILES["file"];
        if ($file["error"] !== UPLOAD_ERR_OK) respondError("File upload error: " . $file["error"]);

        $allowed = ["image/jpeg","image/png","image/webp","video/mp4"];
        if (!in_array($file["type"], $allowed)) respondError("File type not allowed");
        if ($file["size"] > 20 * 1024 * 1024) respondError("File too large (max 20MB)");

        $data = base64_encode(file_get_contents($file["tmp_name"]));
        $mime = $file["type"];
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => "https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD . "/image/upload",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                "file"          => "data:$mime;base64,$data",
                "upload_preset" => "bookkam_uploads",
                "api_key"       => CLOUDINARY_API_KEY,
            ],
        ]);
        $res  = curl_exec($curl);
        $resp = json_decode($res, true);
        curl_close($curl);

        if (!isset($resp["secure_url"])) respondError("Cloudinary upload failed: " . json_encode($resp));

        $url = $resp["secure_url"];

        $s = $pdo->prepare("SELECT COUNT(*) FROM car_media WHERE car_id=?");
        $s->execute([$carId]);
        $order = (int)$s->fetchColumn();

        $pdo->prepare("INSERT INTO car_media (car_id,media_type,category,url,label,status,sort_order) VALUES (?,?,?,?,?,'pending',?)")
            ->execute([$carId,$type,$cat,$url,$label,$order]);

        respond(["success"=>true,"url"=>$url,"message"=>"Uploaded to Cloudinary"]);
    }

    case "get_car_media": {
        $carId = (int)($_GET["car_id"] ?? 0);
        if (!$carId) respondError("car_id required");
        $s = $pdo->prepare("SELECT * FROM car_media WHERE car_id=? ORDER BY media_type,sort_order");
        $s->execute([$carId]);
        respond(["media" => $s->fetchAll()]);
    }

    case "delete": {
        $id = (int)(json_decode(file_get_contents("php://input"),true)["id"] ?? 0);
        $s  = $pdo->prepare("SELECT cm.*,c.driver_id FROM car_media cm JOIN cars c ON cm.car_id=c.id WHERE cm.id=?");
        $s->execute([$id]);
        $m = $s->fetch();
        if (!$m) respondError("Not found", 404);
        // Only the car's assigned driver or an admin may delete media
        if ($user["role"] !== "admin") {
            $ds = $pdo->prepare("SELECT id FROM drivers WHERE user_id=?");
            $ds->execute([$user["id"]]);
            $driver = $ds->fetch();
            if (!$driver || $driver["id"] != $m["driver_id"]) respondError("Access denied", 403);
        }
        $pdo->prepare("DELETE FROM car_media WHERE id=?")->execute([$id]);
        respond(["success" => true]);
    }

    default: respondError("Invalid action");
}
?>

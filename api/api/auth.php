<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

// ── JWT with custom expiry ────────────────────────────────────────────────────
function generateJWTExpiry($userId, $role, $expirySeconds) {
    $h = base64_encode(json_encode(["alg" => "HS256", "typ" => "JWT"]));
    $p = base64_encode(json_encode([
        "user_id" => $userId,
        "role"    => $role,
        "exp"     => time() + $expirySeconds,
        "iat"     => time(),
    ]));
    $s = rtrim(strtr(base64_encode(hash_hmac("sha256", "$h.$p", JWT_SECRET, true)), '+/', '-_'), '=');
    return "$h.$p.$s";
}

// ── Build standard user response ─────────────────────────────────────────────
function buildUserResponse($pdo, $verifyUser, $rememberMe = false) {
    $userId       = $verifyUser["id"];
    $driverStatus = null;
    $driverCar    = null;

    if ($verifyUser["role"] === "driver") {
        $ds = $pdo->prepare("SELECT d.*,c.id as car_id,c.name as car_name FROM drivers d LEFT JOIN cars c ON c.driver_id=d.id WHERE d.user_id=?");
        $ds->execute([$userId]);
        $driver = $ds->fetch();
        if (!$driver) {
            $pdo->prepare("INSERT INTO drivers (user_id,status) VALUES (?,?)")->execute([$userId, "pending"]);
            $driverStatus = "pending";
        } else {
            $driverStatus = $driver["status"];
            if ($driver["car_id"]) {
                $driverCar = ["id" => $driver["car_id"], "name" => $driver["car_name"]];
            }
        }
    }

    if (!$verifyUser["referral_code"]) {
        $refCode = strtoupper(substr(md5($userId . time()), 0, 8));
        $pdo->prepare("UPDATE users SET referral_code=? WHERE id=?")->execute([$refCode, $userId]);
        $verifyUser["referral_code"] = $refCode;
    }

    $expiry = $rememberMe ? (7 * 24 * 3600) : (24 * 3600);
    $token  = generateJWTExpiry($userId, $verifyUser["role"], $expiry);

    return [
        "token"         => $token,
        "remember_me"   => $rememberMe,
        "driver_status" => $driverStatus,
        "user"          => [
            "id"             => $verifyUser["id"],
            "name"           => $verifyUser["name"],
            "phone"          => $verifyUser["phone"],
            "email"          => $verifyUser["email"],
            "role"           => $verifyUser["role"],
            "city"           => $verifyUser["city"] ?? "Calabar",
            "wallet_balance" => $verifyUser["wallet_balance"],
            "photo_url"      => $verifyUser["photo_url"],
            "referral_code"  => $verifyUser["referral_code"],
            "car_id"         => $driverCar["id"] ?? null,
        ]
    ];
}

// ── Create new user ───────────────────────────────────────────────────────────
function createUser($pdo, $phone, $email, $role, $name, $city, $passwordHash = null, $googleId = null, $appleId = null) {
    $pdo->prepare("INSERT INTO users (phone,email,name,role,city,wallet_balance,password_hash,google_id,apple_id)
        VALUES (?,?,?,?,?,0,?,?,?)")
        ->execute([$phone, $email, $name, $role, $city, $passwordHash, $googleId, $appleId]);
    $userId = $pdo->lastInsertId();
    if ($role === "customer") {
        $pdo->prepare("UPDATE users SET wallet_balance=1000 WHERE id=?")->execute([$userId]);
    }
    $s = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $s->execute([$userId]);
    return $s->fetch();
}

switch ($action) {

    // ── OTP: send ─────────────────────────────────────────────────────────────
    case "send_otp":
    case "login": {
        $phone = trim($input["phone"] ?? "");
        $role  = $input["role"] ?? "customer";
        if (!$phone) respondError("Phone number required");
        if (!in_array($role, ["customer","driver"])) respondError("Invalid role");

        $s = $pdo->prepare("SELECT * FROM users WHERE REPLACE(phone,'+','') = ?");
        $s->execute([$phone]);
        $existingUser = $s->fetch();

        if (!$existingUser) {
            $existingUser = createUser($pdo, $phone, null, $role, null, "Calabar");
        }
        $userId = $existingUser["id"];
        $role   = $existingUser["role"];

        $attempts  = (int)($existingUser["otp_attempts"]  ?? 0);
        $lastSent  = $existingUser["otp_last_sent"] ?? null;
        $windowAgo = date("Y-m-d H:i:s", time() - OTP_WINDOW_SECS);

        if ($lastSent && $lastSent > $windowAgo) {
            if ($attempts >= OTP_MAX_ATTEMPTS) {
                $retryAfter = strtotime($lastSent) + OTP_WINDOW_SECS - time();
                $mins = ceil($retryAfter / 60);
                respondError("Too many OTP requests. Try again in {$mins} minute" . ($mins === 1 ? "" : "s") . ".", 429);
            }
        } else {
            $attempts = 0;
        }

        $otp     = TEST_MODE ? TEST_OTP : str_pad(random_int(0, 999999), 6, "0", STR_PAD_LEFT);
        $expires = date("Y-m-d H:i:s", strtotime("+5 minutes"));
        $now     = date("Y-m-d H:i:s");

        $pdo->prepare("UPDATE users SET otp_code=?, otp_expires_at=?, otp_attempts=?, otp_last_sent=? WHERE id=?")
            ->execute([$otp, $expires, $attempts + 1, $now, $userId]);

        if (!TEST_MODE && $role !== "admin") {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => "https://api.ng.termii.com/api/sms/otp/send",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
                CURLOPT_POSTFIELDS     => json_encode([
                    "api_key"          => TERMII_API_KEY,
                    "message_type"     => "NUMERIC",
                    "to"               => $phone,
                    "from"             => TERMII_SENDER_ID,
                    "channel"          => "dnd",
                    "pin_attempts"     => 3,
                    "pin_time_to_live" => 5,
                    "pin_length"       => 6,
                    "pin_placeholder"  => "< 1234 >",
                    "message_text"     => "Your BOOKKAM OTP is < 1234 >. Valid for 5 minutes.",
                    "pin_type"         => "NUMERIC",
                ]),
            ]);
            curl_exec($curl);
            curl_close($curl);
        }

        respond(["user_id" => $userId, "message" => "OTP sent"]);
    }

    // ── OTP: verify ───────────────────────────────────────────────────────────
    case "verify_otp": {
        $userId     = (int)($input["user_id"] ?? 0);
        $otp        = trim($input["otp"] ?? "");
        $rememberMe = !empty($input["remember_me"]);
        if (!$userId || !$otp) respondError("user_id and otp required");

        $s = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $s->execute([$userId]);
        $verifyUser = $s->fetch();
        if (!$verifyUser) respondError("User not found", 404);
        if (!empty($verifyUser["is_blacklisted"])) respondError("Your account has been suspended. Contact support.");

        $valid = TEST_MODE
            ? ($otp === TEST_OTP)
            : ($verifyUser["otp_code"] === $otp && strtotime($verifyUser["otp_expires_at"]) > time());

        if (!$valid) respondError("Invalid or expired OTP", 401);

        $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires_at=NULL, otp_attempts=0, otp_last_sent=NULL WHERE id=?")
            ->execute([$userId]);

        respond(buildUserResponse($pdo, $verifyUser, $rememberMe));
    }

    // ── Email: register ───────────────────────────────────────────────────────
    case "register_email": {
        $name     = trim($input["name"]     ?? "");
        $email    = trim($input["email"]    ?? "");
        $password = $input["password"]      ?? "";
        $confirm  = $input["confirm"]       ?? "";
        $role     = $input["role"]          ?? "customer";
        $city     = trim($input["city"]     ?? "Calabar");

        if (!$name)     respondError("Full name required");
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) respondError("Valid email required");
        if (!$password) respondError("Password required");
        if (strlen($password) < 6) respondError("Password must be at least 6 characters");
        if ($password !== $confirm) respondError("Passwords do not match");
        if (!in_array($role, ["customer","driver"])) respondError("Invalid role");

        $s = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $s->execute([$email]);
        if ($s->fetch()) respondError("Email already registered. Please login.");

        $hash    = password_hash($password, PASSWORD_BCRYPT, ["cost" => 12]);
        $newUser = createUser($pdo, null, $email, $role, $name, $city, $hash);
        respond(buildUserResponse($pdo, $newUser, false));
    }

    // ── Email: login ──────────────────────────────────────────────────────────
    case "login_email": {
        $email      = trim($input["email"]    ?? "");
        $password   = $input["password"]      ?? "";
        $rememberMe = !empty($input["remember_me"]);

        if (!$email || !$password) respondError("Email and password required");

        $s = $pdo->prepare("SELECT * FROM users WHERE email=? AND role != 'admin'");
        $s->execute([$email]);
        $loginUser = $s->fetch();

        if (!$loginUser || !$loginUser["password_hash"] || !password_verify($password, $loginUser["password_hash"])) {
            respondError("Invalid email or password", 401);
        }
        if (!empty($loginUser["is_blacklisted"])) respondError("Your account has been suspended. Contact support.");

        respond(buildUserResponse($pdo, $loginUser, $rememberMe));
    }

    // ── Google login ──────────────────────────────────────────────────────────
    case "google_login": {
        $idToken    = $input["id_token"] ?? "";
        $role       = $input["role"]     ?? "customer";
        $city       = trim($input["city"] ?? "");
        $rememberMe = !empty($input["remember_me"]);

        if (!$idToken) respondError("Google ID token required");
        if (!in_array($role, ["customer","driver"])) respondError("Invalid role");

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($idToken),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $res     = curl_exec($curl);
        $payload = json_decode($res, true);
        curl_close($curl);

        if (empty($payload["sub"])) respondError("Invalid Google token", 401);
        if (($payload["aud"] ?? "") !== GOOGLE_CLIENT_ID) respondError("Invalid Google token", 401);

        $googleId = $payload["sub"];
        $email    = $payload["email"]   ?? null;
        $name     = $payload["name"]    ?? null;
        $photo    = $payload["picture"] ?? null;

        $s = $pdo->prepare("SELECT * FROM users WHERE google_id=? OR (email=? AND email IS NOT NULL) LIMIT 1");
        $s->execute([$googleId, $email]);
        $googleUser = $s->fetch();

        if ($googleUser) {
            if (!$googleUser["google_id"]) {
                $pdo->prepare("UPDATE users SET google_id=? WHERE id=?")->execute([$googleId, $googleUser["id"]]);
            }
            if ($photo && !$googleUser["photo_url"]) {
                $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$photo, $googleUser["id"]]);
            }
            if (!empty($googleUser["is_blacklisted"])) respondError("Your account has been suspended. Contact support.");
            respond(buildUserResponse($pdo, $googleUser, $rememberMe));
        }

        // New user — need city
        if (!$city) respond(["needs_city" => true, "google_id" => $googleId, "email" => $email, "name" => $name, "photo" => $photo, "role" => $role]);

        $newUser = createUser($pdo, null, $email, $role, $name, $city, null, $googleId);
        if ($photo) {
            $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$photo, $newUser["id"]]);
            $newUser["photo_url"] = $photo;
        }
        respond(buildUserResponse($pdo, $newUser, $rememberMe));
    }

    // ── Apple login ───────────────────────────────────────────────────────────
    case "apple_login": {
        $idToken    = $input["id_token"]   ?? "";
        $role       = $input["role"]       ?? "customer";
        $city       = trim($input["city"]  ?? "");
        $appleEmail = trim($input["email"] ?? "");
        $appleName  = trim($input["name"]  ?? "");
        $rememberMe = !empty($input["remember_me"]);

        if (!$idToken) respondError("Apple ID token required");
        if (!in_array($role, ["customer","driver"])) respondError("Invalid role");

        $parts = explode(".", $idToken);
        if (count($parts) < 2) respondError("Invalid Apple token", 401);
        $applePayload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
        if (empty($applePayload["sub"])) respondError("Invalid Apple token", 401);

        $appleId = $applePayload["sub"];
        $email   = $appleEmail ?: ($applePayload["email"] ?? null);

        $s = $pdo->prepare("SELECT * FROM users WHERE apple_id=? OR (email=? AND email IS NOT NULL) LIMIT 1");
        $s->execute([$appleId, $email]);
        $appleUser = $s->fetch();

        if ($appleUser) {
            if (!$appleUser["apple_id"]) {
                $pdo->prepare("UPDATE users SET apple_id=? WHERE id=?")->execute([$appleId, $appleUser["id"]]);
            }
            if (!empty($appleUser["is_blacklisted"])) respondError("Your account has been suspended. Contact support.");
            respond(buildUserResponse($pdo, $appleUser, $rememberMe));
        }

        if (!$city) respond(["needs_city" => true, "apple_id" => $appleId, "email" => $email, "name" => $appleName, "role" => $role]);

        $newUser = createUser($pdo, null, $email, $role, $appleName ?: null, $city, null, null, $appleId);
        respond(buildUserResponse($pdo, $newUser, $rememberMe));
    }

    // ── Set city after Google/Apple first login ────────────────────────────────
    case "set_city": {
        $city       = trim($input["city"]     ?? "");
        $googleId   = $input["google_id"]     ?? null;
        $appleId    = $input["apple_id"]      ?? null;
        $role       = $input["role"]          ?? "customer";
        $email      = trim($input["email"]    ?? "");
        $name       = trim($input["name"]     ?? "");
        $photo      = $input["photo"]         ?? null;
        $rememberMe = !empty($input["remember_me"]);

        if (!$city) respondError("City required");

        if ($googleId) {
            $s = $pdo->prepare("SELECT * FROM users WHERE google_id=?");
            $s->execute([$googleId]);
            $existing = $s->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE users SET city=? WHERE id=?")->execute([$city, $existing["id"]]);
                $existing["city"] = $city;
                respond(buildUserResponse($pdo, $existing, $rememberMe));
            }
            $newUser = createUser($pdo, null, $email ?: null, $role, $name ?: null, $city, null, $googleId);
            if ($photo) $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$photo, $newUser["id"]]);
            respond(buildUserResponse($pdo, $newUser, $rememberMe));
        }

        if ($appleId) {
            $s = $pdo->prepare("SELECT * FROM users WHERE apple_id=?");
            $s->execute([$appleId]);
            $existing = $s->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE users SET city=? WHERE id=?")->execute([$city, $existing["id"]]);
                $existing["city"] = $city;
                respond(buildUserResponse($pdo, $existing, $rememberMe));
            }
            $newUser = createUser($pdo, null, $email ?: null, $role, $name ?: null, $city, null, null, $appleId);
            respond(buildUserResponse($pdo, $newUser, $rememberMe));
        }

        respondError("Missing provider ID");
    }

    // ── Get driver status ─────────────────────────────────────────────────────
    case "get_driver_status": {
        if (!$user) respondError("Unauthorised", 401);
        $ds = $pdo->prepare("SELECT d.status FROM drivers d WHERE d.user_id=?");
        $ds->execute([$user["id"]]);
        $driver = $ds->fetch();
        respond(["driver_status" => $driver ? $driver["status"] : null]);
    }

    // ── Admin login ───────────────────────────────────────────────────────────
    case "admin_login": {
        $email    = trim($input["email"]    ?? "");
        $password = $input["password"]      ?? "";
        if (!$email || !$password) respondError("Email and password required");
        $s = $pdo->prepare("SELECT * FROM users WHERE email=? AND role='admin'");
        $s->execute([$email]);
        $adminUser = $s->fetch();
        if (!$adminUser || !password_verify($password, $adminUser["password_hash"])) {
            respondError("Invalid credentials", 401);
        }
        $token = generateJWTExpiry($adminUser["id"], "admin", 24 * 3600);
        respond([
            "token" => $token,
            "user"  => ["id" => $adminUser["id"], "name" => $adminUser["name"], "email" => $adminUser["email"], "role" => "admin"]
        ]);
    }

    // ── Update profile ────────────────────────────────────────────────────────
    case "update_profile": {
        if (!$user) respondError("Unauthorised", 401);
        $name  = $input["name"]  ?? $user["name"];
        $email = $input["email"] ?? $user["email"];
        $city  = $input["city"]  ?? $user["city"];
        $pdo->prepare("UPDATE users SET name=?,email=?,city=? WHERE id=?")->execute([$name, $email, $city, $user["id"]]);
        respond(["success" => true, "message" => "Profile updated"]);
    }

    default: respondError("Invalid action");
}
?>

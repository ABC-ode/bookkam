<?php
require_once "../config/db.php";
require_once "../config/config.php";
session_start();
apiHeaders();

// Parse JSON body if present
$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

/* ---------- Content negotiation helpers (AJAX vs manual form) ---------- */
function wantsJson() {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($ct, 'application/json') !== false) return true;
    return false;
}

function send_json($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function redirect_or_json($payload) {
    if (wantsJson()) {
        send_json($payload);
    }

    // Non-AJAX manual form: prefer server-provided redirect if present
    if (!empty($payload['redirect'])) {
        header('Location: ' . $payload['redirect']);
        exit;
    }

    // Best-effort redirect by role
    if (!empty($payload['user']['role'])) {
        $role = $payload['user']['role'];
        if ($role === 'driver') {
            $driverStatus = $payload['driver_status'] ?? null;
            if ($driverStatus && $driverStatus !== 'active') {
                header('Location: under-review.php?status=' . urlencode($driverStatus));
                exit;
            } else {
                header('Location: driver/dashboard.php');
                exit;
            }
        } else {
            header('Location: app/dashboard.php');
            exit;
        }
    }

    // fallback
    header('Location: dashboard.php');
    exit;
}

function error_redirect_or_json($message = "Error", $code = 400) {
    if (wantsJson()) {
        send_json(["error" => $message], $code);
    }

    // Non-AJAX: redirect back to referrer with error query param if possible
    $referer = $_SERVER['HTTP_REFERER'] ?? null;
    if ($referer) {
        $sep = strpos($referer, '?') === false ? '?' : '&';
        header('Location: ' . $referer . $sep . 'error=' . urlencode($message));
        exit;
    }

    // Fallback: render a minimal error page
    http_response_code($code);
    echo "<!doctype html><meta charset='utf-8'><title>Error</title><body><h1>Error</h1><p>" . htmlspecialchars($message) . "</p></body>";
    exit;
}

/* ---------- Small manual HTML render helpers ---------- */
function render_html_page($title, $body_html) {
    echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>" . htmlspecialchars($title) . "</title>";
    echo "<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,'Helvetica Neue',Arial;padding:20px;background:#f7f7f7} .card{max-width:720px;margin:36px auto;padding:20px;background:#fff;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.06)}</style>";
    echo "</head><body><div class='card'>" . $body_html . "</div></body></html>";
    exit;
}

/* ---------- JWT helper ---------- */
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

/* ---------- Build user response (includes redirect for convenience) ---------- */
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

    if (empty($verifyUser["referral_code"])) {
        $refCode = strtoupper(substr(md5($userId . time()), 0, 8));
        $pdo->prepare("UPDATE users SET referral_code=? WHERE id=?")->execute([$refCode, $userId]);
        $verifyUser["referral_code"] = $refCode;
    }

    $expiry = $rememberMe ? (7 * 24 * 3600) : (24 * 3600);
    $token  = generateJWTExpiry($userId, $verifyUser["role"], $expiry);

    // Compute redirect target for manual flows
    if ($verifyUser["role"] === "driver") {
        if ($driverStatus === "active") {
            $redirect = "driver/dashboard.php";
        } else {
            $status = $driverStatus ?: "pending";
            $redirect = "under-review.php?status=" . urlencode($status);
        }
    } else {
        $redirect = "dashboard.php";
    }

    return [
        "token"         => $token,
        "remember_me"   => $rememberMe,
        "driver_status" => $driverStatus,
        "redirect"      => $redirect,
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

/* ---------- Create user ---------- */
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

/* ---------- Action switch ---------- */
switch ($action) {

    // OTP send
    case "send_otp":
    case "login": {
        $phone = trim($input["phone"] ?? $_POST["phone"] ?? "");
        $role  = $input["role"] ?? $_POST["role"] ?? "customer";
        if (!$phone) error_redirect_or_json("Phone number required");
        if (!in_array($role, ["customer","driver"])) error_redirect_or_json("Invalid role");

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
                error_redirect_or_json("Too many OTP requests. Try again in {$mins} minute" . ($mins === 1 ? "" : "s") . ".", 429);
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

        // Manual flow: store user_id in session and render OTP entry page
        if (!wantsJson()) {
            $_SESSION['otp_user_id'] = $userId;
            $html = "<h2>Enter OTP</h2>";
            $html .= "<p>An OTP was sent to <strong>" . htmlspecialchars($phone) . "</strong>. Valid for 5 minutes.</p>";
            $html .= "<form method='POST' action='auth.php?action=verify_otp'>";
            $html .= "<input type='hidden' name='user_id' value='" . htmlspecialchars($userId) . "'>";
            $html .= "<label>OTP <input name='otp' maxlength='6' required></label><br/><br/>";
            $html .= "<label><input type='checkbox' name='remember_me' value='1'> Remember me</label><br/><br/>";
            $html .= "<button type='submit'>Verify OTP</button>";
            $html .= "</form>";
            render_html_page("Enter OTP", $html);
        }

        // JSON response for AJAX
        send_json(["user_id" => $userId, "message" => "OTP sent"]);
    }

    // OTP verify
    case "verify_otp": {
        $userId     = (int)($input["user_id"] ?? $_POST["user_id"] ?? $_SESSION['otp_user_id'] ?? 0);
        $otp        = trim($input["otp"] ?? $_POST["otp"] ?? "");
        $rememberMe = !empty($input["remember_me"] ?? $_POST["remember_me"] ?? false);
        if (!$userId || !$otp) error_redirect_or_json("user_id and otp required");

        $s = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $s->execute([$userId]);
        $verifyUser = $s->fetch();
        if (!$verifyUser) error_redirect_or_json("User not found", 404);
        if (!empty($verifyUser["is_blacklisted"])) error_redirect_or_json("Your account has been suspended. Contact support.");

        $valid = TEST_MODE
            ? ($otp === TEST_OTP)
            : ($verifyUser["otp_code"] === $otp && strtotime($verifyUser["otp_expires_at"]) > time());

        if (!$valid) error_redirect_or_json("Invalid or expired OTP", 401);

        $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires_at=NULL, otp_attempts=0, otp_last_sent=NULL WHERE id=?")
            ->execute([$userId]);

        // Clear session value
        if (isset($_SESSION['otp_user_id'])) unset($_SESSION['otp_user_id']);

        $payload = buildUserResponse($pdo, $verifyUser, $rememberMe);
        redirect_or_json($payload);
    }

    // Register email
    case "register_email": {
        $name     = trim($input["name"] ?? $_POST["name"] ?? "");
        $email    = trim($input["email"] ?? $_POST["email"] ?? "");
        $password = $input["password"] ?? $_POST["password"] ?? "";
        $confirm  = $input["confirm"] ?? $_POST["confirm"] ?? "";
        $role     = $input["role"] ?? $_POST["role"] ?? "customer";
        $city     = trim($input["city"] ?? $_POST["city"] ?? "Calabar");

        if (!$name) error_redirect_or_json("Full name required");
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) error_redirect_or_json("Valid email required");
        if (!$password) error_redirect_or_json("Password required");
        if (strlen($password) < 6) error_redirect_or_json("Password must be at least 6 characters");
        if ($password !== $confirm) error_redirect_or_json("Passwords do not match");
        if (!in_array($role, ["customer","driver"])) error_redirect_or_json("Invalid role");

        $s = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $s->execute([$email]);
        if ($s->fetch()) error_redirect_or_json("Email already registered. Please login.");

        $hash    = password_hash($password, PASSWORD_BCRYPT, ["cost" => 12]);
        $newUser = createUser($pdo, null, $email, $role, $name, $city, $hash);
        $payload = buildUserResponse($pdo, $newUser, false);
        redirect_or_json($payload);
    }

    // Login email
    case "login_email": {
        $email      = trim($input["email"] ?? $_POST["email"] ?? "");
        $password   = $input["password"] ?? $_POST["password"] ?? "";
        $rememberMe = !empty($input["remember_me"] ?? $_POST["remember_me"] ?? false);

        if (!$email || !$password) error_redirect_or_json("Email and password required");

        $s = $pdo->prepare("SELECT * FROM users WHERE email=? AND role != 'admin'");
        $s->execute([$email]);
        $loginUser = $s->fetch();

        if (!$loginUser || !$loginUser["password_hash"] || !password_verify($password, $loginUser["password_hash"])) {
            error_redirect_or_json("Invalid email or password", 401);
        }
        if (!empty($loginUser["is_blacklisted"])) error_redirect_or_json("Your account has been suspended. Contact support.");

        $payload = buildUserResponse($pdo, $loginUser, $rememberMe);
        redirect_or_json($payload);
    }

    // Google login
    case "google_login": {
        $idToken    = $input["id_token"] ?? $_POST["id_token"] ?? "";
        $role       = $input["role"] ?? $_POST["role"] ?? "customer";
        $city       = trim($input["city"] ?? $_POST["city"] ?? "");
        $rememberMe = !empty($input["remember_me"] ?? $_POST["remember_me"] ?? false);

        if (!$idToken) error_redirect_or_json("Google ID token required");
        if (!in_array($role, ["customer","driver"])) error_redirect_or_json("Invalid role");

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($idToken),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $res     = curl_exec($curl);
        $payload = json_decode($res, true);
        curl_close($curl);

        if (empty($payload["sub"])) error_redirect_or_json("Invalid Google token", 401);
        if (($payload["aud"] ?? "") !== GOOGLE_CLIENT_ID) error_redirect_or_json("Invalid Google token", 401);

        $googleId = $payload["sub"];
        $email    = $payload["email"]   ?? null;
        $name     = $payload["name"]    ?? null;
        $photo    = $payload["picture"] ?? null;

        $s = $pdo->prepare("SELECT * FROM users WHERE google_id=? OR (email=? AND email IS NOT NULL) LIMIT 1");
        $s->execute([$googleId, $email]);
        $googleUser = $s->fetch();

        if ($googleUser) {
            if (empty($googleUser["google_id"])) {
                $pdo->prepare("UPDATE users SET google_id=? WHERE id=?")->execute([$googleId, $googleUser["id"]]);
            }
            if ($photo && empty($googleUser["photo_url"])) {
                $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$photo, $googleUser["id"]]);
            }
            if (!empty($googleUser["is_blacklisted"])) error_redirect_or_json("Your account has been suspended. Contact support.");
            $payload = buildUserResponse($pdo, $googleUser, $rememberMe);
            redirect_or_json($payload);
        }

        // New user — need city
        if (!$city) {
            // Manual flow: render a city picker form (stores pending data in session)
            if (!wantsJson()) {
                $_SESSION['pending_google'] = ["google_id"=>$googleId,"email"=>$email,"name"=>$name,"photo"=>$photo,"role"=>$role];
                $html = "<h2>Welcome" . ($name ? " " . htmlspecialchars($name) : "") . "</h2>";
                $html .= "<p>Please pick your city to continue.</p>";
                $html .= "<form method='POST' action='auth.php?action=set_city'>";
                $html .= "<label>City <select name='city'>";
                foreach(["Calabar","Ikom","Obudu","Ogoja","Uyo","Port Harcourt","Abuja","Lagos"] as $c) {
                    $html .= "<option>" . htmlspecialchars($c) . "</option>";
                }
                $html .= "</select></label><br/><br/>";
                $html .= "<input type='hidden' name='google_id' value='" . htmlspecialchars($googleId) . "'>";
                $html .= "<input type='hidden' name='email' value='" . htmlspecialchars($email) . "'>";
                $html .= "<input type='hidden' name='name' value='" . htmlspecialchars($name) . "'>";
                $html .= "<input type='hidden' name='role' value='" . htmlspecialchars($role) . "'>";
                $html .= "<button type='submit'>Continue</button>";
                $html .= "</form>";
                render_html_page("Pick your city", $html);
            }
            // AJAX client: tell it we need city
            send_json(["needs_city" => true, "google_id" => $googleId, "email" => $email, "name" => $name, "photo" => $photo, "role" => $role]);
        }

        // City provided (manual flow or AJAX with city)
        $newUser = createUser($pdo, null, $email, $role, $name, $city, null, $googleId);
        if ($photo) {
            $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$photo, $newUser["id"]]);
            $newUser["photo_url"] = $photo;
        }
        $payload = buildUserResponse($pdo, $newUser, $rememberMe);
        redirect_or_json($payload);
    }

    // Apple login
    case "apple_login": {
        $idToken    = $input["id_token"] ?? $_POST["id_token"] ?? "";
        $role       = $input["role"] ?? $_POST["role"] ?? "customer";
        $city       = trim($input["city"] ?? $_POST["city"] ?? "");
        $appleEmail = trim($input["email"] ?? $_POST["email"] ?? "");
        $appleName  = trim($input["name"] ?? $_POST["name"] ?? "");
        $rememberMe = !empty($input["remember_me"] ?? $_POST["remember_me"] ?? false);

        if (!$idToken) error_redirect_or_json("Apple ID token required");
        if (!in_array($role, ["customer","driver"])) error_redirect_or_json("Invalid role");

        $parts = explode(".", $idToken);
        if (count($parts) < 2) error_redirect_or_json("Invalid Apple token", 401);
        $payloadJson = str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT);
        $applePayload = json_decode(base64_decode($payloadJson), true);
        if (empty($applePayload["sub"])) error_redirect_or_json("Invalid Apple token", 401);

        $appleId = $applePayload["sub"];
        $email   = $appleEmail ?: ($applePayload["email"] ?? null);

        $s = $pdo->prepare("SELECT * FROM users WHERE apple_id=? OR (email=? AND email IS NOT NULL) LIMIT 1");
        $s->execute([$appleId, $email]);
        $appleUser = $s->fetch();

        if ($appleUser) {
            if (empty($appleUser["apple_id"])) {
                $pdo->prepare("UPDATE users SET apple_id=? WHERE id=?")->execute([$appleId, $appleUser["id"]]);
            }
            if (!empty($appleUser["is_blacklisted"])) error_redirect_or_json("Your account has been suspended. Contact support.");
            $payload = buildUserResponse($pdo, $appleUser, $rememberMe);
            redirect_or_json($payload);
        }

        if (!$city) {
            if (!wantsJson()) {
                $_SESSION['pending_apple'] = ["apple_id"=>$appleId,"email"=>$email,"name"=>$appleName,"role"=>$role];
                $html = "<h2>Welcome" . ($appleName ? " " . htmlspecialchars($appleName) : "") . "</h2>";
                $html .= "<p>Please pick your city to continue.</p>";
                $html .= "<form method='POST' action='auth.php?action=set_city'>";
                $html .= "<label>City <select name='city'>";
                foreach(["Calabar","Ikom","Obudu","Ogoja","Uyo","Port Harcourt","Abuja","Lagos"] as $c) {
                    $html .= "<option>" . htmlspecialchars($c) . "</option>";
                }
                $html .= "</select></label><br/><br/>";
                $html .= "<input type='hidden' name='apple_id' value='" . htmlspecialchars($appleId) . "'>";
                $html .= "<input type='hidden' name='email' value='" . htmlspecialchars($email) . "'>";
                $html .= "<input type='hidden' name='name' value='" . htmlspecialchars($appleName) . "'>";
                $html .= "<input type='hidden' name='role' value='" . htmlspecialchars($role) . "'>";
                $html .= "<button type='submit'>Continue</button>";
                $html .= "</form>";
                render_html_page("Pick your city", $html);
            }
            send_json(["needs_city" => true, "apple_id" => $appleId, "email" => $email, "name" => $appleName, "role" => $role]);
        }

        $newUser = createUser($pdo, null, $email, $role, $appleName ?: null, $city, null, null, $appleId);
        $payload = buildUserResponse($pdo, $newUser, $rememberMe);
        redirect_or_json($payload);
    }

    // Set city (after social)
    case "set_city": {
        $city       = trim($input["city"] ?? $_POST["city"] ?? "");
        $googleId   = $input["google_id"] ?? $_POST["google_id"] ?? ($_SESSION['pending_google']['google_id'] ?? null);
        $appleId    = $input["apple_id"]  ?? $_POST["apple_id"]  ?? ($_SESSION['pending_apple']['apple_id'] ?? null);
        $role       = $input["role"]      ?? $_POST["role"] ?? ($_SESSION['pending_google']['role'] ?? $_SESSION['pending_apple']['role'] ?? "customer");
        $email      = trim($input["email"] ?? $_POST["email"] ?? ($_SESSION['pending_google']['email'] ?? $_SESSION['pending_apple']['email'] ?? ""));
        $name       = trim($input["name"]  ?? $_POST["name"]  ?? ($_SESSION['pending_google']['name']  ?? $_SESSION['pending_apple']['name'] ?? ""));
        $photo      = $input["photo"] ?? $_POST["photo"] ?? ($_SESSION['pending_google']['photo'] ?? null);
        $rememberMe = !empty($input["remember_me"] ?? $_POST["remember_me"] ?? false);

        if (!$city) error_redirect_or_json("City required");

        if ($googleId) {
            $s = $pdo->prepare("SELECT * FROM users WHERE google_id=?");
            $s->execute([$googleId]);
            $existing = $s->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE users SET city=? WHERE id=?")->execute([$city, $existing["id"]]);
                $existing["city"] = $city;
                if (isset($_SESSION['pending_google'])) unset($_SESSION['pending_google']);
                redirect_or_json(buildUserResponse($pdo, $existing, $rememberMe));
            }
            $newUser = createUser($pdo, null, $email ?: null, $role, $name ?: null, $city, null, $googleId);
            if ($photo) $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$photo, $newUser["id"]]);
            if (isset($_SESSION['pending_google'])) unset($_SESSION['pending_google']);
            redirect_or_json(buildUserResponse($pdo, $newUser, $rememberMe));
        }

        if ($appleId) {
            $s = $pdo->prepare("SELECT * FROM users WHERE apple_id=?");
            $s->execute([$appleId]);
            $existing = $s->fetch();
            if ($existing) {
                $pdo->prepare("UPDATE users SET city=? WHERE id=?")->execute([$city, $existing["id"]]);
                $existing["city"] = $city;
                if (isset($_SESSION['pending_apple'])) unset($_SESSION['pending_apple']);
                redirect_or_json(buildUserResponse($pdo, $existing, $rememberMe));
            }
            $newUser = createUser($pdo, null, $email ?: null, $role, $name ?: null, $city, null, null, $appleId);
            if (isset($_SESSION['pending_apple'])) unset($_SESSION['pending_apple']);
            redirect_or_json(buildUserResponse($pdo, $newUser, $rememberMe));
        }

        error_redirect_or_json("Missing provider ID");
    }

    // Get driver status
    case "get_driver_status": {
        if (!$user) error_redirect_or_json("Unauthorised", 401);
        $ds = $pdo->prepare("SELECT d.status FROM drivers d WHERE d.user_id=?");
        $ds->execute([$user["id"]]);
        $driver = $ds->fetch();
        send_json(["driver_status" => $driver ? $driver["status"] : null]);
    }

    // Admin login
    case "admin_login": {
        $email    = trim($input["email"] ?? $_POST["email"] ?? "");
        $password = $input["password"] ?? $_POST["password"] ?? "";
        if (!$email || !$password) error_redirect_or_json("Email and password required");
        $s = $pdo->prepare("SELECT * FROM users WHERE email=? AND role='admin'");
        $s->execute([$email]);
        $adminUser = $s->fetch();
        if (!$adminUser || !password_verify($password, $adminUser["password_hash"])) {
            error_redirect_or_json("Invalid credentials", 401);
        }
        $token = generateJWTExpiry($adminUser["id"], "admin", 24 * 3600);
        // manual flow: redirect to admin panel
        if (!wantsJson()) {
            header('Location: admin.php');
            exit;
        }
        send_json([
            "token" => $token,
            "user"  => ["id" => $adminUser["id"], "name" => $adminUser["name"], "email" => $adminUser["email"], "role" => "admin"]
        ]);
    }

    // Update profile
    case "update_profile": {
        if (!$user) error_redirect_or_json("Unauthorised", 401);
        $name  = $input["name"] ?? $_POST["name"] ?? $user["name"];
        $email = $input["email"] ?? $_POST["email"] ?? $user["email"];
        $city  = $input["city"] ?? $_POST["city"] ?? $user["city"];
        $pdo->prepare("UPDATE users SET name=?,email=?,city=? WHERE id=?")->execute([$name, $email, $city, $user["id"]]);
        redirect_or_json(["success" => true, "message" => "Profile updated"]);
    }

    default:
        error_redirect_or_json("Invalid action");
}
?>
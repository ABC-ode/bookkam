<?php
require_once "../config/db.php";
require_once "../config/config.php";
require_once "../config/security.php";
require_once "../services/Mailer.php";
require_once "../services/AccountMail.php";
require_once "../services/Totp.php";
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
    // No welcome credit here: accounts start at 0. The credit is granted separately, once, only after the
    // identity is proven (see grantWelcomeCredit() in config/security.php).
    $s = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $s->execute([$userId]);
    return $s->fetch();
}

// A constant, publicly-known bcrypt hash: verifying against it makes "no such account" cost the same as "wrong password".
const TIMING_HASH = '$2y$12$Wp8EqEPFx4ddEgMiFzxev.8pWtEKBx48DTsmTSmGYlRCdy/vt5wbS';
function genericAccountReply(): void {
    respond(["success" => true, "message" => "If that address can be used, we have sent an email to it. Check your inbox (and spam folder)."]);
}

// ── Admin session / second-factor helpers ─────────────────────────────────
function adminSessionReply(array $adm): void {
    respond(["token" => generateJWTExpiry($adm["id"], "admin", 24 * 3600),
             "user"  => ["id" => $adm["id"], "name" => $adm["name"], "email" => $adm["email"], "role" => "admin"]]);
}
// The admin being enrolled: a signed-in admin session, or (only when 2FA is required) the setup challenge issued after a correct password.
function adminForTwoFactor(PDO $pdo, $user, array $input): array {
    if ($user && $user["role"] === "admin") return $user;
    $aid = Totp::readChallenge((string)($input["challenge"] ?? ""), "setup");
    if ($aid) { $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='admin'"); $s->execute([$aid]); if ($r = $s->fetch()) return $r; }
    respondError("Unauthorised", 401);
}

switch ($action) {

    // ── Email: register ──────────────────────────────────────────────────────
    // Creates an UNVERIFIED account and e-mails a single-use link. No session is issued until the address is confirmed.
    // The reply is identical whether or not the address is already registered.
    case "register_email": {
        $name     = cleanText($input["name"] ?? "", 80);
        $email    = normalizeEmail($input["email"] ?? "");
        $password = is_string($input["password"] ?? null) ? $input["password"] : "";
        $confirm  = is_string($input["confirm"]  ?? null) ? $input["confirm"]  : "";
        $role     = $input["role"] ?? "customer";
        $city     = cleanText($input["city"] ?? "Calabar", 60) ?: "Calabar";

        if (!$name)  respondError("Full name required");
        if (!$email) respondError("Valid email required");
        if ($e = passwordProblem($password, $email)) respondError($e);
        if ($password !== $confirm) respondError("Passwords do not match");
        if (!in_array($role, ["customer", "driver"], true)) respondError("Invalid role");

        countOnly($pdo, "reg_ip", clientIp(), 10, 3600);                    // registration floods
        countOnly($pdo, "mail_acct", hash("sha256", $email), 5, 3600);      // at most 5 mails per address per hour

        $existing = $pdo->prepare("SELECT * FROM users WHERE LOWER(email)=? ORDER BY id LIMIT 1");
        $existing->execute([$email]);
        $row = $existing->fetch();
        if ($row) {
            // Never overwrite an existing account's password. An unconfirmed one just gets a fresh link.
            if (array_key_exists("email_verified_at", $row) && empty($row["email_verified_at"]) && $row["role"] !== "admin") sendVerificationEmail($pdo, $row);
            genericAccountReply();
        }
        $hash    = password_hash($password, PASSWORD_BCRYPT, ["cost" => 12]);
        $newUser = createUser($pdo, null, $email, $role, $name, $city, $hash);
        // No unique index on e-mail is guaranteed: if a concurrent request created the same address first, keep only the first.
        $first = $pdo->prepare("SELECT MIN(id) FROM users WHERE LOWER(email)=?");
        $first->execute([$email]);
        if ((int)$first->fetchColumn() !== (int)$newUser["id"]) {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$newUser["id"]]);
            genericAccountReply();
        }
        sendVerificationEmail($pdo, $newUser);
        genericAccountReply();
    }

    // ── Email: confirm the address (link from the verification e-mail) ──────────
    case "verify_email": {
        countOnly($pdo, "tok_ip", clientIp(), 30, 900);
        $uid = consumeAuthToken($pdo, is_string($input["token"] ?? null) ? trim($input["token"]) : "", "verify_email");
        if ($uid === null) respondError("This link is invalid or has expired. Request a new one.", 400);
        $pdo->prepare("UPDATE users SET email_verified_at=COALESCE(email_verified_at, ?) WHERE id=?")->execute([date("Y-m-d H:i:s"), $uid]);
        if ($u = reloadUser($pdo, $uid)) onEmailVerified($pdo, $u);
        respond(["success" => true, "message" => "Email confirmed. You can now sign in."]);
    }

    case "resend_verification": {
        $email = normalizeEmail($input["email"] ?? "");
        countOnly($pdo, "reg_ip", clientIp(), 10, 3600);
        if ($email) {
            countOnly($pdo, "mail_acct", hash("sha256", $email), 5, 3600);
            $s = $pdo->prepare("SELECT * FROM users WHERE LOWER(email)=? AND role != 'admin' ORDER BY id LIMIT 1");
            $s->execute([$email]);
            $row = $s->fetch();
            if ($row && array_key_exists("email_verified_at", $row) && empty($row["email_verified_at"])) sendVerificationEmail($pdo, $row);
        }
        genericAccountReply();
    }

    // ── Password reset ────────────────────────────────────────────────────────
    case "forgot_password": {
        $email = normalizeEmail($input["email"] ?? "");
        countOnly($pdo, "reg_ip", clientIp(), 10, 3600);
        if ($email) {
            countOnly($pdo, "mail_acct", hash("sha256", $email), 5, 3600);
            $s = $pdo->prepare("SELECT * FROM users WHERE LOWER(email)=? AND role != 'admin' ORDER BY id LIMIT 1");
            $s->execute([$email]);
            if ($row = $s->fetch()) sendResetEmail($pdo, $row);
        }
        genericAccountReply();                                              // same reply for unknown, admin and known addresses
    }

    case "reset_password": {
        countOnly($pdo, "tok_ip", clientIp(), 30, 900);
        $password = is_string($input["password"] ?? null) ? $input["password"] : "";
        $confirm  = is_string($input["confirm"]  ?? null) ? $input["confirm"]  : "";
        if ($e = passwordProblem($password)) respondError($e);              // checked BEFORE the token is spent
        if ($password !== $confirm) respondError("Passwords do not match");
        $uid = consumeAuthToken($pdo, is_string($input["token"] ?? null) ? trim($input["token"]) : "", "password_reset");
        if ($uid === null) respondError("This link is invalid or has expired. Request a new one.", 400);
        $row = reloadUser($pdo, $uid);
        if (!$row || $row["role"] === "admin") respondError("This link is invalid or has expired. Request a new one.", 400);
        $now = date("Y-m-d H:i:s");
        // Possession of the inbox proves the address; every session issued before this moment stops working.
        $pdo->prepare("UPDATE users SET password_hash=?, email_verified_at=COALESCE(email_verified_at, ?), tokens_valid_after=? WHERE id=?")
            ->execute([password_hash($password, PASSWORD_BCRYPT, ["cost" => 12]), $now, $now, $uid]);
        if (empty($row["email_verified_at"])) onEmailVerified($pdo, reloadUser($pdo, $uid) ?: $row);
        respond(["success" => true, "message" => "Password updated. Sign in with your new password."]);
    }

    // ── Email: login ──────────────────────────────────────────────────────────
    case "login_email": {
        $email      = normalizeEmail($input["email"] ?? "");
        $password   = is_string($input["password"] ?? null) ? $input["password"] : "";
        $rememberMe = !empty($input["remember_me"]);
        if (!$email || $password === "") respondError("Email and password required");

        // Count the attempt first (per account and per IP); a correct password forgives this account's failures.
        $guard = guardAttempt($pdo, "login", hash("sha256", $email), LOGIN_MAX_FAILS, LOGIN_WINDOW, LOGIN_IP_MAX_FAILS);
        $s = $pdo->prepare("SELECT * FROM users WHERE LOWER(email)=? AND role != 'admin' ORDER BY id LIMIT 1");
        $s->execute([$email]);
        $loginUser = $s->fetch();
        $hash = $loginUser["password_hash"] ?? "";
        $ok   = password_verify($password, $hash ?: TIMING_HASH) && $loginUser && $hash;      // always one bcrypt verification
        if (!$ok) respondError("Invalid email or password", 401);
        forgiveAttempt($pdo, $guard);

        if (!array_key_exists("email_verified_at", $loginUser)) {                          // migration 009 not applied: fail closed
            error_log("BOOKKAM: users.email_verified_at is missing, apply migrations/009_email_auth.sql");
            respondError("Service temporarily unavailable. Please try again shortly.", 503);
        }
        if (empty($loginUser["email_verified_at"])) {
            http_response_code(403);
            echo json_encode(["error" => "Please confirm your email address first. Check your inbox for the link.", "code" => "email_unverified"]);
            exit;
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
            CURLOPT_URL            => GOOGLE_TOKENINFO_URL . "?id_token=" . urlencode($idToken),
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

        $emailVerified = filter_var($payload["email_verified"] ?? false, FILTER_VALIDATE_BOOLEAN);
        $email = ($emailVerified && $email) ? (normalizeEmail($email) ?: null) : null;     // an unverified Google e-mail is never stored or linked
        // $email is non-null only when Google verified it. A row matching the Google subject id always wins over an e-mail match.
        if ($email) {
            $s = $pdo->prepare("SELECT * FROM users WHERE google_id=? OR LOWER(email)=? ORDER BY CASE WHEN google_id=? THEN 0 ELSE 1 END, id LIMIT 1");
            $s->execute([$googleId, $email, $googleId]);
        } else {
            $s = $pdo->prepare("SELECT * FROM users WHERE google_id=? LIMIT 1");
            $s->execute([$googleId]);
        }
        $googleUser = $s->fetch();

        if ($googleUser) {
            if ($googleUser["role"] === "admin") respondError("Administrators must sign in with their password", 403);   // never via a social login
            if (!$googleUser["google_id"]) {
                $pdo->prepare("UPDATE users SET google_id=? WHERE id=?")->execute([$googleId, $googleUser["id"]]);
            }
            if ($email && array_key_exists("email_verified_at", $googleUser) && empty($googleUser["email_verified_at"]) && strtolower((string)$googleUser["email"]) === $email) {
                // Someone registered this address with a password but never proved they own it; Google just proved the real
                // owner does. Drop that password and every session so the pre-registrant cannot get in.
                $now = date("Y-m-d H:i:s");
                $pdo->prepare("UPDATE users SET password_hash=NULL, email_verified_at=?, tokens_valid_after=? WHERE id=?")->execute([$now, $now, $googleUser["id"]]);
            }
            if ($photo && !$googleUser["photo_url"]) {
                $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$photo, $googleUser["id"]]);
            }
            if (!empty($googleUser["is_blacklisted"])) respondError("Your account has been suspended. Contact support.");
            respond(buildUserResponse($pdo, $googleUser, $rememberMe));
        }

        // New user — need city
        if (!$city) respond(["needs_city" => true, "email" => $email, "name" => $name, "photo" => $photo, "role" => $role,
            "pending_token" => generateSocialPendingToken("google", $googleId, $email, $name, $photo, $role)]);

        $newUser = createUser($pdo, null, $email, $role, $name, $city, null, $googleId);
        if ($email) $pdo->prepare("UPDATE users SET email_verified_at=? WHERE id=?")->execute([date("Y-m-d H:i:s"), $newUser["id"]]);
        if ($photo) {
            $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$photo, $newUser["id"]]);
            $newUser["photo_url"] = $photo;
        }
        if ($role === "customer" && grantWelcomeCredit($pdo, (int)$newUser["id"], "google", (string)$googleId)) {
            $newUser = reloadUser($pdo, (int)$newUser["id"]) ?: $newUser;
        }
        respond(buildUserResponse($pdo, $newUser, $rememberMe));
    }

    // ── Apple login ───────────────────────────────────────────────────────────
    case "apple_login": {
        // The Apple identity token's signature is NOT verified here (no JWKS check), so anyone could
        // forge a token and take over any account that shares an e-mail. Disabled outside development
        // until real verification (Apple JWKS, issuer, audience, expiry) is implemented and tested.
        if (!TEST_MODE) respondError("Sign in with Apple is temporarily unavailable. Please use another sign-in method.", 503);
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

        if (!$city) respond(["needs_city" => true, "email" => $email, "name" => $appleName, "role" => $role,
            "pending_token" => generateSocialPendingToken("apple", $appleId, $email, $appleName, null, $role)]);

        $newUser = createUser($pdo, null, $email, $role, $appleName ?: null, $city, null, null, $appleId);
        respond(buildUserResponse($pdo, $newUser, $rememberMe));
    }

    // ── Set city after Google/Apple first login ────────────────────────────────
    case "set_city": {
        // Only a server-issued pending token (from a verified google_login/apple_login) may create
        // an account here. Provider id, e-mail, name, photo and role all come from that token;
        // anything the browser sends besides the city is ignored. Admin can never be created here.
        $city       = trim((string)($input["city"] ?? ""));
        $rememberMe = !empty($input["remember_me"]);
        if ($city === "" || mb_strlen($city) > 60) respondError("City required");
        $tok = verifyJWT((string)($input["pending_token"] ?? ""));
        if (!$tok || ($tok["typ"] ?? "") !== "social_pending" || !in_array($tok["provider"] ?? "", ["google", "apple"], true)
            || !in_array($tok["role"] ?? "", ["customer", "driver"], true) || ($tok["sub"] ?? "") === "") {
            respondError("Sign-in session expired. Please sign in again.", 401);
        }
        $col = $tok["provider"] === "google" ? "google_id" : "apple_id";
        $s = $pdo->prepare("SELECT * FROM users WHERE $col=?");
        $s->execute([$tok["sub"]]);
        $existing = $s->fetch();
        if ($existing) {
            if (!empty($existing["is_blacklisted"])) respondError("Your account has been suspended. Contact support.");
            $pdo->prepare("UPDATE users SET city=? WHERE id=?")->execute([$city, $existing["id"]]);
            $existing["city"] = $city;
            respond(buildUserResponse($pdo, $existing, $rememberMe));
        }
        try {
            $newUser = $tok["provider"] === "google"
                ? createUser($pdo, null, $tok["email"] ?: null, $tok["role"], $tok["name"] ?: null, $city, null, $tok["sub"])
                : createUser($pdo, null, $tok["email"] ?: null, $tok["role"], $tok["name"] ?: null, $city, null, null, $tok["sub"]);
        } catch (Throwable $e) {
            error_log("BOOKKAM set_city create failed: " . $e->getMessage());
            respondError("Could not create account", 409);
        }
        if (!empty($tok["photo"])) $pdo->prepare("UPDATE users SET photo_url=? WHERE id=?")->execute([$tok["photo"], $newUser["id"]]);
        if ($tok["provider"] === "google" && !empty($tok["email"])) $pdo->prepare("UPDATE users SET email_verified_at=? WHERE id=?")->execute([date("Y-m-d H:i:s"), $newUser["id"]]);
        if ($tok["provider"] === "google" && $tok["role"] === "customer"
            && grantWelcomeCredit($pdo, (int)$newUser["id"], "google", (string)$tok["sub"])) {
            $newUser = reloadUser($pdo, (int)$newUser["id"]) ?: $newUser;
        }
        respond(buildUserResponse($pdo, $newUser, $rememberMe));
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
        $email = normalizeEmail($email);
        if (!$email) respondError("Invalid credentials", 401);
        $guard = guardAttempt($pdo, "adminlogin", hash("sha256", $email), 5, LOGIN_WINDOW, 20);
        $s = $pdo->prepare("SELECT * FROM users WHERE LOWER(email)=? AND role='admin' LIMIT 1");
        $s->execute([$email]);
        $adminUser = $s->fetch();
        $hash = $adminUser["password_hash"] ?? "";
        if (!(password_verify($password, $hash ?: TIMING_HASH) && $adminUser && $hash)) {
            respondError("Invalid credentials", 401);
        }
        // Second factor. The password step alone never yields a session for an enrolled admin.
        $enrolled = array_key_exists("admin_totp_enabled", $adminUser) && (int)$adminUser["admin_totp_enabled"] === 1;
        if ($enrolled || ADMIN_2FA_REQUIRED) {
            // Give back the attempt this correct password reserved: only wrong passwords and wrong codes count towards the lockout.
            throttleRefund($pdo, $guard[0] . "_acct", $guard[1]); throttleRefund($pdo, $guard[0] . "_ip", $guard[2]);
            if ($enrolled) respond(["two_factor_required" => true, "challenge" => Totp::challenge((int)$adminUser["id"], "login2fa")]);
            respond(["needs_2fa_setup" => true, "challenge" => Totp::challenge((int)$adminUser["id"], "setup", 600)]);
        }
        forgiveAttempt($pdo, $guard);
        adminSessionReply($adminUser);
    }

    // ── Admin second factor: sign-in step 2 (authenticator code or one-time recovery code) ──────────────────
    case "admin_login_2fa": {
        $aid = Totp::readChallenge((string)($input["challenge"] ?? ""), "login2fa");
        if (!$aid) respondError("Sign-in expired. Please start again.", 401);
        $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='admin'"); $s->execute([$aid]); $adm = $s->fetch();
        if (!$adm || (int)($adm["admin_totp_enabled"] ?? 0) !== 1) respondError("Sign-in expired. Please start again.", 401);
        $guard = guardAttempt($pdo, "adminlogin", hash("sha256", normalizeEmail($adm["email"])), 5, LOGIN_WINDOW, 20);   // same lockout as the password step
        $code = is_string($input["code"] ?? null) ? $input["code"] : "";
        $rec  = is_string($input["recovery_code"] ?? null) ? trim($input["recovery_code"]) : "";
        $ok = false;
        if ($code !== "") {
            $secret = Totp::open($adm["admin_totp_secret"]);
            $step = $secret ? Totp::verify($secret, $code, isset($adm["admin_totp_last_step"]) ? (int)$adm["admin_totp_last_step"] : null) : null;
            if ($step !== null) {      // atomic: a code (step) can be accepted once
                $c = $pdo->prepare("UPDATE users SET admin_totp_last_step=? WHERE id=? AND (admin_totp_last_step IS NULL OR admin_totp_last_step < ?)");
                $c->execute([$step, $aid, $step]); $ok = $c->rowCount() === 1;
            }
        } elseif ($rec !== "") {
            $hashes = json_decode((string)($adm["admin_recovery_hashes"] ?? "[]"), true) ?: [];
            $h = Totp::hashCode($rec);
            if (in_array($h, $hashes, true)) {   // compare-and-swap so one recovery code works exactly once
                $left = array_values(array_diff($hashes, [$h]));
                $c = $pdo->prepare("UPDATE users SET admin_recovery_hashes=? WHERE id=? AND admin_recovery_hashes=?");
                $c->execute([json_encode($left), $aid, $adm["admin_recovery_hashes"]]); $ok = $c->rowCount() === 1;
                if ($ok) error_log("BOOKKAM admin #" . $aid . " signed in with a recovery code (" . count($left) . " left)");
            }
        }
        if (!$ok) respondError("Invalid code", 401);
        forgiveAttempt($pdo, $guard);
        adminSessionReply($adm);
    }

    // ── Admin second factor: enrolment. Needs an admin session, or the setup challenge from sign-in when 2FA is required ──
    case "admin_2fa_begin": {
        $adm = adminForTwoFactor($pdo, $user, $input);
        countOnly($pdo, "tok_ip", clientIp(), 30, 900);
        if ((int)($adm["admin_totp_enabled"] ?? 0) === 1) respondError("Two-factor authentication is already on", 409);
        $secret = Totp::generateSecret();
        $pdo->prepare("UPDATE users SET admin_totp_secret=?, admin_totp_enabled=0 WHERE id=?")->execute([Totp::seal($secret), $adm["id"]]);
        respond(["secret" => $secret, "otpauth_uri" => Totp::uri($secret, $adm["email"])]);
    }
    case "admin_2fa_enable": {
        $adm = adminForTwoFactor($pdo, $user, $input);
        $g = guardAttempt($pdo, "admin2fa_setup", (string)$adm["id"], 5, LOGIN_WINDOW, 30);
        if ((int)($adm["admin_totp_enabled"] ?? 0) === 1) respondError("Two-factor authentication is already on", 409);
        $secret = Totp::open($adm["admin_totp_secret"] ?? null);
        $step = $secret ? Totp::verify($secret, (string)($input["code"] ?? ""), null) : null;
        if ($step === null) respondError("That code is not correct. Check the time on your phone and try again.", 400);
        $codes = Totp::newRecoveryCodes();
        $e = $pdo->prepare("UPDATE users SET admin_totp_enabled=1, admin_totp_last_step=?, admin_recovery_hashes=? WHERE id=? AND admin_totp_enabled=0");
        $e->execute([$step, json_encode(array_map([Totp::class, "hashCode"], $codes)), $adm["id"]]);
        if ($e->rowCount() !== 1) respondError("Two-factor authentication is already on", 409);
        forgiveAttempt($pdo, $g);
        error_log("BOOKKAM admin #" . $adm["id"] . " enabled two-factor authentication");
        $out = ["success" => true, "recovery_codes" => $codes];    // shown ONCE; only their hashes are stored
        if (!$user) { $out["token"] = generateJWTExpiry($adm["id"], "admin", 24 * 3600); $out["user"] = ["id" => $adm["id"], "name" => $adm["name"], "email" => $adm["email"], "role" => "admin"]; }
        respond($out);
    }
    case "admin_2fa_disable": {
        if (!$user || $user["role"] !== "admin") respondError("Unauthorised", 401);
        $g = guardAttempt($pdo, "admin2fa_off", (string)$user["id"], 5, LOGIN_WINDOW, 30);
        $pw = is_string($input["password"] ?? null) ? $input["password"] : "";
        if (!$pw || !password_verify($pw, (string)($user["password_hash"] ?? ""))) respondError("Incorrect password", 401);
        $secret = Totp::open($user["admin_totp_secret"] ?? null);
        if (!$secret || Totp::verify($secret, (string)($input["code"] ?? ""), isset($user["admin_totp_last_step"]) ? (int)$user["admin_totp_last_step"] : null) === null) respondError("Incorrect code", 401);
        $pdo->prepare("UPDATE users SET admin_totp_enabled=0, admin_totp_secret=NULL, admin_recovery_hashes=NULL, admin_totp_last_step=NULL WHERE id=?")->execute([$user["id"]]);
        forgiveAttempt($pdo, $g);
        error_log("BOOKKAM admin #" . $user["id"] . " disabled two-factor authentication");
        respond(["success" => true]);
    }
    case "admin_2fa_status": {
        if (!$user || $user["role"] !== "admin") respondError("Unauthorised", 401);
        $left = json_decode((string)($user["admin_recovery_hashes"] ?? "[]"), true) ?: [];
        respond(["enabled" => (int)($user["admin_totp_enabled"] ?? 0) === 1, "required" => ADMIN_2FA_REQUIRED, "recovery_codes_left" => count($left)]);
    }

    // ── Update profile ────────────────────────────────────────────────────────
    case "update_profile": {
        if (!$user) respondError("Unauthorised", 401);
        if (isset($input["email"]) && strtolower(trim((string)$input["email"])) !== strtolower(trim((string)($user["email"] ?? "")))) {
            respondError("Your email address cannot be changed here", 400);   // it is the login identity; changing it needs verification
        }
        $name = array_key_exists("name", $input) ? cleanText($input["name"], 80) : (string)$user["name"];
        $city = array_key_exists("city", $input) ? cleanText($input["city"], 60) : (string)$user["city"];
        if ($name === "") $name = (string)$user["name"];
        if ($city === "") $city = (string)$user["city"];
        $pdo->prepare("UPDATE users SET name=?,city=? WHERE id=?")->execute([$name, $city, $user["id"]]);
        respond(["success" => true, "message" => "Profile updated"]);
    }

    default: respondError("Invalid action");
}
?>

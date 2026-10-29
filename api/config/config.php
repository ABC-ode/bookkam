<?php
// ── Load .env file if present ─────────────────────────────────────────────────
// In production: set real values in .env (never commit it)
// In development: use .env or set directly below as fallback
$envFile = dirname(__DIR__) . "/.env";
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), "#") || !str_contains($line, "=")) continue;
        [$key, $val] = explode("=", $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

function env(string $key, string $default = ""): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ── Mode ──────────────────────────────────────────────────────────────────────
// Fail closed: development/test behaviour (fixed OTP, simulated payments, test wallet
// credits, dev JWT fallback) is enabled ONLY by an explicit allow-listed APP_ENV value.
// A missing, mistyped or unexpected value (e.g. "prod", "staging") is treated as production.
define("TEST_MODE", in_array(strtolower(env("APP_ENV", "production")), ["development", "local", "test", "testing"], true));
define("TEST_OTP",  "123456");

// ── Secrets — loaded from .env ────────────────────────────────────────────────
// JWT: generate your secret with: php -r "echo bin2hex(random_bytes(32));"
$jwtSecret = env("JWT_SECRET");
// A copied .env.example placeholder or a short value is not a secret.
$jwtIsPlaceholder = $jwtSecret !== "" && (stripos($jwtSecret, "REPLACE_WITH") !== false || strlen($jwtSecret) < 32);
if ($jwtIsPlaceholder && !TEST_MODE) {
    http_response_code(500);
    echo json_encode(["error" => "Server misconfiguration: JWT_SECRET is a placeholder or too short"]);
    exit;
}
if (empty($jwtSecret)) {
    if (TEST_MODE) {
        // Dev fallback — safe only because TEST_MODE is on
        $jwtSecret = "bookkam_dev_secret_not_for_production";
    } else {
        // Production with no secret = hard stop
        http_response_code(500);
        echo json_encode(["error" => "Server misconfiguration: JWT_SECRET not set in .env"]);
        exit;
    }
}
define("JWT_SECRET", $jwtSecret);
define("GOOGLE_CLIENT_ID", env("GOOGLE_CLIENT_ID"));
// Termii
define("TERMII_API_KEY",   env("TERMII_API_KEY"));
define("TERMII_SENDER_ID", env("TERMII_SENDER_ID", "BOOKKAM"));

// OPay remains disabled unless explicitly enabled and the server credentials exist.
// The checkout integration still requires validation against current official OPay docs;
// credentials alone do not mean it is production-ready.
define("OPAY_PUBLIC_KEY", env("OPAY_PUBLIC_KEY"));
define("OPAY_SECRET_KEY", env("OPAY_SECRET_KEY"));
define("OPAY_MERCHANT_ID", env("OPAY_MERCHANT_ID"));
define("OPAY_ENABLED", env("OPAY_ENABLED", "false") === "true"
    && OPAY_SECRET_KEY !== "" && OPAY_MERCHANT_ID !== "");

// Twilio WhatsApp
define("TWILIO_SID",               env("TWILIO_SID"));
define("TWILIO_TOKEN",             env("TWILIO_TOKEN"));
define("TWILIO_WHATSAPP_FROM",     env("TWILIO_WHATSAPP_FROM", "whatsapp:+14155238886"));
define("BOOKKAM_WHATSAPP_SUPPORT", env("BOOKKAM_WHATSAPP_SUPPORT", "2349164812347"));

// Cloudinary
define("CLOUDINARY_CLOUD",         env("CLOUDINARY_CLOUD"));
define("CLOUDINARY_API_KEY",       env("CLOUDINARY_API_KEY"));
define("CLOUDINARY_API_SECRET",    env("CLOUDINARY_API_SECRET"));
define("CLOUDINARY_UPLOAD_PRESET", env("CLOUDINARY_UPLOAD_PRESET", "bookkam_uploads"));

// App constants (not sensitive — fine to keep here)
define("PLATFORM_COMMISSION", 0.20);
define("UPLOAD_DIR",          dirname(__DIR__) . "/uploads/");
define("UPLOAD_URL",          "/uploads/");
define("INACTIVITY_TIMEOUT",  3600);

// OTP rate limiting constants
define("OTP_MAX_ATTEMPTS",  5);   // max OTPs per window
define("OTP_WINDOW_SECS",   600); // 10-minute window
// OTP *verification* brute-force limits (failed guesses). Tunable from .env; defaults are deliberately small.
define("OTP_VERIFY_MAX_FAILS",    max(1,  (int)env("OTP_VERIFY_MAX_FAILS", "5")));      // guesses per account per window
define("OTP_VERIFY_WINDOW",       max(60, (int)env("OTP_VERIFY_WINDOW", "900")));       // lockout window (seconds)
define("OTP_VERIFY_IP_MAX_FAILS", max(1,  (int)env("OTP_VERIFY_IP_MAX_FAILS", "30")));  // guesses per IP per window
define("OTP_VERIFY_IP_WINDOW",    max(60, (int)env("OTP_VERIFY_IP_WINDOW", "900")));
// One-time welcome credit for a customer whose phone/Google identity has been verified. Set WELCOME_CREDIT_ENABLED=0 in .env to switch it off.
// (parsed explicitly: env() treats the string "0" as empty and would fall back to the default)
$wcFlag = strtolower(trim((string)($_ENV["WELCOME_CREDIT_ENABLED"] ?? getenv("WELCOME_CREDIT_ENABLED"))));
define("WELCOME_CREDIT_ENABLED", !in_array($wcFlag, ["0", "false", "off", "no", "disabled"], true));
define("WELCOME_CREDIT_AMOUNT",  1000);

// ── WhatsApp notification helper ──────────────────────────────────────────────
function sendWhatsApp($to, $message) {
    if (TEST_MODE || !TWILIO_SID || !TWILIO_TOKEN) {
        error_log("WhatsApp [TEST] to $to: $message");
        return true;
    }
    $url  = "https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Messages.json";
    $data = [
        "From" => TWILIO_WHATSAPP_FROM,
        "To"   => "whatsapp:+$to",
        "Body" => $message,
    ];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_USERPWD        => TWILIO_SID . ":" . TWILIO_TOKEN,
    ]);
    curl_exec($curl);
    curl_close($curl);
    return true;
}

// ── JWT helpers ───────────────────────────────────────────────────────────────
function generateJWT($userId, $role) {
    $h = base64_encode(json_encode(["alg" => "HS256", "typ" => "JWT"]));
    $p = base64_encode(json_encode([
        "user_id" => $userId,
        "role"    => $role,
        "exp"     => time() + (30 * 24 * 3600),
        "iat"     => time(),
    ]));
    $s = rtrim(strtr(base64_encode(hash_hmac("sha256", "$h.$p", JWT_SECRET, true)), '+/', '-_'), '=');
    return "$h.$p.$s";
}

function verifyJWT($token) {
    $parts = explode(".", $token);
    if (count($parts) !== 3) return false;
    [$h, $p, $s] = $parts;
    $expected = rtrim(strtr(base64_encode(hash_hmac("sha256", "$h.$p", JWT_SECRET, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $s)) return false;
    $data = json_decode(base64_decode($p), true);
    if (!$data || !isset($data["exp"]) || $data["exp"] < time()) return false;
    return $data;
}

// Short-lived token proving that a Google/Apple identity was verified server-side and only the
// city is still missing. set_city accepts nothing else, so the browser cannot choose the
// provider id, e-mail or role. It has no user_id and is never accepted as a login token.
function generateSocialPendingToken($provider, $sub, $email, $name, $photo, $role) {
    $h = base64_encode(json_encode(["alg" => "HS256", "typ" => "JWT"]));
    $p = base64_encode(json_encode([
        "typ" => "social_pending", "provider" => $provider, "sub" => (string)$sub,
        "email" => $email, "name" => $name, "photo" => $photo, "role" => $role,
        "exp" => time() + 900, "iat" => time(),
    ]));
    $s = rtrim(strtr(base64_encode(hash_hmac("sha256", "$h.$p", JWT_SECRET, true)), '+/', '-_'), '=');
    return "$h.$p.$s";
}

function getAuthUser($pdo) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth    = $headers["Authorization"] ?? $headers["authorization"] ?? "";
    if (!str_starts_with($auth, "Bearer ")) return null;
    $payload = verifyJWT(substr($auth, 7));
    if (!$payload || !isset($payload["user_id"]) || isset($payload["typ"])) return null;
    $s = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $s->execute([$payload["user_id"]]);
    return $s->fetch() ?: null;
}

function respond($data, $code = 200)     { http_response_code($code); echo json_encode($data); exit; }
function respondError($msg, $code = 400) { http_response_code($code); echo json_encode(["error" => $msg]); exit; }

function apiHeaders() {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
}
?>

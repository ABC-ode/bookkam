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
define("TEST_MODE", env("APP_ENV", "development") !== "production");
define("TEST_OTP",  "123456");

// ── Secrets — loaded from .env ────────────────────────────────────────────────
// JWT: generate your secret with: php -r "echo bin2hex(random_bytes(32));"
$jwtSecret = env("JWT_SECRET");
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

// Paystack
define("PAYSTACK_SECRET", env("PAYSTACK_SECRET"));
define("PAYSTACK_PUBLIC", env("PAYSTACK_PUBLIC"));

// Twilio WhatsApp
define("TWILIO_SID",               env("TWILIO_SID"));
define("TWILIO_TOKEN",             env("TWILIO_TOKEN"));
define("TWILIO_WHATSAPP_FROM",     env("TWILIO_WHATSAPP_FROM", "whatsapp:+14155238886"));
define("BOOKKAM_WHATSAPP_SUPPORT", env("BOOKKAM_WHATSAPP_SUPPORT", "2348000000000"));

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

function getAuthUser($pdo) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth    = $headers["Authorization"] ?? $headers["authorization"] ?? "";
    if (!str_starts_with($auth, "Bearer ")) return null;
    $payload = verifyJWT(substr($auth, 7));
    if (!$payload) return null;
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

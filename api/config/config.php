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
// Token verification endpoint. Overridable ONLY in test environments (so tests can stub Google); production always uses Google.
define("GOOGLE_TOKENINFO_URL", (TEST_MODE && env("GOOGLE_TOKENINFO_URL") !== "") ? env("GOOGLE_TOKENINFO_URL") : "https://oauth2.googleapis.com/tokeninfo");
// OPay (Nigeria, NGN). OFF unless OPAY_ENABLED=true AND the public key, the secret key and the merchant id are all set.
// Credentials alone do not make it production-ready: complete one real sandbox payment, callback and refund first.
// OPAY_ENV: "sandbox" (default, https://testapi.opaycheckout.com) or "production" (https://liveapi.opaycheckout.com).
// OPAY_BASE_URL (loopback only) and OPAY_CASHIER_HOSTS=127.0.0.1 let the test-suite use a fake local server; they are
// ignored unless APP_ENV is a development value, and never apply in production. OPAY_CASHIER_HOSTS may also hold extra
// trusted cashier host suffixes (comma separated) if OPay's cashier is served from a host other than *.opaycheckout.com.
define("OPAY_PUBLIC_KEY", env("OPAY_PUBLIC_KEY"));
define("OPAY_SECRET_KEY", env("OPAY_SECRET_KEY"));
define("OPAY_MERCHANT_ID", env("OPAY_MERCHANT_ID"));
define("OPAY_ENV", strtolower(env("OPAY_ENV", "sandbox")) === "production" ? "production" : "sandbox");
define("OPAY_BASE_URL", (OPAY_ENV !== "production" && TEST_MODE) ? rtrim(env("OPAY_BASE_URL"), "/") : "");
define("OPAY_CASHIER_HOSTS", env("OPAY_CASHIER_HOSTS"));
define("OPAY_ENABLED", env("OPAY_ENABLED", "false") === "true"
    && OPAY_PUBLIC_KEY !== "" && OPAY_SECRET_KEY !== "" && OPAY_MERCHANT_ID !== "");

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

// -- E-mail and account security (phone OTP login has been removed) --------------------------
// Links in e-mails are built from APP_URL (e.g. https://bookkam.com). Without it nothing is sent.
define("APP_URL", rtrim(env("APP_URL"), "/"));
define("MAIL_TRANSPORT", strtolower(env("MAIL_TRANSPORT", "mail")));      // smtp | mail | file (file: test environments only)
define("MAIL_FROM", env("MAIL_FROM"));
define("MAIL_FROM_NAME", env("MAIL_FROM_NAME", "BOOKKAM"));
define("MAIL_OUTBOX_DIR", env("MAIL_OUTBOX_DIR"));
define("SMTP_HOST", env("SMTP_HOST"));
define("SMTP_PORT", (int)env("SMTP_PORT", "587"));
define("SMTP_USER", env("SMTP_USER"));
define("SMTP_PASS", env("SMTP_PASS"));
define("SMTP_ENCRYPTION", strtolower(env("SMTP_ENCRYPTION", "tls")));      // tls | ssl | none (none: localhost only)
define("EMAIL_VERIFY_TTL", 86400);          // verification link lifetime: 24 h
define("PASSWORD_RESET_TTL", 3600);         // reset link lifetime: 1 h
// Failed-login limits (per account and per IP), fixed window in seconds. Tunable from .env.
define("LOGIN_MAX_FAILS",    max(1,  (int)env("LOGIN_MAX_FAILS", "8")));
define("LOGIN_IP_MAX_FAILS", max(1,  (int)env("LOGIN_IP_MAX_FAILS", "40")));
define("LOGIN_WINDOW",       max(60, (int)env("LOGIN_WINDOW", "900")));
// Admin second factor. When ADMIN_2FA_REQUIRED=1 an admin who has NOT enrolled an authenticator cannot sign in with a
// password alone: they are walked through enrolment first. Leave it 0 until every admin has enrolled (see docs/admin-2fa.md).
// An admin who HAS enrolled always needs the second factor, whatever this flag says.
$a2 = strtolower(trim((string)($_ENV["ADMIN_2FA_REQUIRED"] ?? getenv("ADMIN_2FA_REQUIRED"))));
define("ADMIN_2FA_REQUIRED", in_array($a2, ["1", "true", "on", "yes"], true));
// Mapbox stays OFF until MAPBOX_ENABLED=1. Only a PUBLIC token (pk.*) is ever sent to browsers; secret (sk.*) tokens are refused.
$mb = strtolower(trim((string)($_ENV["MAPBOX_ENABLED"] ?? getenv("MAPBOX_ENABLED"))));
$mbTok = trim((string)env("MAPBOX_PUBLIC_TOKEN"));
define("MAPBOX_ENABLED", in_array($mb, ["1", "true", "on", "yes"], true) && str_starts_with($mbTok, "pk."));
define("MAPBOX_PUBLIC_TOKEN", MAPBOX_ENABLED ? $mbTok : "");
// One-time welcome credit for a customer whose e-mail (via a single-use link) or Google identity is verified.
// OFF unless WELCOME_CREDIT_ENABLED=1: an e-mail address costs nothing, so enabling it accepts that farming risk.
// (parsed explicitly: env() treats the string "0" as empty and would fall back to the default)
$wcFlag = strtolower(trim((string)($_ENV["WELCOME_CREDIT_ENABLED"] ?? getenv("WELCOME_CREDIT_ENABLED"))));
define("WELCOME_CREDIT_ENABLED", in_array($wcFlag, ["1", "true", "on", "yes", "enabled"], true));
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
    $u = $s->fetch();
    if (!$u) return null;
    if (!empty($u["is_blacklisted"]) && ($u["role"] ?? "") !== "admin") return null;   // suspended: existing sessions stop working too
    // A password reset (or the Google claim of an unconfirmed pre-registration) invalidates every earlier session,
    // including one issued in the same second as the reset (<=). A sign-in within that same second must be repeated.
    if (!empty($u["tokens_valid_after"]) && (int)($payload["iat"] ?? 0) <= strtotime((string)$u["tokens_valid_after"])) return null;
    return $u;
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

require_once __DIR__ . "/settings.php";
require_once __DIR__ . "/security.php";      // shared helpers (cleanText, normalizeEmail, throttling) for every endpoint
?>

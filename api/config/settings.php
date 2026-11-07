<?php
// Admin-controlled ON/OFF switches stored in app_settings (migration 010).
//   bookings_enabled : accepting NEW bookings (and starting payments); existing trips are never affected.
//   payments_enabled : starting NEW payments / wallet top-ups only; verification, reconciliation and refunds are never affected.
// A missing table or row means "on" (the pre-switch behaviour), so deploying the code before the migration cannot stop the business.
const APP_SETTING_KEYS = ["bookings_enabled", "payments_enabled"];

function appSettingOn(PDO $pdo, string $key): bool {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $s = $pdo->prepare("SELECT v FROM app_settings WHERE k=?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $cache[$key] = ($v === false) ? true : ((string)$v !== "0");
    } catch (Throwable $e) {
        error_log("BOOKKAM app_settings unavailable, treating '$key' as ON (apply migrations/010): " . $e->getMessage());
        return true;
    }
}
function setAppSetting(PDO $pdo, string $key, bool $on, ?int $adminId): void {
    if (!in_array($key, APP_SETTING_KEYS, true)) throw new InvalidArgumentException("unknown setting");
    $now = date("Y-m-d H:i:s");
    $u = $pdo->prepare("UPDATE app_settings SET v=?, updated_at=?, updated_by=? WHERE k=?");
    $u->execute([$on ? "1" : "0", $now, $adminId, $key]);
    if ($u->rowCount() === 0) {                                    // row missing (or unchanged value on MySQL): make sure it exists
        $e = $pdo->prepare("SELECT COUNT(*) FROM app_settings WHERE k=?"); $e->execute([$key]);
        if ((int)$e->fetchColumn() === 0) $pdo->prepare("INSERT INTO app_settings (k,v,updated_at,updated_by) VALUES (?,?,?,?)")->execute([$key, $on ? "1" : "0", $now, $adminId]);
    }
}
// Responds 503 and stops when new bookings are switched off.
function requireBookingsOpen(PDO $pdo): void {
    if (!appSettingOn($pdo, "bookings_enabled")) { http_response_code(503); echo json_encode(["error" => "BOOKKAM is not taking new bookings right now. Please try again later.", "code" => "bookings_paused"]); exit; }
}
// New payments are refused when EITHER the payment switch or the application-wide switch is off.
function requirePaymentsOpen(PDO $pdo): void {
    if (!appSettingOn($pdo, "payments_enabled")) { http_response_code(503); echo json_encode(["error" => "Payments are temporarily unavailable. Please try again later.", "code" => "payments_paused"]); exit; }
    if (!appSettingOn($pdo, "bookings_enabled")) { http_response_code(503); echo json_encode(["error" => "BOOKKAM is not taking new bookings or payments right now. Please try again later.", "code" => "bookings_paused"]); exit; }
}

<?php
// Shared security helpers for auth.php: attempt throttling, single-use e-mail tokens and the one-time welcome credit.
// Requires config.php (respondError, constants). Statements are portable across MySQL/MariaDB and the
// SQLite stand-in used by tests/functional; all timestamps come from PHP so every node agrees.

// -- Throttle storage ------------------------------------------------------------------
// Normally created by migrations/008_otp_throttle_and_welcome_claims.sql. The table is also created lazily
// so a missed migration cannot disable the protection; if it can be neither found nor created the caller
// must fail CLOSED (throttleReserve() returns null).
function ensureThrottleTable(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql";
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_throttle (
            bucket VARCHAR(40) NOT NULL, k VARCHAR(190) NOT NULL, hits INT NOT NULL DEFAULT 0,
            window_start DATETIME NOT NULL, PRIMARY KEY (bucket, k))" . ($mysql ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : ""));
        $ready = true;
    } catch (Throwable $e) {
        error_log("BOOKKAM auth_throttle unavailable (apply migrations/008_otp_throttle_and_welcome_claims.sql): " . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

// REMOTE_ADDR only. X-Forwarded-For is client-controlled and must never feed a rate limit; if the site is ever put
// behind a trusted proxy/CDN, map the proxy's real-client header here deliberately.
function clientIp(): string {
    return substr((string)($_SERVER["REMOTE_ADDR"] ?? "0.0.0.0"), 0, 45);
}

// Atomically count one attempt for (bucket,key) and return the new total inside the current fixed window
// (the window restarts only once it has fully elapsed). The count is taken BEFORE the guarded check runs, so N
// concurrent attempts receive N distinct, increasing numbers and at most $max of them can ever pass the check.
// Returns null when the storage is unusable (caller must fail closed).
function throttleReserve(PDO $pdo, string $bucket, string $key, int $window): ?int {
    if (!ensureThrottleTable($pdo)) return null;
    $k = substr($key, 0, 190);
    $ignore = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql" ? "INSERT IGNORE" : "INSERT OR IGNORE";
    for ($try = 0; $try < 5; $try++) {
        $own = !$pdo->inTransaction();
        try {
            $now = date("Y-m-d H:i:s"); $cut = date("Y-m-d H:i:s", time() - $window);
            // Make sure the row exists in its OWN autocommit statement (hits=0). Creating it inside the counting
            // transaction lets two first-time requests deadlock, and InnoDB then rolls the loser back silently.
            $pdo->prepare("$ignore INTO auth_throttle (bucket,k,hits,window_start) VALUES (?,?,0,?)")->execute([$bucket, $k, $now]);
            if ($own) $pdo->beginTransaction();
            // One single-row UPDATE takes the row lock, so concurrent attempts are strictly serialised and each
            // reads back its own, unique number.
            $upd = $pdo->prepare("UPDATE auth_throttle
                SET hits = CASE WHEN window_start <= ? THEN 1 ELSE hits + 1 END,
                    window_start = CASE WHEN window_start <= ? THEN ? ELSE window_start END
                WHERE bucket = ? AND k = ?");
            $upd->execute([$cut, $cut, $now, $bucket, $k]);
            if ($upd->rowCount() === 0) {          // row vanished (a successful login just cleared it): seed again
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                continue;
            }
            $sel = $pdo->prepare("SELECT hits FROM auth_throttle WHERE bucket = ? AND k = ?");
            $sel->execute([$bucket, $k]);
            $n = $sel->fetchColumn();
            $sel->closeCursor();
            if ($own) $pdo->commit();
            return $n === false ? null : (int)$n;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $x) {} }
            usleep(20000 * ($try + 1));          // lock timeout: back off and retry (a rolled-back try counted nothing)
            $last = $e;
        }
    }
    error_log("BOOKKAM throttleReserve failed: " . (isset($last) ? $last->getMessage() : "row kept vanishing"));
    return null;
}

// Forget a bucket entirely (used only after a CORRECT OTP: a failed guess never calls this).
function throttleRelease(PDO $pdo, string $bucket, string $key): void {
    try { $pdo->prepare("DELETE FROM auth_throttle WHERE bucket = ? AND k = ?")->execute([$bucket, substr($key, 0, 190)]); }
    catch (Throwable $e) { error_log("BOOKKAM throttleRelease: " . $e->getMessage()); }
}

// Give back the single attempt reserved by a request that turned out to be successful (never below zero).
function throttleRefund(PDO $pdo, string $bucket, string $key): void {
    try {
        $pdo->prepare("UPDATE auth_throttle SET hits = CASE WHEN hits > 0 THEN hits - 1 ELSE 0 END WHERE bucket = ? AND k = ?")
            ->execute([$bucket, substr($key, 0, 190)]);
    } catch (Throwable $e) { error_log("BOOKKAM throttleRefund: " . $e->getMessage()); }
}

function respondTooManyAttempts(int $retryAfter): void {
    header("Retry-After: " . max(1, $retryAfter));
    respondError("Too many attempts. Please try again later.", 429);
}

// -- Attempt guard (login, admin login) ---------------------------------------------------
// Reserve one attempt per account AND per IP before anything is compared (same atomic counter as above).
// Responds 429/503 itself when the limits are exceeded; otherwise returns the keys needed by forgiveAttempt().
function guardAttempt(PDO $pdo, string $bucket, string $accountKey, int $max, int $window, int $ipMax): array {
    $ip = clientIp();
    $a  = throttleReserve($pdo, $bucket . "_acct", $accountKey, $window);
    $i  = throttleReserve($pdo, $bucket . "_ip", $ip, $window);
    if ($a === null || $i === null) respondError("Service temporarily unavailable. Please try again shortly.", 503);   // fail closed
    if ($a > $max || $i > $ipMax) respondTooManyAttempts($window);
    return [$bucket, $accountKey, $ip];
}
// After a SUCCESSFUL check: the account's failures are forgiven and the IP gets its reserved attempt back.
function forgiveAttempt(PDO $pdo, array $guard): void {
    [$bucket, $accountKey, $ip] = $guard;
    throttleRelease($pdo, $bucket . "_acct", $accountKey);
    throttleRefund($pdo, $bucket . "_ip", $ip);
}
// Count an attempt that may never be forgiven (registration / reset-request / resend floods). Responds 429 when over.
function countOnly(PDO $pdo, string $bucket, string $key, int $max, int $window): void {
    $n = throttleReserve($pdo, $bucket, $key, $window);
    if ($n === null) respondError("Service temporarily unavailable. Please try again shortly.", 503);
    if ($n > $max) respondTooManyAttempts($window);
}

// -- Input normalisation ------------------------------------------------------------------
function normalizeEmail($v): string {
    $e = strtolower(trim((string)$v));
    return (strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : "";
}
// Identity key for e-mail: lower-case, "+tag" removed, and dots removed for gmail.com/googlemail.com, so the
// trivial aliases of one inbox collapse to one identity. (Different inboxes still count as different people.)
function canonicalEmail(string $e): string {
    [$local, $domain] = array_pad(explode("@", strtolower($e), 2), 2, "");
    $local = explode("+", $local, 2)[0];
    if ($domain === "googlemail.com") $domain = "gmail.com";
    if ($domain === "gmail.com") $local = str_replace(".", "", $local);
    return $local . "@" . $domain;
}
// Plain text for names/cities: tags and control characters removed, whitespace collapsed, length-capped.
function cleanText($v, int $max): string {
    $t = preg_replace('/[\x00-\x1F\x7F]+/u', " ", strip_tags((string)$v)) ?? "";
    $t = trim(preg_replace('/\s+/u', " ", $t) ?? "");
    return function_exists("mb_substr") ? mb_substr($t, 0, $max) : substr($t, 0, $max);
}
// bcrypt silently ignores everything after 72 bytes, so longer passwords are refused instead of truncated.
function passwordProblem(string $pw, string $email = ""): ?string {
    if (strlen($pw) < 8)  return "Password must be at least 8 characters";
    if (strlen($pw) > 72) return "Password must be at most 72 characters";
    if ($email !== "" && strtolower($pw) === strtolower($email)) return "Password must not be your email address";
    return null;
}

// -- Single-use e-mail tokens (verify e-mail / reset password) ------------------------------
// Only the SHA-256 of a token is stored. The raw 256-bit token exists in the e-mail and nowhere else.
function issueAuthToken(PDO $pdo, int $userId, string $purpose, int $ttlSeconds): string {
    $raw  = bin2hex(random_bytes(32));
    $now  = date("Y-m-d H:i:s");
    $pdo->prepare("UPDATE auth_tokens SET used_at=? WHERE user_id=? AND purpose=? AND used_at IS NULL")->execute([$now, $userId, $purpose]); // supersede older links
    $pdo->prepare("INSERT INTO auth_tokens (user_id,purpose,token_hash,expires_at,created_at) VALUES (?,?,?,?,?)")
        ->execute([$userId, $purpose, hash("sha256", $raw), date("Y-m-d H:i:s", time() + $ttlSeconds), $now]);
    return $raw;
}
// Atomic: of any number of concurrent requests carrying the same token, exactly one gets the user id back.
function consumeAuthToken(PDO $pdo, string $raw, string $purpose): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return null;
    $h = hash("sha256", $raw); $now = date("Y-m-d H:i:s");
    $u = $pdo->prepare("UPDATE auth_tokens SET used_at=? WHERE token_hash=? AND purpose=? AND used_at IS NULL AND expires_at > ?");
    $u->execute([$now, $h, $purpose, $now]);
    if ($u->rowCount() !== 1) return null;
    $s = $pdo->prepare("SELECT user_id FROM auth_tokens WHERE token_hash=?");
    $s->execute([$h]);
    $id = $s->fetchColumn();
    return $id === false ? null : (int)$id;
}

// -- Welcome credit ---------------------------------------------------------------------
// Grants the one-time welcome credit to a CUSTOMER whose identity has just been proven: an e-mail address (the
// owner clicked a single-use link sent to it) or a Google-verified subject id. The durable claim row and the wallet
// credit commit in ONE transaction; UNIQUE(user_id) and UNIQUE(identity_key) make a second credit impossible for the
// same account, or for the same identity on another account, however many requests race. Returns true only when credit
// was added. Fails closed: a missing table (migration not applied) or any error means NO credit.
// NOTE: an e-mail address is cheap to create. Keep WELCOME_CREDIT_ENABLED off unless that cost is acceptable.
function grantWelcomeCredit(PDO $pdo, int $userId, string $identityType, string $identityValue): bool {
    if (!WELCOME_CREDIT_ENABLED || WELCOME_CREDIT_AMOUNT <= 0 || $userId <= 0 || $identityValue === "") return false;
    if ($identityType === "email") $identityValue = canonicalEmail($identityValue);
    elseif ($identityType !== "google") return false;                      // apple (token not verified) is not eligible
    $key = hash("sha256", $identityType . ":" . $identityValue);
    $own = !$pdo->inTransaction();
    try {
        if ($own) $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO welcome_credit_claims (user_id, identity_type, identity_key, amount, source, created_at)
                       VALUES (?,?,?,?, 'signup', ?)")
            ->execute([$userId, $identityType, $key, WELCOME_CREDIT_AMOUNT, date("Y-m-d H:i:s")]);
        $u = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ? AND role = 'customer'");
        $u->execute([WELCOME_CREDIT_AMOUNT, $userId]);
        if ($u->rowCount() !== 1) { if ($own) $pdo->rollBack(); return false; }
        if ($own) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $x) {} }
        if (!($e instanceof PDOException) || (string)$e->getCode() !== "23000") {
            error_log("BOOKKAM welcome credit not granted (apply migrations 008/009?): " . $e->getMessage());
        }
        return false;                                  // duplicate claim (23000) or storage problem: never credit
    }
}

function reloadUser(PDO $pdo, int $userId) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $s->execute([$userId]);
    return $s->fetch();
}

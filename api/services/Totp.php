<?php
// RFC 6238 time-based one-time passwords for ADMIN second-factor sign-in, plus recovery codes.
// The authenticator secret is stored ENCRYPTED (AES-256-GCM, key derived from JWT_SECRET), never in clear text.
// Rotating JWT_SECRET therefore invalidates enrolled authenticators; see docs/admin-2fa.md for the emergency SQL.
final class Totp
{
    private const B32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

    public static function generateSecret(): string { return self::b32encode(random_bytes(20)); }

    public static function b32encode(string $bin): string {
        $bits = ""; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, "0", STR_PAD_LEFT);
        $out = ""; foreach (str_split($bits, 5) as $chunk) $out .= self::B32[bindec(str_pad($chunk, 5, "0"))];
        return $out;
    }
    public static function b32decode(string $s): string {
        $bits = ""; foreach (str_split(strtoupper(rtrim($s, "="))) as $c) { $p = strpos(self::B32, $c); if ($p === false) return ""; $bits .= str_pad(decbin($p), 5, "0", STR_PAD_LEFT); }
        $out = ""; foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }

    public static function code(string $secretB32, int $step): string {
        $h = hash_hmac("sha1", pack("J", $step), self::b32decode($secretB32), true);
        $o = ord($h[19]) & 0x0f;
        $n = ((ord($h[$o]) & 0x7f) << 24) | ((ord($h[$o + 1]) & 0xff) << 16) | ((ord($h[$o + 2]) & 0xff) << 8) | (ord($h[$o + 3]) & 0xff);
        return str_pad((string)($n % 1000000), 6, "0", STR_PAD_LEFT);
    }

    // Accepts the current 30-second step and one step either side (clock drift). A step that is not NEWER than the last
    // one accepted is refused, so a code that was just used (or shoulder-surfed) cannot be replayed.
    // Returns the accepted step, or null.
    public static function verify(string $secretB32, string $code, ?int $lastStep, ?int $now = null): ?int {
        $code = preg_replace('/\s+/', "", $code);
        if (!preg_match('/^\d{6}$/', $code)) return null;
        $cur = intdiv($now ?? time(), 30); $hit = null;
        foreach ([-1, 0, 1] as $d) {
            $st = $cur + $d;
            if (hash_equals(self::code($secretB32, $st), $code) && ($lastStep === null || $st > $lastStep)) $hit = $st;
        }
        return $hit;
    }

    public static function uri(string $secretB32, string $account, string $issuer = "BOOKKAM"): string {
        return "otpauth://totp/" . rawurlencode($issuer . ":" . $account) . "?secret=$secretB32&issuer=" . rawurlencode($issuer) . "&algorithm=SHA1&digits=6&period=30";
    }

    // ---- secret at rest -------------------------------------------------------------------------
    private static function key(): string { return hash("sha256", "bookkam-admin-totp|" . JWT_SECRET, true); }
    public static function seal(string $plain): string {
        $iv = random_bytes(12); $tag = "";
        $c = openssl_encrypt($plain, "aes-256-gcm", self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $c);
    }
    public static function open(?string $sealed): ?string {
        $raw = base64_decode((string)$sealed, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $p = openssl_decrypt(substr($raw, 28), "aes-256-gcm", self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }

    // ---- recovery codes (shown once; only SHA-256 hashes are stored) -----------------------------
    public static function newRecoveryCodes(int $n = 8): array {
        $codes = []; for ($i = 0; $i < $n; $i++) $codes[] = bin2hex(random_bytes(2)) . "-" . bin2hex(random_bytes(2)) . "-" . bin2hex(random_bytes(2));
        return $codes;
    }
    public static function hashCode(string $c): string { return hash("sha256", strtolower(trim($c))); }

    // ---- signed, short-lived challenge between the password step and the second factor -----------
    public static function challenge(int $adminId, string $purpose, int $ttl = 300): string {
        $p = rtrim(strtr(base64_encode(json_encode(["a" => $adminId, "p" => $purpose, "e" => time() + $ttl, "n" => bin2hex(random_bytes(6))])), "+/", "-_"), "=");
        return $p . "." . hash_hmac("sha256", $p, hash("sha256", "bookkam-admin-challenge|" . JWT_SECRET, true));
    }
    public static function readChallenge(string $tok, string $purpose): ?int {
        $parts = explode(".", $tok);
        if (count($parts) !== 2) return null;
        if (!hash_equals(hash_hmac("sha256", $parts[0], hash("sha256", "bookkam-admin-challenge|" . JWT_SECRET, true)), $parts[1])) return null;
        $d = json_decode(base64_decode(strtr($parts[0], "-_", "+/")), true);
        return (is_array($d) && ($d["p"] ?? "") === $purpose && (int)($d["e"] ?? 0) >= time()) ? (int)$d["a"] : null;
    }
}

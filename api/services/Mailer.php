<?php
// Transactional e-mail (verification and password-reset links). Configuration comes ONLY from .env:
//   MAIL_TRANSPORT = smtp | mail | file      (file is honoured only when APP_ENV is a test value; tests read the outbox)
//   MAIL_FROM, MAIL_FROM_NAME
//   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_ENCRYPTION = tls (STARTTLS, 587) | ssl (465) | none (localhost only)
// Nothing here ever logs a message body (it contains a secret link) or an SMTP password.
final class Mailer
{
    public static function configured(): bool
    {
        if (MAIL_FROM === "" || !filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL)) return false;
        $t = self::transport();
        return $t === "mail" || $t === "file" || ($t === "smtp" && SMTP_HOST !== "");
    }

    private static function transport(): string
    {
        $t = MAIL_TRANSPORT;
        if ($t === "file") return TEST_MODE && MAIL_OUTBOX_DIR !== "" ? "file" : "none";   // never in production
        return in_array($t, ["smtp", "mail"], true) ? $t : "none";
    }

    public static function send(string $to, string $subject, string $text): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) return false;   // no header injection
        if (!self::configured()) { error_log("BOOKKAM mail not sent: mail transport is not configured"); return false; }
        try {
            switch (self::transport()) {
                case "file": return self::sendFile($to, $subject, $text);
                case "smtp": return self::sendSmtp($to, $subject, $text);
                case "mail": return self::sendMail($to, $subject, $text);
            }
        } catch (Throwable $e) {
            error_log("BOOKKAM mail failed (" . self::transport() . "): " . $e->getMessage());
        }
        return false;
    }

    private static function headers(string $to, string $subject, string $text): array
    {
        $from = MAIL_FROM_NAME !== "" ? '"' . str_replace(['"', "\r", "\n"], "", MAIL_FROM_NAME) . '" <' . MAIL_FROM . '>' : MAIL_FROM;
        return [
            "From: $from",
            "Date: " . date("r"),
            "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . (substr(strrchr(MAIL_FROM, "@"), 1) ?: "localhost") . ">",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
        ];
    }
    private static function subjectHeader(string $subject): string { return "=?UTF-8?B?" . base64_encode($subject) . "?="; }

    private static function sendFile(string $to, string $subject, string $text): bool
    {
        if (!is_dir(MAIL_OUTBOX_DIR) && !@mkdir(MAIL_OUTBOX_DIR, 0700, true)) return false;
        $f = rtrim(MAIL_OUTBOX_DIR, "/\\") . "/" . sprintf("%.6f", microtime(true)) . "-" . bin2hex(random_bytes(4)) . ".json";
        return file_put_contents($f, json_encode(["to" => $to, "subject" => $subject, "text" => $text])) !== false;
    }

    private static function sendMail(string $to, string $subject, string $text): bool
    {
        // The 5th argument pins the envelope sender so bounces and SPF alignment use the configured address.
        return @mail($to, self::subjectHeader($subject), chunk_split(base64_encode($text)),
            implode("\r\n", self::headers($to, $subject, $text)), "-f" . MAIL_FROM) === true;
    }

    private static function sendSmtp(string $to, string $subject, string $text): bool
    {
        $enc  = SMTP_ENCRYPTION;
        $host = SMTP_HOST;
        $isLocal = in_array(strtolower($host), ["localhost", "127.0.0.1", "::1"], true);
        if ($enc === "none" && !$isLocal && !TEST_MODE) { error_log("BOOKKAM SMTP refused: unencrypted SMTP is only allowed to localhost"); return false; }
        $ctx = stream_context_create(["ssl" => ["verify_peer" => true, "verify_peer_name" => true, "allow_self_signed" => false]]);
        $fp = @stream_socket_client(($enc === "ssl" ? "ssl://" : "tcp://") . $host . ":" . SMTP_PORT, $en, $es, 12, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("SMTP connect failed ($en)");
        stream_set_timeout($fp, 12);
        $expect = function (array $ok) use ($fp): string {
            $line = ""; $all = "";
            while (($line = fgets($fp, 1024)) !== false) { $all .= $line; if (strlen($line) < 4 || $line[3] === " ") break; }
            $code = (int)substr($all, 0, 3);
            if (!in_array($code, $ok, true)) throw new RuntimeException("SMTP unexpected reply " . $code);
            return $all;
        };
        $cmd = function (string $c, array $ok) use ($fp, $expect): string { fwrite($fp, $c . "\r\n"); return $expect($ok); };
        try {
            $expect([220]);
            $me = "bookkam.local";
            $cmd("EHLO $me", [250]);
            if ($enc === "tls") {
                $cmd("STARTTLS", [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException("STARTTLS failed");
                $cmd("EHLO $me", [250]);
            }
            if (SMTP_USER !== "") {
                $cmd("AUTH LOGIN", [334]);
                $cmd(base64_encode(SMTP_USER), [334]);
                $cmd(base64_encode(SMTP_PASS), [235]);
            }
            $cmd("MAIL FROM:<" . MAIL_FROM . ">", [250]);
            $cmd("RCPT TO:<$to>", [250, 251]);
            $cmd("DATA", [354]);
            $body = implode("\r\n", array_merge(self::headers($to, $subject, $text), [
                "To: <$to>", "Subject: " . self::subjectHeader($subject), "", rtrim(chunk_split(base64_encode($text))),
            ]));
            fwrite($fp, preg_replace('/^\./m', '..', $body) . "\r\n.\r\n");
            $expect([250]);
            @$cmd("QUIT", [221]);
            return true;
        } finally {
            fclose($fp);
        }
    }
}

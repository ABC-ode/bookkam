<?php
// Account e-mails (verification, password reset). Needs config.php, config/security.php and services/Mailer.php.
// ── E-mail account helpers ─────────────────────────────────────────────────
// Tokens travel in the URL FRAGMENT (#verify_email=...): fragments are never sent to the server, written to access
// logs, or leaked to third-party scripts through the Referer header.
function accountLink(string $kind, string $token): string { return APP_URL . "/#" . $kind . "=" . $token; }

function sendVerificationEmail(PDO $pdo, array $u): bool {
    if (APP_URL === "") { error_log("BOOKKAM: APP_URL is not set, verification e-mail not sent"); return false; }
    $tok  = issueAuthToken($pdo, (int)$u["id"], "verify_email", EMAIL_VERIFY_TTL);
    $name = cleanText($u["name"] ?? "", 60) ?: "there";
    return Mailer::send($u["email"], "Confirm your BOOKKAM email address",
        "Hi $name,\n\nConfirm your email address to finish creating your BOOKKAM account:\n\n" . accountLink("verify_email", $tok) .
        "\n\nThis link works once and expires in 24 hours. If you did not create a BOOKKAM account, you can ignore this message.\n\nBOOKKAM");
}
function sendResetEmail(PDO $pdo, array $u, int $ttl = PASSWORD_RESET_TTL): bool {
    if (APP_URL === "") { error_log("BOOKKAM: APP_URL is not set, reset e-mail not sent"); return false; }
    $tok  = issueAuthToken($pdo, (int)$u["id"], "password_reset", $ttl);
    $name = cleanText($u["name"] ?? "", 60) ?: "there";
    $mins = (int)round($ttl / 60);
    return Mailer::send($u["email"], "Reset your BOOKKAM password",
        "Hi $name,\n\nUse this link to choose a new password:\n\n" . accountLink("reset_password", $tok) .
        "\n\nThe link works once and expires in " . ($mins >= 120 ? round($mins / 60) . " hours" : "$mins minutes") .
        ". If you did not ask for this, ignore this message: your password has not changed.\n\nBOOKKAM");
}
// Called once an e-mail address is proven (link clicked / reset completed). Eligibility is decided inside grantWelcomeCredit.
function onEmailVerified(PDO $pdo, array $u): void {
    if (($u["role"] ?? "") === "customer" && !empty($u["email"])) grantWelcomeCredit($pdo, (int)$u["id"], "email", $u["email"]);
}

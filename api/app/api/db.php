<?php
// ── Database connection ───────────────────────────────────────────────────────
// Reads from .env if present, otherwise uses the hardcoded dev defaults below.
// On your live server: set DB_HOST, DB_NAME, DB_USER, DB_PASS in .env

if (empty($_ENV["DB_HOST"])) {
    $envFile = dirname(__DIR__) . "/.env";
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), "#") || !str_contains($line, "=")) continue;
            [$key, $val] = explode("=", $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}

// ── Dev defaults (your local XAMPP settings) ─────────────────────────────────
// Change these to match your local setup if needed
$host     = $_ENV["DB_HOST"] ?? "localhost";
$dbname   = $_ENV["DB_NAME"] ?? "bookkam";
$username = $_ENV["DB_USER"] ?? "root";
$password = $_ENV["DB_PASS"] ?? "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username, $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}
?>

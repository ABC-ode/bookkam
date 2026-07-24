<?php
// db.php is loaded before config.php in some contexts,
// so we parse .env here too if $_ENV isn't populated yet.
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

$host     = $_ENV["DB_HOST"] ?? "localhost";
$port     = $_ENV["DB_PORT"] ?? "3306";
$dbname   = $_ENV["DB_NAME"] ?? "bookkam";
$username = $_ENV["DB_USER"] ?? "root";
$password = $_ENV["DB_PASS"] ?? "";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
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

<?php
// db.php - Database Connection (Secure)

// Load .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue; // Skip comments
        if (strpos($line, '=') === false)
            continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) {
            putenv("$key=$value");
        }
    }
}

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'candytex_dash';
$username = getenv('DB_USER') ?: 'candytex_user';
$password = getenv('DB_PASS') ?: 'nU?MZsXZ[*i6LB^]';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // In production, log this, don't show details to user
    error_log("DB Connection Failed: " . $e->getMessage());
    die("Database Connection Failed. Please contact the administrator.");
}
?>
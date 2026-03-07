<?php
// Fake db connection to load everything properly
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key)."=".trim($value));
    }
}
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'candytex_dash';
$username = getenv('DB_USER') ?: 'candytex_user';
$password = getenv('DB_PASS') ?: 'nU?MZsXZ[*i6LB^]';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ALTER TABLE hr_payroll ADD COLUMN IF NOT EXISTS frais DECIMAL(10,2) DEFAULT 0.00 AFTER advances");
    echo "Frais column added successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

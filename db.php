<?php
// db.php - Database Connection
$host = 'localhost';
$dbname = 'candytex_dash';
$username = 'candytex_user';
$password = 'nU?MZsXZ[*i6LB^]';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // In production, log this, don't show to user
    die("Database Connection Failed: " . $e->getMessage());
}
?>
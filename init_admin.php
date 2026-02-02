<?php
require 'db.php';

$cin = 'admin';
$password = '8059Ccaixa1*';
$hash = password_hash($password, PASSWORD_DEFAULT);
$name = 'Director (Super Admin)';
$phone = '000000000'; // Placeholder
$role = 'admin';

$sql = "INSERT INTO users (cin, name, phone, role, password) 
        VALUES (?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role)";

$stmt = $pdo->prepare($sql);
if ($stmt->execute([$cin, $name, $phone, $role, $hash])) {
    echo "<h1>✅ Admin Account Created/Updated</h1>";
    echo "<p>Login: <strong>admin</strong></p>";
    echo "<p>Password: (Hidden)</p>";
    echo "<br><a href='index.php'>Go to Login</a>";
} else {
    echo "<h1>❌ Error creating admin</h1>";
}
?>
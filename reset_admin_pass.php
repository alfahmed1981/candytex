<?php
require 'db.php';

$cin = 'admin'; // User login provided
$data_pass = '8059Ccaixa1*'; // User password provided

// Hash
$hash = password_hash($data_pass, PASSWORD_DEFAULT);

// Check if admin exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE cin = ?");
$stmt->execute([$cin]);
$exists = $stmt->fetch();

if ($exists) {
    // Update
    $update = $pdo->prepare("UPDATE users SET password = ?, status = 'active', role = 'admin' WHERE cin = ?");
    $update->execute([$hash, $cin]);
    echo "✅ Admin password updated successfully.";
} else {
    // Create
    $insert = $pdo->prepare("INSERT INTO users (cin, name, password, role, status, phone) VALUES (?, 'Administrator', ?, 'admin', 'active', '0000000000')");
    $insert->execute([$cin, $hash]);
    echo "✅ Admin user created successfully.";
}

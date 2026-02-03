<?php
session_start();
require 'db.php';

// Get params
$u = $_GET['u'] ?? '';
$p = $_GET['p'] ?? '';

if (empty($u) || empty($p)) {
    die("Error: Missing u or p parameters.");
}

// Fetch user
$stmt = $pdo->prepare("SELECT * FROM users WHERE cin = ?");
$stmt->execute([$u]);
$user = $stmt->fetch();

if ($user) {
    if (password_verify($p, $user['password'])) {
        // Login Success
        $_SESSION['user_cin'] = $user['cin'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['role'] = $user['role'];

        // Redirect
        if ($user['role'] === 'admin') {
            header("Location: admin.php");
        } else {
            header("Location: index.php");
        }
        exit;
    } else {
        die("Error: Wrong password.");
    }
} else {
    die("Error: User not found.");
}

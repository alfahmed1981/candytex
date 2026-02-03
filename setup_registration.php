<?php
require 'db.php';

try {
    // Check if 'status' column exists
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($check->rowCount() == 0) {
        // Add status column, default to 'active' for existing users so we don't lock them out
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'pending') DEFAULT 'active'");
        echo "✅ Added 'status' column to users table.<br>";
    } else {
        echo "ℹ️ 'status' column already exists.<br>";
    }

    // Ensure password column exists (just in case)
    $check_pass = $pdo->query("SHOW COLUMNS FROM users LIKE 'password'");
    if ($check_pass->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL");
        echo "✅ Added 'password' column.<br>";
    }

    echo "Schema update complete. <a href='index.php'>Go to Login</a>";

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

echo "<h2>🛠️ Database Diagnostic Tool</h2>";

try {
    // 1. Check USERS table structure
    echo "Checking 'users' table...<br>";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "Columns found: " . implode(", ", $columns) . "<br><br>";

    // 2. Check for 'status'
    if (!in_array('status', $columns)) {
        echo "⚠️ 'status' column MISSING. Attempting to add...<br>";
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'pending') DEFAULT 'active'");
            echo "✅ 'status' column ADDED successfully.<br>";
        } catch (Exception $e) {
            echo "❌ Failed to add 'status': " . $e->getMessage() . "<br>";
        }
    } else {
        echo "✅ 'status' column exists.<br>";
    }

    // 3. Check for 'password'
    if (!in_array('password', $columns)) {
        echo "⚠️ 'password' column MISSING. Attempting to add...<br>";
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL");
            echo "✅ 'password' column ADDED successfully.<br>";
        } catch (Exception $e) {
            echo "❌ Failed to add 'password': " . $e->getMessage() . "<br>";
        }
    } else {
        echo "✅ 'password' column exists.<br>";
    }

    echo "<hr>";
    echo "<h3>Diagnostic Complete.</h3>";
    echo "<p>If you saw green checks (✅), the database is fixed.</p>";
    echo "<a href='index.php'>Go back to Login/Register</a>";

} catch (PDOException $e) {
    echo "<h3>🔥 Fatal Catch Error</h3>";
    echo "Message: " . $e->getMessage();
}

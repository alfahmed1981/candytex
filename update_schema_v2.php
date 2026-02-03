<?php
require 'db.php';

try {
    echo "<h1>🔄 Updating Schema v2...</h1>";

    // Add birth_date column if not exists
    $sql = "SHOW COLUMNS FROM users LIKE 'birth_date'";
    $result = $pdo->query($sql)->fetch();

    if (!$result) {
        $pdo->exec("ALTER TABLE users ADD COLUMN birth_date DATE DEFAULT NULL AFTER location");
        echo "✅ Added 'birth_date' column.<br>";
    } else {
        echo "ℹ️ 'birth_date' column already exists.<br>";
    }

    echo "<h1>✨ Database Update Complete!</h1>";

} catch (PDOException $e) {
    echo "<h1>❌ Error: " . $e->getMessage() . "</h1>";
}
?>
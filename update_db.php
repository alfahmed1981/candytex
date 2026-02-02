<?php
require 'db.php';

try {
    // 1. Add 'category' column if it doesn't exist (Previous Step)
    echo "<h3>Step 1: Check Categories...</h3>";
    try {
        $sql = "ALTER TABLE `countermeasures` 
                ADD COLUMN `category` ENUM('S', 'Q', 'D', '5S', 'C') NOT NULL DEFAULT 'S' AFTER `user_cin`";
        $pdo->exec($sql);
        echo "✅ Added 'category' column.<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "ℹ️ 'category' column already exists.<br>";
        } else {
            // Ignore other errors for now or print
        }
    }

    // 2. Create 'workers' table (New Step)
    echo "<h3>Step 2: Creating Workers Table...</h3>";
    $sql_workers = "CREATE TABLE IF NOT EXISTS `workers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `cin` VARCHAR(20) NOT NULL UNIQUE,
        `name` VARCHAR(100) NOT NULL,
        `shift` ENUM('A', 'B', 'C', 'Normal') NOT NULL DEFAULT 'Normal',
        `manager_cin` VARCHAR(20) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`manager_cin`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql_workers);
    echo "✅ Table 'workers' is ready.<br>";

    echo "<h1>🚀 Database Update Complete!</h1>";

} catch (PDOException $e) {
    echo "<h1>❌ General Error: " . $e->getMessage() . "</h1>";
}
?>
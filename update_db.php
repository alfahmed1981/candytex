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
    echo "<h3>Step 2: Creating/Updating Workers Table...</h3>";
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

    // Add new columns if they don't exist (Safe Alter)
    $cols_to_add = [
        "ALTER TABLE `workers` ADD COLUMN `phone` VARCHAR(20) NULL AFTER `name`",
        "ALTER TABLE `workers` ADD COLUMN `location` VARCHAR(50) NULL AFTER `phone`", // Candy 1, Flora 1
        "ALTER TABLE `workers` ADD COLUMN `department` VARCHAR(50) NULL AFTER `location`", // Sewing, Cutting...
        "ALTER TABLE `workers` ADD COLUMN `job_title` VARCHAR(100) NULL AFTER `department`",

        "ALTER TABLE `users` ADD COLUMN `location` VARCHAR(50) NULL AFTER `role`",
        "ALTER TABLE `users` ADD COLUMN `department` VARCHAR(50) NULL AFTER `location`",
        "ALTER TABLE `users` ADD COLUMN `job_title` VARCHAR(100) NULL AFTER `department`"
    ];

    foreach ($cols_to_add as $sql) {
        try {
            $pdo->exec($sql);
            echo "✅ Column added/checked.<br>";
        } catch (PDOException $e) {
            // Ignore "Duplicate column" checks
        }
    }

    echo "✅ Table 'workers' & 'users' updated.<br>";

    echo "<h1>🚀 Database Update Complete!</h1>";

} catch (PDOException $e) {
    echo "<h1>❌ General Error: " . $e->getMessage() . "</h1>";
}
?>
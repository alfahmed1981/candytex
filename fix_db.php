<?php
// Run this file ONE TIME in your browser to fix the database: http://candytex.ma/dash/fix_db.php
require 'db.php';
try {
    $pdo->exec("ALTER TABLE hr_payroll ADD COLUMN IF NOT EXISTS frais DECIMAL(10,2) DEFAULT 0.00 AFTER advances");
    echo "<h1>✅ SUCCESS!</h1>";
    echo "<p>The 'frais' column was successfully added to the hr_payroll table.</p>";
    echo "<p>You can now go back to <a href='hr_import.php'>Import Data</a> and try again.</p>";
} catch (Exception $e) {
    echo "<h1>❌ ERROR</h1>";
    echo "<p>" . $e->getMessage() . "</p>";
}

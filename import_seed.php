<?php
// One-time Import Script for HR Data
session_start();
require 'db.php';
require 'includes/auth.php';
require_admin(); // Only Admin can run this

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>HR Import</title><style>body{font-family:sans-serif; padding:40px; text-align:center;} .btn{padding:10px 20px; background:#0b3c5d; color:white; text-decoration:none; border-radius:5px;}</style></head><body>";

try {
    // Read the SQL seed file
    $sql = file_get_contents(__DIR__ . '/import_hr_employees.sql');

    if (!$sql) {
        throw new Exception("SQL file not found! Make sure you pulled the latest changes from GitHub.");
    }

    // Execute the massive insert statement
    $pdo->exec($sql);

    echo "<h1>✅ نجاح! تم استيراد جميع الموظفين (424 موظف) بنجاح.</h1>";
    echo "<p>Data imported successfully from Excel to the database.</p>";
    echo "<br><br><a href='hr_employees.php' class='btn'>🔙 العودة إلى دليل الموظفين</a>";

} catch (PDOException $e) {
    echo "<h1>❌ خطأ في قاعدة البيانات (Database Error)</h1>";
    echo "<p style='color:red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
} catch (Exception $e) {
    echo "<h1>❌ خطأ (Error)</h1>";
    echo "<p style='color:red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";

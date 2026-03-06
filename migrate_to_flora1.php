<?php
/**
 * Temporary Script: Move all employees to Flora 1
 * Run once then DELETE this file.
 */
session_start();
require 'db.php';
require 'includes/auth.php';
require_admin();

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Migration</title></head><body style='font-family:sans-serif;max-width:700px;margin:50px auto;padding:20px;'>";

// Get Flora 1 ID
$flora = $pdo->query("SELECT id, name FROM locations WHERE name = 'Flora 1' LIMIT 1")->fetch();
if (!$flora) {
    // Try fuzzy match
    $flora = $pdo->query("SELECT id, name FROM locations ORDER BY id")->fetchAll();
    echo "<h2>❌ 'Flora 1' not found. Available locations:</h2><ul>";
    foreach ($flora as $l) echo "<li>ID={$l['id']} — {$l['name']}</li>";
    echo "</ul>";
    die();
}

$flora_id = $flora['id'];
echo "<h2>🏭 Flora 1 → location_id = $flora_id</h2>";

// Show current state
$stats = $pdo->query("SELECT location_id, COUNT(*) as cnt FROM hr_employees GROUP BY location_id")->fetchAll();
echo "<h3>الحالة الحالية:</h3><table border='1' cellpadding='8'><tr><th>Location ID</th><th>عدد العمال</th></tr>";
foreach ($stats as $s) {
    $lid = $s['location_id'] ?? 'NULL';
    echo "<tr><td>$lid</td><td>{$s['cnt']}</td></tr>";
}
echo "</table>";

if (isset($_POST['confirm'])) {
    // Direct UPDATE — set ALL employees to Flora 1
    $stmt = $pdo->prepare("UPDATE hr_employees SET location_id = ?");
    $stmt->execute([$flora_id]);
    $affected = $stmt->rowCount();
    
    audit_log($pdo, 'bulk_migrate', "Migrated $affected employees to Flora 1 (id=$flora_id)");
    
    echo "<h2 style='color:green;'>✅ تم تحويل $affected عامل إلى Flora 1</h2>";
    echo "<p style='color:red;font-weight:bold;'>⚠️ احذف هذا الملف بعد الاستخدام!</p>";
    echo "<a href='hr_employees.php' style='padding:10px 30px;background:#0984e3;color:white;text-decoration:none;border-radius:5px;'>← العودة</a>";
} else {
    echo "<br><form method='POST'><button name='confirm' value='1' style='padding:15px 40px;background:#e74c3c;color:white;border:none;border-radius:8px;font-size:1.2em;cursor:pointer;' onclick=\"return confirm('تحويل جميع العمال إلى Flora 1؟')\">✅ تأكيد التحويل لـ Flora 1</button></form>";
}

echo "</body></html>";

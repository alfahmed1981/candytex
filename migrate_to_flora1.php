<?php
/**
 * Temporary Script: Move all employees to Flora 1
 * Run once then DELETE this file.
 * Usage: https://candytex.ma/dash/migrate_to_flora1.php
 */
session_start();
require 'db.php';
require 'includes/auth.php';
require_admin(); // Only admin can run this

// Find Flora 1 location_id
$stmt = $pdo->prepare("SELECT id, name FROM locations WHERE name LIKE '%Flora%1%' OR name LIKE '%flora%1%' LIMIT 1");
$stmt->execute();
$flora = $stmt->fetch();

if (!$flora) {
    die("❌ Location 'Flora 1' not found in locations table.");
}

$flora_id = $flora['id'];
$flora_name = $flora['name'];

// Count employees before
$count_before = $pdo->query("SELECT COUNT(*) FROM hr_employees")->fetchColumn();
$count_already = $pdo->prepare("SELECT COUNT(*) FROM hr_employees WHERE location_id = ?");
$count_already->execute([$flora_id]);
$already = $count_already->fetchColumn();
$count_other = $count_before - $already;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    // Do the migration
    $stmt = $pdo->prepare("UPDATE hr_employees SET location_id = ? WHERE location_id IS NULL OR location_id != ?");
    $stmt->execute([$flora_id, $flora_id]);
    $affected = $stmt->rowCount();
    
    audit_log($pdo, 'bulk_migrate', "Migrated $affected employees to Flora 1 (location_id=$flora_id)");
    
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Migration Done</title></head><body style='font-family:sans-serif;max-width:600px;margin:50px auto;text-align:center;'>";
    echo "<h1>✅ تم التحويل بنجاح</h1>";
    echo "<p style='font-size:1.2em;'>تم تحويل <strong>$affected</strong> عامل إلى <strong>$flora_name</strong></p>";
    echo "<p style='color:red;font-weight:bold;'>⚠️ يرجى حذف هذا الملف من السيرفر بعد الاستخدام</p>";
    echo "<a href='hr_employees.php' style='display:inline-block;margin-top:20px;padding:10px 30px;background:#0984e3;color:white;text-decoration:none;border-radius:5px;'>← العودة للموارد البشرية</a>";
    echo "</body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تحويل العمال إلى Flora 1</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .card { background: #f8f9fa; border: 2px solid #dee2e6; border-radius: 10px; padding: 30px; text-align: center; }
        .stat { font-size: 2em; color: #0984e3; font-weight: bold; margin: 10px 0; }
        .btn { display: inline-block; margin-top: 20px; padding: 15px 40px; background: #e74c3c; color: white; border: none; border-radius: 8px; font-size: 1.1em; cursor: pointer; }
        .btn:hover { background: #c0392b; }
        .info { background: #fff3cd; padding: 10px; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🏭 تحويل العمال إلى <?= htmlspecialchars($flora_name) ?></h1>
        <p>Location ID: <strong><?= $flora_id ?></strong></p>
        
        <div class="stat"><?= $count_before ?> عامل إجمالاً</div>
        <p>✅ موجودون بالفعل في <?= htmlspecialchars($flora_name) ?>: <strong><?= $already ?></strong></p>
        <p>🔄 سيتم تحويلهم: <strong><?= $count_other ?></strong></p>
        
        <div class="info">
            ⚠️ هذه العملية ستقوم بتحويل جميع العمال الذين ليسوا في <?= htmlspecialchars($flora_name) ?> إلى هذا الموقع
        </div>
        
        <form method="POST">
            <button type="submit" name="confirm" value="1" class="btn" onclick="return confirm('هل أنت متأكد من تحويل <?= $count_other ?> عامل إلى <?= htmlspecialchars($flora_name) ?>?')">
                ✅ تأكيد التحويل
            </button>
        </form>
        
        <br><br>
        <a href="hr_employees.php">← العودة</a>
    </div>
</body>
</html>

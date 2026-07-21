<?php
// reset_passwords.php
// This script resets all user passwords to: [NAME][CIN]

require 'db.php';

// Simple security measure: Only allow running this if it's explicitly requested
// Just to prevent random bots from running it before the admin deletes it.
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    die("<h3>Are you sure you want to reset ALL passwords? <a href='?confirm=yes'>Click here to confirm</a>.</h3>");
}

try {
    // Fetch all users
    $stmt = $pdo->query("SELECT id, name, cin FROM users");
    $users = $stmt->fetchAll();
    $count = 0;

    echo "<h3>بدأ تحديث كلمات المرور... / Updating passwords...</h3>";

    foreach ($users as $u) {
        // Remove spaces and concatenate NAME and CIN
        // Note: We trim but we do not remove internal spaces from the name, 
        // as the user requested "MOHAMMED ELMOUBTAHIJC571619" (spaces intact in name).
        $plain_password = trim($u['name']) . trim($u['cin']);
        
        // Hash the password securely
        $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);
        
        // Update database
        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->execute([$hashed_password, $u['id']]);
        
        $count++;
    }

    echo "<h3 style='color:green;'>تم بنجاح! تم تحديث وتشفير كلمات مرور $count مستخدم بالصيغة المطلوبة (الاسم + CIN).</h3>";
    echo "<p style='color:red;'>⚠️ هام: يرجى إخباري بحذف هذا الملف فوراً بعد الانتهاء لدواعي أمنية.</p>";

} catch (Exception $e) {
    echo "حدث خطأ: " . $e->getMessage();
}
?>

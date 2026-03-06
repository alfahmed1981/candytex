<?php
// includes/nav.php - Shared Navigation Component
// Required variables: none (uses session)

$current_page = basename($_SERVER['PHP_SELF']);
$nav_user_name = $_SESSION['user_name'] ?? 'User';
$nav_is_admin = is_admin();
$nav_is_hr = is_hr();
$nav_is_leader = is_leader();
$nav_is_hr_admin = function_exists('is_hr_admin') ? is_hr_admin() : false;
$nav_is_impersonating = !empty($_SESSION['is_impersonating']);
?>

<?php if ($nav_is_impersonating): ?>
    <div
        style="background: linear-gradient(135deg, #ff6b6b, #ee5a24); color: white; padding: 10px 20px; text-align: center; font-weight: bold; position: fixed; top: 0; left: 0; width: 100%; z-index: 9999;">
        🕵️ أنت تتصفح كـ <strong>
            <?= htmlspecialchars($nav_user_name) ?>
        </strong>
        | <a href="admin.php?action=stop_impersonation" style="color: white; text-decoration: underline;">🔙 العودة لحساب
            المدير</a>
    </div>
    <div style="height: 45px;"></div>
<?php endif; ?>

<!-- Mobile Top Navigation -->
<div class="top-nav">
    <div class="top-nav-header">
        <h3>📊 SQD+C Board</h3>
        <span class="user-info">👤
            <?= htmlspecialchars($nav_user_name) ?>
        </span>
    </div>
    <div class="nav-links">
        <a href="index.php" <?= $current_page === 'index.php' ? 'class="active"' : '' ?>>📊 لوحة</a>
        <a href="guide.php" <?= $current_page === 'guide.php' ? 'class="active"' : '' ?>>📖 دليل</a>
        <a href="my_team.php" <?= $current_page === 'my_team.php' ? 'class="active"' : '' ?>>👥 فريقي</a>
        <a href="global.php" <?= $current_page === 'global.php' ? 'class="active"' : '' ?>>🏭 المصنع</a>
        <a href="meetings.php" <?= $current_page === 'meetings.php' ? 'class="active"' : '' ?>>🗓️ اجتماعات</a>
        <a href="iso_ncr.php" <?= $current_page === 'iso_ncr.php' ? 'class="active"' : '' ?>>📝 NCR/CAR</a>
        <a href="iso_risk.php" <?= $current_page === 'iso_risk.php' ? 'class="active"' : '' ?>>📋 مخاطر</a>
        <?php if ($nav_is_admin || $nav_is_hr): ?>
            <a href="iso_docs.php" <?= $current_page === 'iso_docs.php' ? 'class="active"' : '' ?>>📄 وثائق</a>
        <?php endif; ?>
        <?php if ($nav_is_admin || $nav_is_hr || $nav_is_hr_admin): ?>
            <a href="hr_employees.php" <?= $current_page === 'hr_employees.php' ? 'class="active"' : '' ?>>👥 موارد بشرية</a>
            <a href="hr_attendance.php" <?= $current_page === 'hr_attendance.php' ? 'class="active"' : '' ?>>🕒 حضور يومي</a>
        <?php endif; ?>
        <?php if ($nav_is_admin || $nav_is_hr): ?>
            <a href="hr_payroll.php" <?= $current_page === 'hr_payroll.php' ? 'class="active"' : '' ?>>💵 إدارة الرواتب</a>
            <a href="hr_skills_matrix.php" <?= $current_page === 'hr_skills_matrix.php' ? 'class="active"' : '' ?>>🧠 مصفوفة
                المهارات</a>
        <?php endif; ?>
        <?php if ($nav_is_admin || $nav_is_hr || $nav_is_leader): ?>
            <a href="admin_daily.php" <?= $current_page === 'admin_daily.php' ? 'class="active"' : '' ?>>📸 يومي</a>
            <a href="admin_issues.php" <?= $current_page === 'admin_issues.php' ? 'class="active"' : '' ?>>🛠️ مشاكل</a>
        <?php endif; ?>
        <?php if ($nav_is_admin): ?>
            <a href="admin.php" <?= $current_page === 'admin.php' ? 'class="active"' : '' ?>>⚙️ إدارة</a>
            <a href="iso_maintenance.php" <?= $current_page === 'iso_maintenance.php' ? 'class="active"' : '' ?>>🏭 إدارة
                الصيانة (TPM)</a>
        <?php endif; ?>
        <a href="index.php?logout=1" class="logout">🚪 خروج</a>
    </div>
</div>

<!-- Desktop Sidebar -->
<div class="sidebar">
    <div class="profile">
        <h3>👤
            <?= htmlspecialchars($nav_user_name) ?>
        </h3>
        <p>
            <?= htmlspecialchars($_SESSION['user_cin'] ?? '') ?>
        </p>
    </div>
    <hr>
    <a href="index.php" class="logout-btn" style="background:#007bff;">📊 لوحة القيادة</a>
    <a href="guide.php" class="logout-btn" style="background:#28a745;">📖 دليل الاستخدام</a>
    <a href="my_team.php" class="logout-btn" style="background:#17a2b8;">👥 فريقي</a>
    <a href="global.php" class="logout-btn" style="background:#fd7e14;">🏭 وضع المصنع</a>
    <a href="meetings.php" class="logout-btn" style="background:#e65100;">🗓️ الاجتماعات</a>
    <a href="iso_ncr.php" class="logout-btn" style="background:#1a237e;">📝 NCR / CAR</a>
    <a href="iso_risk.php" class="logout-btn" style="background:#c0392b;">📋 سجل المخاطر</a>
    <?php if ($nav_is_admin || $nav_is_hr): ?>
        <a href="iso_docs.php" class="logout-btn" style="background:#2e7d32;">📄 التحكم بالوثائق</a>
    <?php endif; ?>
    <?php if ($nav_is_admin || $nav_is_hr || $nav_is_hr_admin): ?>
        <a href="hr_employees.php" class="logout-btn" style="background:#0984e3;">👥 الموارد البشرية</a>
        <a href="hr_attendance.php" class="logout-btn" style="background:#00cec9;">🕒 الحضور اليومي</a>
    <?php endif; ?>
    <?php if ($nav_is_admin || $nav_is_hr): ?>
        <a href="hr_payroll.php" class="logout-btn" style="background:#2ecc71;">💵 إدارة وتوليد الرواتب</a>
        <a href="hr_skills_matrix.php" class="logout-btn" style="background:#8e44ad;">🧠 مصفوفة المهارات والمناصب</a>
    <?php endif; ?>
    <?php if ($nav_is_admin || $nav_is_hr || $nav_is_leader): ?>
        <a href="admin_daily.php" class="logout-btn" style="background:#f39c12;">📸 النظرة اليومية</a>
        <a href="admin_issues.php" class="logout-btn" style="background:#e91e63;">🛠️ إدارة المشاكل</a>
    <?php endif; ?>
    <?php if ($nav_is_admin): ?>
        <a href="admin.php" class="logout-btn" style="background:#6f42c1;">⚙️ إدارة النظام</a>
        <a href="iso_maintenance.php" class="logout-btn" style="background:#d35400;">🏭 إدارة الصيانة والمعدات</a>
    <?php endif; ?>
    <a href="index.php?logout=1" class="logout-btn" style="background:#dc3545;">🚪 خروج</a>
</div>
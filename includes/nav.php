<?php
// includes/nav.php - Shared Navigation Component
// Required variables: none (uses session)

$current_page = basename($_SERVER['PHP_SELF']);
$nav_user_name = $_SESSION['user_name'] ?? 'User';
$nav_is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$nav_is_impersonating = !empty($_SESSION['is_impersonating']);
?>

<?php if ($nav_is_impersonating): ?>
    <div
        style="background: linear-gradient(135deg, #ff6b6b, #ee5a24); color: white; padding: 10px 20px; text-align: center; font-weight: bold; position: sticky; top: 0; z-index: 1100;">
        🕵️ أنت تتصفح كـ <strong>
            <?= htmlspecialchars($nav_user_name) ?>
        </strong>
        | <a href="admin.php?action=stop_impersonation" style="color: white; text-decoration: underline;">🔙 العودة لحساب
            المدير</a>
    </div>
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
        <?php if ($nav_is_admin): ?>
            <a href="iso_ncr.php" <?= $current_page === 'iso_ncr.php' ? 'class="active"' : '' ?>>📝 NCR/CAR</a>
            <a href="iso_risk.php" <?= $current_page === 'iso_risk.php' ? 'class="active"' : '' ?>>📋 مخاطر</a>
            <a href="iso_docs.php" <?= $current_page === 'iso_docs.php' ? 'class="active"' : '' ?>>📄 وثائق</a>
            <a href="admin_issues.php" <?= $current_page === 'admin_issues.php' ? 'class="active"' : '' ?>>🛠️ مشاكل</a>
            <a href="admin.php" <?= $current_page === 'admin.php' ? 'class="active"' : '' ?>>⚙️ إدارة</a>
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
    <?php if ($nav_is_admin): ?>
        <a href="iso_ncr.php" class="logout-btn" style="background:#1a237e;">📝 NCR / CAR</a>
        <a href="iso_risk.php" class="logout-btn" style="background:#c0392b;">📋 سجل المخاطر</a>
        <a href="iso_docs.php" class="logout-btn" style="background:#2e7d32;">📄 التحكم بالوثائق</a>
        <a href="admin_issues.php" class="logout-btn" style="background:#e91e63;">🛠️ إدارة المشاكل</a>
        <a href="admin.php" class="logout-btn" style="background:#6f42c1;">⚙️ إدارة النظام</a>
    <?php endif; ?>
    <a href="index.php?logout=1" class="logout-btn" style="background:#dc3545;">🚪 خروج</a>
</div>
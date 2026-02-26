<?php
session_start();
require 'db.php';
require 'includes/auth.php';

require_login();
$user_role = $_SESSION['role'];
$user_cin = $_SESSION['user_cin'];

$is_admin = is_admin();
$is_hr = is_hr();
$is_leader = is_leader();

if (!$is_admin && !$is_hr && !$is_leader) {
    header("Location: index.php");
    exit;
}

// Handle Status Update / Delete / Restore
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        if (isset($_POST['update_status'])) {
            $issue_id = intval($_POST['issue_id']);
            $new_status = $_POST['new_status'];

            if ($is_leader) {
                // Team Leaders can only update their own issues
                $stmt_chk = $pdo->prepare("SELECT user_cin FROM countermeasures WHERE id = ?");
                $stmt_chk->execute([$issue_id]);
                if ($stmt_chk->fetchColumn() !== $user_cin) {
                    die("Unauthorized");
                }
            }

            $stmt = $pdo->prepare("UPDATE countermeasures SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $issue_id]);

            header("Location: admin_issues.php?updated=1");
            exit;
        }

        // Soft Delete (just change status to 'Deleted')
        if (isset($_POST['delete_issue'])) {
            $issue_id = intval($_POST['issue_id']);
            $stmt = $pdo->prepare("UPDATE countermeasures SET status = 'Deleted' WHERE id = ?");
            $stmt->execute([$issue_id]);
            header("Location: admin_issues.php?deleted=1");
            exit;
        }

        // Restore (change status back to 'Open')
        if (isset($_POST['restore_issue'])) {
            $issue_id = intval($_POST['issue_id']);
            $stmt = $pdo->prepare("UPDATE countermeasures SET status = 'Open' WHERE id = ?");
            $stmt->execute([$issue_id]);
            header("Location: admin_issues.php?restored=1");
            exit;
        }
    } catch (PDOException $e) {
        // Fix ENUM constraint issue: Convert status to VARCHAR
        // Errors like "Data truncated" (1265) or "General error"
        $pdo->exec("ALTER TABLE countermeasures MODIFY COLUMN status VARCHAR(50) DEFAULT 'Open'");

        // Retry the exact same request logic? 
        // Simpler to just redirect with an error param or retry via refresh, 
        // but let's try to execute the failed query again if possible.
        // For simplicity/safety, just retry the specific actions (Lazy Logic):

        if (isset($_POST['delete_issue'])) {
            $issue_id = intval($_POST['issue_id']);
            $pdo->prepare("UPDATE countermeasures SET status = 'Deleted' WHERE id = ?")->execute([$issue_id]);
            header("Location: admin_issues.php?deleted=1&fixed_enum=1");
            exit;
        }

        // Rethrow if not handled
        throw $e;
    }
}

// Fetch all countermeasures with user info (exclude deleted by default)
$show_deleted = isset($_GET['show_deleted']) ? true : false;

$sql = "SELECT c.*, u.name as user_name, u.department, u.location 
        FROM countermeasures c 
        LEFT JOIN users u ON c.user_cin = u.cin 
        WHERE 1=1 ";

if (!$show_deleted) {
    $sql .= "AND c.status != 'Deleted' ";
}

if ($is_hr) {
    $loc = get_user_factory($pdo, $user_cin);
    $sql .= "AND u.location = " . $pdo->quote($loc) . " ";
} elseif ($is_leader) {
    $sql .= "AND c.user_cin = " . $pdo->quote($user_cin) . " ";
}

$sql .= "ORDER BY c.created_at DESC";

$stmt = $pdo->query($sql);
$issues = $stmt->fetchAll();

// Calculate Statistics
$stats = [
    'total' => count($issues),
    'Open' => 0,
    'In Progress' => 0,
    'Done' => 0,
    'Deleted' => 0,
    'by_category' => ['S' => 0, 'Q' => 0, 'D' => 0, '5S' => 0, 'C' => 0]
];

foreach ($issues as $issue) {
    $status = $issue['status'] ?? 'Open';
    if (isset($stats[$status])) {
        $stats[$status]++;
    }
    $cat = $issue['category'] ?? 'S';
    if (isset($stats['by_category'][$cat])) {
        $stats['by_category'][$cat]++;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المشاكل | Issues Management</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #f5f6fa;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 1.8em;
        }

        .page-header .nav-links a {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            margin-left: 10px;
            transition: background 0.3s;
        }

        .page-header .nav-links a:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Stats Dashboard */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card .number {
            font-size: 2.2em;
            font-weight: bold;
        }

        .stat-card .label {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }

        .stat-card.open {
            border-top: 4px solid #dc3545;
        }

        .stat-card.open .number {
            color: #dc3545;
        }

        .stat-card.progress {
            border-top: 4px solid #ffc107;
        }

        .stat-card.progress .number {
            color: #ffc107;
        }

        .stat-card.done {
            border-top: 4px solid #28a745;
        }

        .stat-card.done .number {
            color: #28a745;
        }

        .stat-card.total {
            border-top: 4px solid #007bff;
        }

        .stat-card.total .number {
            color: #007bff;
        }

        /* Category Stats */
        .category-stats {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .category-stats h3 {
            margin-top: 0;
            color: #333;
        }

        .category-bars {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .category-bar {
            flex: 1;
            min-width: 100px;
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            color: white;
            font-weight: bold;
        }

        .category-bar.S {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        .category-bar.Q {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .category-bar.D {
            background: linear-gradient(135deg, #f39c12, #d35400);
        }

        .category-bar.fiveS {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
        }

        .category-bar.C {
            background: linear-gradient(135deg, #27ae60, #1e8449);
        }

        .category-bar .count {
            font-size: 1.8em;
            display: block;
        }

        .category-bar .name {
            font-size: 0.85em;
            opacity: 0.9;
        }

        /* Issues Table */
        .issues-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .issues-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .issues-table th {
            background: #f8f9fa;
            padding: 15px 12px;
            text-align: right;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
        }

        .issues-table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .issues-table tr:hover {
            background: #f8f9fa;
        }

        /* Status Badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 500;
        }

        .status-badge.open {
            background: #fce4ec;
            color: #c62828;
        }

        .status-badge.progress {
            background: #fff3e0;
            color: #ef6c00;
        }

        .status-badge.done {
            background: #e8f5e9;
            color: #2e7d32;
        }

        /* Category Badge */
        .cat-badge {
            display: inline-block;
            width: 35px;
            height: 35px;
            line-height: 35px;
            text-align: center;
            border-radius: 8px;
            font-weight: bold;
            color: white;
        }

        .cat-badge.S {
            background: #e74c3c;
        }

        .cat-badge.Q {
            background: #3498db;
        }

        .cat-badge.D {
            background: #f39c12;
        }

        .cat-badge.fiveS {
            background: #9b59b6;
        }

        .cat-badge.C {
            background: #27ae60;
        }

        /* User Info */
        .user-info {
            font-size: 0.85em;
        }

        .user-info .name {
            font-weight: 600;
            color: #333;
        }

        .user-info .meta {
            color: #888;
            font-size: 0.9em;
        }

        /* Action Buttons */
        .action-form select {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9em;
        }

        .action-form button {
            padding: 6px 15px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9em;
        }

        .action-form button:hover {
            background: #5a6fd6;
        }

        /* Filters */
        .filters-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .filters-bar select,
        .filters-bar input[type="date"] {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1em;
            color: #333;
            background: white;
        }

        .filters-bar input[type="date"] {
            min-width: 140px;
        }

        .date-filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .date-filter-group label {
            font-size: 0.9em;
            color: #555;
            white-space: nowrap;
        }

        .btn-reset-filter {
            padding: 8px 16px !important;
            background: #6c757d !important;
            color: white !important;
            border: none !important;
            border-radius: 8px !important;
            cursor: pointer;
            font-size: 0.9em !important;
            width: auto !important;
            white-space: nowrap;
        }

        .btn-reset-filter:hover {
            background: #5a6268 !important;
        }

        /* Alert */
        .alert {
            padding: 15px 20px;
            background: #d4edda;
            color: #155724;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .issues-table {
                overflow-x: auto;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1>🛠️ إدارة المشاكل</h1>
                <p style="margin: 5px 0 0; opacity: 0.9;">Issues Management Dashboard</p>
            </div>
            <div class="nav-links">
                <a href="index.php">📊 لوحة القيادة</a>
                <?php if ($is_admin || $is_hr || $is_leader): ?>
                    <a href="iso_ncr.php">📝 NCR</a>
                    <a href="iso_risk.php">📋 مخاطر</a>
                <?php endif; ?>
                <?php if ($is_admin || $is_hr): ?>
                    <a href="iso_docs.php">📄 وثائق</a>
                <?php endif; ?>
                <?php if ($is_admin): ?>
                    <a href="admin.php">⚙️ الإدارة</a>
                <?php endif; ?>
                <a href="?logout=1">🚪 خروج</a>
            </div>
        </div>

        <?php if (isset($_GET['updated'])): ?>
            <div class="alert">✅ تم تحديث حالة المشكلة بنجاح!</div>
        <?php endif; ?>

        <!-- Statistics Dashboard -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="number">
                    <?php echo $stats['total']; ?>
                </div>
                <div class="label">📋 إجمالي المشاكل</div>
            </div>
            <div class="stat-card open">
                <div class="number">
                    <?php echo $stats['Open']; ?>
                </div>
                <div class="label">🔴 مفتوحة (Open)</div>
            </div>
            <div class="stat-card progress">
                <div class="number">
                    <?php echo $stats['In Progress']; ?>
                </div>
                <div class="label">🟡 قيد الإصلاح (In Progress)</div>
            </div>
            <div class="stat-card done">
                <div class="number">
                    <?php echo $stats['Done']; ?>
                </div>
                <div class="label">✅ مغلقة (Done)</div>
            </div>
        </div>

        <!-- Category Statistics -->
        <div class="category-stats">
            <h3>📊 المشاكل حسب الفئة</h3>
            <div class="category-bars">
                <div class="category-bar S">
                    <span class="count">
                        <?php echo $stats['by_category']['S']; ?>
                    </span>
                    <span class="name">🦺 السلامة (S)</span>
                </div>
                <div class="category-bar Q">
                    <span class="count">
                        <?php echo $stats['by_category']['Q']; ?>
                    </span>
                    <span class="name">🔍 الجودة (Q)</span>
                </div>
                <div class="category-bar D">
                    <span class="count">
                        <?php echo $stats['by_category']['D']; ?>
                    </span>
                    <span class="name">🚚 التسليم (D)</span>
                </div>
                <div class="category-bar fiveS">
                    <span class="count">
                        <?php echo $stats['by_category']['5S']; ?>
                    </span>
                    <span class="name">✨ التحسين (5S)</span>
                </div>
                <div class="category-bar C">
                    <span class="count">
                        <?php echo $stats['by_category']['C']; ?>
                    </span>
                    <span class="name">💰 التكلفة (C)</span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <label>🔍 تصفية:</label>
            <select id="filter-status" onchange="filterTable()">
                <option value="">كل الحالات</option>
                <option value="Open">مفتوحة (Open)</option>
                <option value="In Progress">قيد الإصلاح (In Progress)</option>
                <option value="Done">مغلقة (Done)</option>
            </select>
            <select id="filter-category" onchange="filterTable()">
                <option value="">كل الفئات</option>
                <option value="S">السلامة (S)</option>
                <option value="Q">الجودة (Q)</option>
                <option value="D">التسليم (D)</option>
                <option value="5S">التحسين (5S)</option>
                <option value="C">التكلفة (C)</option>
            </select>
            <div class="date-filter-group">
                <label>📅 من:</label>
                <input type="date" id="filter-date-from" onchange="filterTable()">
                <label>إلى:</label>
                <input type="date" id="filter-date-to" onchange="filterTable()">
            </div>
            <button type="button" class="btn-reset-filter" onclick="resetFilters()">🔄 إعادة تعيين</button>
        </div>

        <!-- Issues Table -->
        <div class="issues-table">
            <table id="issues-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الفئة</th>
                        <th>المستخدم</th>
                        <th>المشكلة</th>
                        <th>الإجراء</th>
                        <th>المسؤول</th>
                        <th>الموعد</th>
                        <th>تاريخ الإضافة</th>
                        <th>الحالة</th>
                        <th>تحديث</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issues as $i => $issue): ?>
                        <?php
                        $cat = $issue['category'] ?? 'S';
                        $catClass = $cat === '5S' ? 'fiveS' : $cat;
                        $statusClass = strtolower(str_replace(' ', '', $issue['status']));
                        if ($statusClass === 'inprogress')
                            $statusClass = 'progress';
                        ?>
                        <tr data-status="<?php echo $issue['status']; ?>" data-category="<?php echo $cat; ?>"
                            data-date="<?php echo $issue['created_at'] ? date('Y-m-d', strtotime($issue['created_at'])) : ''; ?>">
                            <td>
                                <?php echo $i + 1; ?>
                            </td>
                            <td><span class="cat-badge <?php echo $catClass; ?>">
                                    <?php echo $cat; ?>
                                </span></td>
                            <td class="user-info">
                                <div class="name">👤
                                    <?php echo htmlspecialchars($issue['user_name'] ?? $issue['user_cin']); ?>
                                </div>
                                <div class="meta">
                                    📍
                                    <?php echo htmlspecialchars($issue['location'] ?? '-'); ?> |
                                    🏢
                                    <?php echo htmlspecialchars($issue['department'] ?? '-'); ?>
                                </div>
                            </td>
                            <td style="max-width: 200px; font-size: 0.9em;">
                                <?php echo htmlspecialchars($issue['issue']); ?>
                            </td>
                            <td style="max-width: 180px; font-size: 0.9em;">
                                <?php echo htmlspecialchars($issue['action_plan']); ?>
                            </td>
                            <td style="font-size: 0.9em;">
                                <?php echo htmlspecialchars($issue['responsible']); ?>
                            </td>
                            <td>
                                <?php echo $issue['due_date'] ? date('d/m', strtotime($issue['due_date'])) : '-'; ?>
                            </td>
                            <td style="font-size: 0.85em; color: #666;">
                                <?php echo $issue['created_at'] ? date('d/m H:i', strtotime($issue['created_at'])) : '-'; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php
                                    if ($issue['status'] === 'Open')
                                        echo '🔴 Open';
                                    elseif ($issue['status'] === 'In Progress')
                                        echo '🟡 In Progress';
                                    else
                                        echo '✅ Done';
                                    ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" class="action-form" style="display: flex; gap: 5px;">
                                    <input type="hidden" name="issue_id" value="<?php echo $issue['id']; ?>">

                                    <?php if ($issue['status'] === 'Deleted'): ?>
                                        <button type="submit" name="restore_issue" style="background:#28a745;"
                                            title="استعادة / Restore">♻️</button>
                                        <span style="color:red; font-size:0.8em; align-self:center;">Deleted</span>
                                    <?php else: ?>
                                        <select name="new_status">
                                            <option value="Open" <?php echo $issue['status'] === 'Open' ? 'selected' : ''; ?>>Open
                                            </option>
                                            <option value="In Progress" <?php echo $issue['status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="Done" <?php echo $issue['status'] === 'Done' ? 'selected' : ''; ?>>Done
                                            </option>
                                        </select>
                                        <button type="submit" name="update_status" title="حفظ الحالة / Save Status">💾</button>
                                        <button type="submit" name="delete_issue" style="background:#dc3545;"
                                            title="حذف (سلة المهملات) / Bin"
                                            onclick="return confirm('To Recycle Bin? / إلى سلة المحذوفات؟');">🗑️</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($issues)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px; color: #888;">
                                📭 لا توجد مشاكل مسجلة حالياً
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function filterTable() {
            const statusFilter = document.getElementById('filter-status').value;
            const categoryFilter = document.getElementById('filter-category').value;
            const dateFrom = document.getElementById('filter-date-from').value;
            const dateTo = document.getElementById('filter-date-to').value;
            const rows = document.querySelectorAll('#issues-table tbody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const status = row.dataset.status;
                const category = row.dataset.category;
                const rowDate = row.dataset.date || '';

                let show = true;
                if (statusFilter && status !== statusFilter) show = false;
                if (categoryFilter && category !== categoryFilter) show = false;
                if (dateFrom && rowDate && rowDate < dateFrom) show = false;
                if (dateTo && rowDate && rowDate > dateTo) show = false;

                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            // Update count indicator
            let counter = document.getElementById('filter-count');
            if (!counter) {
                counter = document.createElement('span');
                counter.id = 'filter-count';
                counter.style.cssText = 'font-size:0.85em; color:#667eea; font-weight:600; margin-right:8px;';
                document.querySelector('.filters-bar').appendChild(counter);
            }
            const total = rows.length;
            if (statusFilter || categoryFilter || dateFrom || dateTo) {
                counter.textContent = `📊 ${visibleCount} / ${total}`;
            } else {
                counter.textContent = '';
            }
        }

        function resetFilters() {
            document.getElementById('filter-status').value = '';
            document.getElementById('filter-category').value = '';
            document.getElementById('filter-date-from').value = '';
            document.getElementById('filter-date-to').value = '';
            filterTable();
        }
    </script>
</body>

</html>
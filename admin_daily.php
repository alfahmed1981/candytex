<?php
session_start();
require 'db.php';

// Security Check
if (!isset($_SESSION['user_cin']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied.");
}

// 1. Get Date (Default Today)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 2. Sorting Logic
$valid_sorts = ['name', 'department', 'location', 's_status', 'q_status', 'd_status', '5s_status', 'c_status'];
$sort_by = isset($_GET['sort']) && in_array($_GET['sort'], $valid_sorts) ? $_GET['sort'] : 'department';
$order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

// 3. Fetch Data
// We join Users with SQDC Daily logs for the *specific date*
// We perform 5 LEFT JOINs to get each category status in one row? 
// Or better: Fetch Users, then Fetch Logs, then Merge in PHP. 
// Merging in PHP is easier for the "Matrix" visual.

// A. Fetch All Managers
$sql_users = "SELECT * FROM users WHERE role = 'manager'";
if ($sort_by === 'name' || $sort_by === 'department' || $sort_by === 'location') {
    $sql_users .= " ORDER BY $sort_by $order";
} else {
    $sql_users .= " ORDER BY name ASC";
}
$stmt = $pdo->prepare($sql_users);
$stmt->execute();
$managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// B. Fetch All Logs for Date
$stmt_logs = $pdo->prepare("SELECT user_cin, category, status FROM sqdc_daily WHERE day_date = ?");
$stmt_logs->execute([$selected_date]);
$logs_raw = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

// Re-map logs: [cin][category] = status
$daily_data = [];
foreach ($logs_raw as $l) {
    $daily_data[$l['user_cin']][$l['category']] = $l['status'];
}

// C. Fetch Issues (Countermeasures) for Date
// Assuming we filter issues created on this date OR due on this date? 
// The prompt implies "Problems identified". Usually these are linked to Red/Orange status.
// Let's just count Open issues for this user? Or issues created today?
// Let's show "Issues Created Today".
$stmt_issues = $pdo->prepare("SELECT user_cin, COUNT(*) as issue_count FROM countermeasures WHERE DATE(created_at) = ? GROUP BY user_cin");
$stmt_issues->execute([$selected_date]);
$issues_map = $stmt_issues->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. PHP Sorting (if sorting by Status)
if (strpos($sort_by, '_status') !== false) {
    $cat_key = strtoupper(explode('_', $sort_by)[0]); // s_status -> S
    usort($managers, function ($a, $b) use ($daily_data, $cat_key, $order) {
        $statA = $daily_data[$a['cin']][$cat_key] ?? 'gray';
        $statB = $daily_data[$b['cin']][$cat_key] ?? 'gray';
        // Sort order: Red < Orange < Green < Blue < Gray (Priority)
        $priority = ['red' => 1, 'orange' => 2, 'green' => 3, 'blue' => 4, 'gray' => 5];
        $valA = $priority[$statA] ?? 99;
        $valB = $priority[$statB] ?? 99;

        return ($order === 'ASC') ? ($valA - $valB) : ($valB - $valA);
    });
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Daily Snapshot -
        <?= $selected_date ?>
    </title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .data-table th,
        .data-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .data-table th {
            background: #007bff;
            color: white;
            cursor: pointer;
            user-select: none;
        }

        .data-table th a {
            color: white;
            text-decoration: none;
            display: block;
        }

        /* Status Badges */
        .badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-weight: bold;
            color: white;
            text-align: center;
            display: inline-block;
            min-width: 30px;
        }

        .bg-green {
            background: #28a745;
        }

        .bg-orange {
            background: #fd7e14;
        }

        .bg-red {
            background: #dc3545;
        }

        .bg-blue {
            background: #17a2b8;
        }

        .bg-gray {
            background: #e9ecef;
            color: #ccc;
        }

        .issue-count {
            background: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }
    </style>
</head>

<body>

    <div class="top-nav">
        <div class="top-nav-header">
            <h3>📊 Daily Snapshot</h3>
        </div>
        <div class="nav-links">
            <a href="admin.php">🔙 Admin</a>
            <a href="admin_reports.php">📅 Monthly Report</a>
            <a href="index.php?logout=1" class="logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <h1>📸 Daily Overview / نظرة يومية</h1>

        <form class="controls">
            <label>Start Date:</label>
            <input type="date" name="date" value="<?= $selected_date ?>" onchange="this.form.submit()"
                style="padding: 5px;">
            <span style="flex-grow:1;"></span>
            <button type="button" onclick="window.print()" class="btn btn-secondary">🖨️ Print</button>
        </form>

        <table class="data-table">
            <thead>
                <tr>
                    <th><a href="?date=<?= $selected_date ?>&sort=name&order=<?= $order === 'ASC' ? 'desc' : 'asc' ?>">Manager
                            👤</a></th>
                    <th><a href="?date=<?= $selected_date ?>&sort=department&order=<?= $order === 'ASC' ? 'desc' : 'asc' ?>">Dept
                            🏭</a></th>
                    <th><a href="?date=<?= $selected_date ?>&sort=location&order=<?= $order === 'ASC' ? 'desc' : 'asc' ?>">Location
                            📍</a></th>

                    <?php foreach (['S', 'Q', 'D', '5S', 'C'] as $cat): ?>
                        <th style="text-align:center;">
                            <a
                                href="?date=<?= $selected_date ?>&sort=<?= strtolower($cat) ?>_status&order=<?= $order === 'ASC' ? 'desc' : 'asc' ?>">
                                <?= $cat ?>
                            </a>
                        </th>
                    <?php endforeach; ?>

                    <th>⚠️ Issues</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($managers as $m):
                    $cin = $m['cin'];
                    $row_stats = $daily_data[$cin] ?? [];
                    ?>
                    <tr>
                        <td>
                            <strong>
                                <?= htmlspecialchars($m['name']) ?>
                            </strong><br>
                            <small style="color:#666;">
                                <?= htmlspecialchars($cin) ?>
                            </small>
                        </td>
                        <td>
                            <?= htmlspecialchars($m['department'] ?? '-') ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($m['location'] ?? '-') ?>
                        </td>

                        <?php foreach (['S', 'Q', 'D', '5S', 'C'] as $cat):
                            $stat = $row_stats[$cat] ?? 'gray';
                            $class = 'bg-' . $stat;
                            if ($cat === '5S' && $stat === 'gray')
                                $class = 'bg-gray'; // Fix logical name if needed
                            ?>
                            <td style="text-align:center;">
                                <span class="badge <?= $class ?>">
                                    <?= strtoupper($stat[0]) ?>
                                </span>
                            </td>
                        <?php endforeach; ?>

                        <td style="text-align:center;">
                            <?php if (isset($issues_map[$cin])): ?>
                                <span class="issue-count">
                                    <?= $issues_map[$cin] ?> New
                                </span>
                            <?php else: ?>
                                <small style="color:#ccc;">-</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (empty($managers)): ?>
            <p style="text-align:center; padding:20px; color:#666;">No managers found.</p>
        <?php endif; ?>
    </div>

</body>

</html>
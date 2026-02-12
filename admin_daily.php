<?php
session_start();
require 'db.php';
require 'includes/auth.php';

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

// B. Fetch All Logs for Date (including updated_at for fill time tracking)
$stmt_logs = $pdo->prepare("SELECT user_cin, category, status, updated_at FROM sqdc_daily WHERE day_date = ?");
$stmt_logs->execute([$selected_date]);
$logs_raw = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

// Re-map logs: [cin][category] = status
// Also track fill time: latest updated_at per user
$daily_data = [];
$fill_times = []; // [cin] => latest updated_at
foreach ($logs_raw as $l) {
    $daily_data[$l['user_cin']][$l['category']] = $l['status'];
    $ts = $l['updated_at'];
    if ($ts && (!isset($fill_times[$l['user_cin']]) || $ts > $fill_times[$l['user_cin']])) {
        $fill_times[$l['user_cin']] = $ts;
    }
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

// 5. Extract Unique Lists for Filters
$departments = array_unique(array_column($managers, 'department'));
$locations = array_unique(array_column($managers, 'location'));
sort($departments);
sort($locations);

// Helper: Mask name (show first word + first letter of second word + ***)
function mask_name($name)
{
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '***';
    }
    return mb_substr($name, 0, 4) . '***';
}

// Helper: Mask CIN (show first 3 chars + ****)
function mask_cin($cin)
{
    if (mb_strlen($cin) <= 3)
        return $cin;
    return mb_substr($cin, 0, 3) . str_repeat('*', mb_strlen($cin) - 3);
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

        /* Fill Time Discipline Badges */
        .time-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 12px;
            color: white;
            display: inline-block;
        }

        .time-early {
            background: #28a745;
        }

        .time-late {
            background: #fd7e14;
        }

        .time-very-late {
            background: #dc3545;
        }

        .issue-count {
            background: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }

        .btn-wa {
            background: #25D366;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }

        .btn-wa:hover {
            background: #1da851;
        }

        .btn-wa-bulk {
            background: #25D366;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .btn-wa-bulk:hover {
            background: #1da851;
        }
    </style>
</head>

<body>

    <div class="top-nav no-print">
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
        <!-- PRINT HEADER (ISO) -->
        <div class="print-header">
            <div class="print-logo">
                🏭 CANDYTEX S.A.R.L<br>
                <small style="font-size:10pt; font-weight:normal;">Excellence in Textiles</small>
            </div>
            <div style="text-align:center;">
                <h2 style="margin:0;">SQD+C DAILY PERFORMANCE REPORT</h2>
                <p style="margin:5px 0;">Rapport Quotidien de Performance</p>
                <b>Date: <?= $selected_date ?></b>
            </div>
            <div class="doc-info">
                <b>Ref:</b> OP-SQDC-004<br>
                <b>Rev:</b> 2.1 (2026)<br>
                <b>Type:</b> Confidential
            </div>
        </div>

        <h1 class="no-print">📸 Daily Overview / نظرة يومية</h1>

        <form class="controls no-print">
            <label>Start Date:</label>
            <input type="date" name="date" value="<?= $selected_date ?>" onchange="this.form.submit()"
                style="padding: 5px;">
            <span style="flex-grow:1;"></span>
            <?php
            // Count managers who haven't filled
            $unfilled_managers = array_filter($managers, function ($m) use ($daily_data) {
                return empty($daily_data[$m['cin']]);
            });
            $unfilled_count = count($unfilled_managers);
            ?>
            <?php if ($unfilled_count > 0): ?>
                <button type="button" onclick="remindAll()" class="btn-wa-bulk no-print">📱 تذكير الكل
                    (<?= $unfilled_count ?>)</button>
            <?php endif; ?>
            <button type="button" onclick="window.print()" class="btn btn-secondary">🖨️ Print</button>
            <a href="admin_discipline.php?date=<?= $selected_date ?>" class="btn btn-secondary"
                style="background:#17a2b8; color:white; text-decoration:none; padding:10px 20px; border-radius:8px; font-weight:bold;">🏆
                ترتيب الانضباط</a>
        </form>

        <div class="stats-container" style="display:flex; gap:20px; margin-bottom:20px; flex-wrap:wrap;">
            <?php
            // Stats Calculation
            $total_managers = count($managers);
            $participated_count = count($daily_data);
            $participation_rate = $total_managers > 0 ? round(($participated_count / $total_managers) * 100) : 0;

            // Detailed Counters
            $good_counts = ['total' => 0, 'S' => 0, 'Q' => 0, 'D' => 0, '5S' => 0, 'C' => 0];
            $issue_counts = ['total' => 0, 'S' => 0, 'Q' => 0, 'D' => 0, '5S' => 0, 'C' => 0]; // Red + Orange
            
            foreach ($logs_raw as $log) {
                $cat = $log['category'];
                $stat = $log['status'];

                if ($stat === 'green') {
                    $good_counts['total']++;
                    if (isset($good_counts[$cat]))
                        $good_counts[$cat]++;
                } elseif ($stat === 'red' || $stat === 'orange') {
                    $issue_counts['total']++;
                    if (isset($issue_counts[$cat]))
                        $issue_counts[$cat]++;
                }
            }
            ?>

            <!-- Card 1: Participation -->
            <div class="stat-card"
                style="flex:1; background:white; padding:15px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.05); text-align:center; border-left:5px solid #007bff;">
                <h3 style="margin:0; font-size:14px; color:#555;">Participation / المشاركة</h3>
                <div style="font-size:24px; font-weight:bold; color:#007bff; margin:10px 0;">
                    <?= $participated_count ?> / <?= $total_managers ?>
                </div>
                <div style="font-size:12px; color:#777;">Rate: <?= $participation_rate ?>%</div>
            </div>

            <!-- Card 2: Good Performance -->
            <div class="stat-card"
                style="flex:1.5; background:white; padding:15px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.05); border-left:5px solid #28a745;">
                <div style="text-align:center; margin-bottom:10px;">
                    <h3 style="margin:0; font-size:14px; color:#555;">Good (Green) / جيد</h3>
                    <div style="font-size:24px; font-weight:bold; color:#28a745;"><?= $good_counts['total'] ?></div>
                </div>
                <div
                    style="display:flex; justify-content:space-around; font-size:11px; color:#555; background:#f0fff2; padding:5px; border-radius:5px;">
                    <span><b>S:</b> <?= $good_counts['S'] ?></span>
                    <span><b>Q:</b> <?= $good_counts['Q'] ?></span>
                    <span><b>D:</b> <?= $good_counts['D'] ?></span>
                    <span><b>5S:</b> <?= $good_counts['5S'] ?></span>
                    <span><b>C:</b> <?= $good_counts['C'] ?></span>
                </div>
            </div>

            <!-- Card 3: Issues -->
            <div class="stat-card"
                style="flex:1.5; background:white; padding:15px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.05); border-left:5px solid #dc3545;">
                <div style="text-align:center; margin-bottom:10px;">
                    <h3 style="margin:0; font-size:14px; color:#555;">Issues (Red/Orange) / مشاكل</h3>
                    <div style="font-size:24px; font-weight:bold; color:#dc3545;"><?= $issue_counts['total'] ?></div>
                </div>
                <div
                    style="display:flex; justify-content:space-around; font-size:11px; color:#555; background:#fff0f0; padding:5px; border-radius:5px;">
                    <span><b>S:</b> <?= $issue_counts['S'] ?></span>
                    <span><b>Q:</b> <?= $issue_counts['Q'] ?></span>
                    <span><b>D:</b> <?= $issue_counts['D'] ?></span>
                    <span><b>5S:</b> <?= $issue_counts['5S'] ?></span>
                    <span><b>C:</b> <?= $issue_counts['C'] ?></span>
                </div>
            </div>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th><a href="?date=<?= $selected_date ?>&sort=name&order=<?= $order === 'ASC' ? 'desc' : 'asc' ?>">Manager
                            👤</a></th>
                    <th><a
                            href="?date=<?= $selected_date ?>&sort=department&order=<?= $order === 'ASC' ? 'desc' : 'asc' ?>">Dept
                            🏭</a></th>
                    <th><a
                            href="?date=<?= $selected_date ?>&sort=location&order=<?= $order === 'ASC' ? 'desc' : 'asc' ?>">Location
                            📍</a></th>

                    <?php foreach (['S', 'Q', 'D', '5S', 'C'] as $cat): ?>
                        <th style="text-align:center;">
                            <a
                                href="?date=<?= $selected_date ?>&sort=<?= strtolower($cat) ?>_status&order=<?= $order === 'ASC' ? 'desc' : 'asc' ?>">
                                <?= $cat ?>
                            </a>
                        </th>
                    <?php endforeach; ?>

                    <th style="text-align:center;">⏰ وقت التعبئة</th>
                    <th>⚠️ Issues</th>
                    <th class="no-print">📱 تذكير</th>
                </tr>
                <!-- FILTER ROW -->
                <tr class="no-print" style="background:#f1f1f1;">
                    <td style="padding:5px;">
                        <input type="text" id="filterName" onkeyup="filterTable()" placeholder="🔍 Search Name/CIN..."
                            style="width:100%; padding:5px; border:1px solid #ccc; border-radius:4px;">
                    </td>
                    <td style="padding:5px;">
                        <select id="filterDept" onchange="filterTable()"
                            style="width:100%; padding:5px; border:1px solid #ccc; border-radius:4px;">
                            <option value="">All</option>
                            <?php foreach ($departments as $d):
                                if ($d)
                                    echo "<option value='$d'>$d</option>";
                            endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:5px;">
                        <select id="filterLoc" onchange="filterTable()"
                            style="width:100%; padding:5px; border:1px solid #ccc; border-radius:4px;">
                            <option value="">All</option>
                            <?php foreach ($locations as $l):
                                if ($l)
                                    echo "<option value='$l'>$l</option>";
                            endforeach; ?>
                        </select>
                    </td>
                    <?php foreach (['S', 'Q', 'D', '5S', 'C'] as $cat): ?>
                        <td style="padding:5px; text-align:center;">
                            <select id="filter<?= $cat ?>" onchange="filterTable()"
                                style="width:100%; padding:5px; border:1px solid #ccc; border-radius:4px;">
                                <option value="">All</option>
                                <option value="G" style="background:#28a745; color:white;">G</option>
                                <option value="O" style="background:#fd7e14; color:white;">O</option>
                                <option value="R" style="background:#dc3545; color:white;">R</option>
                                <option value="B" style="background:#17a2b8; color:white;">B</option>
                                <option value="gray" style="background:#e9ecef; color:#333;">Gray</option>
                            </select>
                        </td>
                    <?php endforeach; ?>
                    <td style="padding:5px; text-align:center;">
                        <select id="filterTime" onchange="filterTable()"
                            style="width:100%; padding:5px; border:1px solid #ccc; border-radius:4px;">
                            <option value="">All</option>
                            <option value="early" style="background:#28a745; color:white;">✅ منضبط</option>
                            <option value="late" style="background:#fd7e14; color:white;">⚠️ متأخر</option>
                            <option value="very-late" style="background:#dc3545; color:white;">🔴 متأخر جداً</option>
                            <option value="none" style="background:#e9ecef; color:#333;">❌ لم يملأ</option>
                        </select>
                    </td>
                    <td style="padding:5px;">
                        <select id="filterIssues" onchange="filterTable()"
                            style="width:100%; padding:5px; border:1px solid #ccc; border-radius:4px;">
                            <option value="">All</option>
                            <option value="yes">Has Issues</option>
                        </select>
                    </td>
                    <td class="no-print" style="padding:5px;"></td>
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
                                <span class="screen-only"><?= htmlspecialchars($m['name']) ?></span>
                                <span class="print-only"><?= htmlspecialchars(mask_name($m['name'])) ?></span>
                            </strong><br>
                            <small style="color:#666;">
                                <span class="screen-only"><?= htmlspecialchars($cin) ?></span>
                                <span class="print-only"><?= htmlspecialchars(mask_cin($cin)) ?></span>
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

                        <!-- Fill Time Column -->
                        <td style="text-align:center;">
                            <?php
                            $ft = $fill_times[$cin] ?? null;
                            if ($ft) {
                                $fill_dt = new DateTime($ft);
                                $fill_hour = (int) $fill_dt->format('H');
                                $fill_min = (int) $fill_dt->format('i');
                                $fill_time_str = $fill_dt->format('H:i');
                                $total_minutes = $fill_hour * 60 + $fill_min;

                                // Discipline: before 8:30 = early, before 10:00 = late, after = very late
                                if ($total_minutes <= 510) { // 8:30
                                    $time_class = 'time-early';
                                    $time_label = 'منضبط';
                                    $time_data = 'early';
                                } elseif ($total_minutes <= 600) { // 10:00
                                    $time_class = 'time-late';
                                    $time_label = 'متأخر';
                                    $time_data = 'late';
                                } else {
                                    $time_class = 'time-very-late';
                                    $time_label = 'متأخر جداً';
                                    $time_data = 'very-late';
                                }
                                ?>
                                <span class="time-badge <?= $time_class ?>" data-discipline="<?= $time_data ?>"
                                    title="<?= $time_label ?>">
                                    <?= $fill_time_str ?>
                                </span>
                            <?php } else { ?>
                                <span class="time-badge" data-discipline="none" style="background:#e9ecef; color:#999;">—</span>
                            <?php } ?>
                        </td>

                        <td style="text-align:center;">
                            <?php if (isset($issues_map[$cin])): ?>
                                <span class="issue-count">
                                    <?= $issues_map[$cin] ?> New
                                </span>
                            <?php else: ?>
                                <small style="color:#ccc;">-</small>
                            <?php endif; ?>
                        </td>
                        <td class="no-print" style="text-align:center;">
                            <?php
                            $phone = $m['phone'] ?? '';
                            $has_data = !empty($row_stats);
                            if ($phone && !$has_data):
                                $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                                if (substr($clean_phone, 0, 1) === '0')
                                    $clean_phone = '212' . substr($clean_phone, 1);
                                $wa_msg = urlencode("السلام عليكم 👋\n" . htmlspecialchars_decode($m['name']) . "\n\nتذكير: يرجى ملء لوحة SQD+C اليومية 📊\nالتاريخ: {$selected_date}\n\nرابط المنصة:\nhttps://candytex.ma/dash/\n\nشكراً لتعاونكم 🙏");
                                ?>
                                <a href="https://wa.me/<?= $clean_phone ?>?text=<?= $wa_msg ?>" target="_blank" class="btn-wa"
                                    title="تذكير واتساب">📱</a>
                            <?php elseif ($has_data): ?>
                                <small style="color:#28a745;">✅</small>
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

        <!-- ===================== -->
        <!-- DISCIPLINE RANKING (Print Only) -->
        <!-- ===================== -->
        <div id="disciplineRanking" style="display:none;">
            <!-- Print Header -->
            <div class="print-header">
                <div class="print-logo">
                    🏭 CANDYTEX S.A.R.L<br>
                    <small style="font-size:10pt; font-weight:normal;">Excellence in Textiles</small>
                </div>
                <div style="text-align:center;">
                    <h2 style="margin:0;">🏆 ترتيب انضباط التوقيت / Classement Ponctualité</h2>
                    <p style="margin:5px 0;">Discipline Ranking Report</p>
                    <b>Date: <?= $selected_date ?></b>
                </div>
                <div class="doc-info">
                    <b>Ref:</b> OP-SQDC-005<br>
                    <b>Rev:</b> 1.0 (2026)<br>
                    <b>Type:</b> Motivational
                </div>
            </div>

            <?php
            // Build ranking: only managers who filled, sorted by fill time (earliest first)
            $ranking = [];
            foreach ($managers as $m) {
                $cin = $m['cin'];
                $ft = $fill_times[$cin] ?? null;
                if ($ft) {
                    $fill_dt = new DateTime($ft);
                    $total_min = (int) $fill_dt->format('H') * 60 + (int) $fill_dt->format('i');
                    $ranking[] = [
                        'name' => mask_name($m['name']),
                        'cin' => mask_cin($cin),
                        'department' => $m['department'] ?? '-',
                        'location' => $m['location'] ?? '-',
                        'fill_time' => $fill_dt->format('H:i'),
                        'total_min' => $total_min,
                    ];
                }
            }
            // Sort by fill time (earliest first)
            usort($ranking, function ($a, $b) {
                return $a['total_min'] - $b['total_min'];
            });
            ?>

            <table class="data-table" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th style="width:50px; text-align:center;">🏅 المرتبة</th>
                        <th>👤 الاسم</th>
                        <th>🏭 القسم</th>
                        <th>📍 الموقع</th>
                        <th style="text-align:center;">⏰ وقت التعبئة</th>
                        <th style="text-align:center;">📊 الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ranking as $i => $r):
                        $rank = $i + 1;
                        if ($r['total_min'] <= 510) {
                            $tc = 'time-early';
                            $tl = '✅ منضبط';
                        } elseif ($r['total_min'] <= 600) {
                            $tc = 'time-late';
                            $tl = '⚠️ متأخر';
                        } else {
                            $tc = 'time-very-late';
                            $tl = '🔴 متأخر جداً';
                        }
                        // Medal for top 3
                        $medal = '';
                        if ($rank === 1)
                            $medal = '🥇';
                        elseif ($rank === 2)
                            $medal = '🥈';
                        elseif ($rank === 3)
                            $medal = '🥉';
                        ?>
                        <tr>
                            <td style="text-align:center; font-weight:bold; font-size:16px;">
                                <?= $medal ?: $rank ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($r['name']) ?></strong><br>
                                <small style="color:#666;"><?= htmlspecialchars($r['cin']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($r['department']) ?></td>
                            <td><?= htmlspecialchars($r['location']) ?></td>
                            <td style="text-align:center;">
                                <span class="time-badge <?= $tc ?>"><?= $r['fill_time'] ?></span>
                            </td>
                            <td style="text-align:center;"><?= $tl ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="text-align:center; margin-top:15px; font-size:12px; color:#666;">
                📊 المجموع: <?= count($ranking) ?> مشارك | تاريخ الطباعة: <?= date('Y-m-d H:i') ?>
            </div>
        </div>

        <!-- PRINT LEGEND -->
        <div class="print-legend">
            <strong>Key / المفتاح:</strong> &nbsp;
            <div class="legend-item"><span class="legend-color" style="background:#28a745;"></span> Green (Target Met)
            </div>
            <div class="legend-item"><span class="legend-color" style="background:#fd7e14;"></span> Orange (Warning)
            </div>
            <div class="legend-item"><span class="legend-color" style="background:#dc3545;"></span> Red (Issue/Stop)
            </div>
            <div class="legend-item"><span class="legend-color" style="background:#17a2b8;"></span> Blue (Info)</div>
            <br>
            <strong>Categories:</strong> S=Safety, Q=Quality, D=Delivery, 5S=Organization, C=Cost.
        </div>

        <!-- PRINT FOOTER via HTML (Note: Fixed position covers every page usually) -->
        <div class="print-footer">
            <div>Generé par système le: <?= date('Y-m-d H:i') ?> | User: <?= $_SESSION['user_name'] ?? 'Admin' ?></div>
            <div>
                Validation Manager: ____________________
                &nbsp;&nbsp;|&nbsp;&nbsp;
                Validation Director: ____________________
            </div>
            <div>Page <span class="page-number"></span></div>
        </div>
    </div>

    <script>
        function filterTable() {
            // Get input values
            const nameFilter = document.getElementById('filterName').value.toUpperCase();
            const deptFilter = document.getElementById('filterDept').value.toUpperCase();
            const locFilter = document.getElementById('filterLoc').value.toUpperCase();
            const issueFilter = document.getElementById('filterIssues').value;

            // KPI Filters
            const kpiFilters = {
                'S': document.getElementById('filterS').value,
                'Q': document.getElementById('filterQ').value,
                'D': document.getElementById('filterD').value,
                '5S': document.getElementById('filter5S').value,
                'C': document.getElementById('filterC').value
            };

            const table = document.querySelector('.data-table tbody');
            const rows = table.getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                if (cells.length < 10) continue;

                // 1. Name/CIN (Index 0)
                const nameText = cells[0].textContent || cells[0].innerText;
                const showName = nameText.toUpperCase().indexOf(nameFilter) > -1;

                // 2. Dept (Index 1)
                const deptText = cells[1].textContent || cells[1].innerText;
                const showDept = deptFilter === "" || deptText.toUpperCase().indexOf(deptFilter) > -1;

                // 3. Loc (Index 2)
                const locText = cells[2].textContent || cells[2].innerText;
                const showLoc = locFilter === "" || locText.toUpperCase().indexOf(locFilter) > -1;

                // 4. Fill Time filter (Index 8)
                const timeFilter = document.getElementById('filterTime').value;
                const timeBadge = cells[8].querySelector('.time-badge');
                const timeDiscipline = timeBadge ? timeBadge.getAttribute('data-discipline') : 'none';
                const showTime = timeFilter === "" || timeDiscipline === timeFilter;

                // 5. Issues (Index 9)
                const issueText = cells[9].textContent || cells[9].innerText;
                const hasIssues = issueText.trim() !== '-' && issueText.trim() !== '' && issueText.trim() !== '—';
                const showIssues = issueFilter === "" || (issueFilter === "yes" && hasIssues);

                // 5. KPIs
                let showKPIsClass = true;
                const kpiIndices = { 'S': 3, 'Q': 4, 'D': 5, '5S': 6, 'C': 7 }; // 8=time, 9=issues, 10=remind

                for (const [key, filterVal] of Object.entries(kpiFilters)) {
                    if (filterVal !== "") {
                        const cell = cells[kpiIndices[key]];
                        const badge = cell.querySelector('.badge');

                        let targetClass = '';
                        if (filterVal === 'G') targetClass = 'bg-green';
                        if (filterVal === 'O') targetClass = 'bg-orange';
                        if (filterVal === 'R') targetClass = 'bg-red';
                        if (filterVal === 'B') targetClass = 'bg-blue';
                        if (filterVal === 'gray') targetClass = 'bg-gray';

                        if (!badge || !badge.classList.contains(targetClass)) {
                            showKPIsClass = false;
                            break;
                        }
                    }
                }

                if (showName && showDept && showLoc && showTime && showIssues && showKPIsClass) {
                    rows[i].style.display = "";
                } else {
                    rows[i].style.display = "none";
                }
            }
        }

        // Remind All - opens WhatsApp links for unfilled managers
        function remindAll() {
            const waButtons = document.querySelectorAll('.btn-wa');
            if (waButtons.length === 0) {
                alert('✅ الكل قام بملء لوحته!');
                return;
            }
            const names = [];
            waButtons.forEach(btn => {
                const row = btn.closest('tr');
                if (row) names.push(row.cells[0].textContent.trim().split('\n')[0]);
            });

            if (confirm('📱 سيتم فتح واتساب لـ ' + waButtons.length + ' رئيس فريق:\n\n' + names.join('\n') + '\n\nمتابعة؟')) {
                let i = 0;
                function openNext() {
                    if (i < waButtons.length) {
                        window.open(waButtons[i].href, '_blank');
                        i++;
                        setTimeout(openNext, 1500);
                    }
                }
                openNext();
            }
        }

        // Print Discipline Ranking - hides main table, shows ranking, prints, then restores
        function printDiscipline() {
            const mainTable = document.querySelector('.data-table');
            const statsContainer = document.querySelector('.stats-container');
            const legend = document.querySelector('.print-legend');
            const ranking = document.getElementById('disciplineRanking');
            const h1 = document.querySelector('h1.no-print');

            // Hide main content
            mainTable.style.display = 'none';
            statsContainer.style.display = 'none';
            if (legend) legend.style.display = 'none';

            // Show ranking
            ranking.style.display = 'block';

            // Print
            window.print();

            // Restore after print
            setTimeout(() => {
                mainTable.style.display = '';
                statsContainer.style.display = '';
                if (legend) legend.style.display = '';
                ranking.style.display = 'none';
            }, 500);
        }
    </script>
</body>

</html>
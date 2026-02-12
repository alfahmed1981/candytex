<?php
session_start();
require 'db.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_cin']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied.");
}

// --- Date Parameters ---
$today = date('Y-m-d');
$selected_date = $_GET['date'] ?? $today;

// Selected month (YYYY-MM)
$selected_month = $_GET['month'] ?? date('Y-m', strtotime($selected_date));
$month_start = $selected_month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

// Selected week
$sel_dt = new DateTime($selected_date);
$week_start_dt = clone $sel_dt;
$week_start_dt->modify('monday this week');
$week_end_dt = clone $week_start_dt;
$week_end_dt->modify('+6 days');
$week_start = $week_start_dt->format('Y-m-d');
$week_end = $week_end_dt->format('Y-m-d');

// Active tab
$tab = $_GET['tab'] ?? 'daily';

// --- Fetch All Managers ---
$managers = $pdo->query("SELECT * FROM users WHERE role = 'manager' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$manager_map = [];
foreach ($managers as $m) {
    $manager_map[$m['cin']] = $m;
}

// --- Masking Helpers ---
function mask_name($name)
{
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '***';
    }
    return mb_substr($name, 0, 4) . '***';
}

function mask_cin($cin)
{
    if (mb_strlen($cin) <= 3)
        return $cin;
    return mb_substr($cin, 0, 3) . str_repeat('*', mb_strlen($cin) - 3);
}

// --- Time Discipline Classification ---
function classify_time($total_minutes)
{
    if ($total_minutes <= 510)
        return ['class' => 'time-early', 'label' => '✅ منضبط', 'key' => 'early'];
    if ($total_minutes <= 600)
        return ['class' => 'time-late', 'label' => '⚠️ متأخر', 'key' => 'late'];
    return ['class' => 'time-very-late', 'label' => '🔴 متأخر جداً', 'key' => 'very-late'];
}

// =============================================
// A. DAILY RANKING
// =============================================
$stmt_daily = $pdo->prepare("
    SELECT user_cin, MAX(updated_at) as last_fill 
    FROM sqdc_daily WHERE day_date = ? 
    GROUP BY user_cin
");
$stmt_daily->execute([$selected_date]);
$daily_fills = $stmt_daily->fetchAll(PDO::FETCH_KEY_PAIR);

$daily_ranking = [];
foreach ($daily_fills as $cin => $last_fill) {
    if (!isset($manager_map[$cin]))
        continue;
    $dt = new DateTime($last_fill);
    $total_min = (int) $dt->format('H') * 60 + (int) $dt->format('i');
    $daily_ranking[] = [
        'cin' => $cin,
        'name' => mask_name($manager_map[$cin]['name']),
        'cin_masked' => mask_cin($cin),
        'department' => $manager_map[$cin]['department'] ?? '-',
        'location' => $manager_map[$cin]['location'] ?? '-',
        'fill_time' => $dt->format('H:i'),
        'total_min' => $total_min,
        'discipline' => classify_time($total_min),
    ];
}
usort($daily_ranking, fn($a, $b) => $a['total_min'] - $b['total_min']);

// =============================================
// B. WEEKLY RANKING
// =============================================
$stmt_week = $pdo->prepare("
    SELECT user_cin, day_date, MAX(updated_at) as last_fill 
    FROM sqdc_daily 
    WHERE day_date BETWEEN ? AND ? 
    GROUP BY user_cin, day_date
");
$stmt_week->execute([$week_start, $week_end]);
$week_raw = $stmt_week->fetchAll(PDO::FETCH_ASSOC);

$week_data = []; // [cin] => [ 'times' => [], 'early_count' => 0 ]
foreach ($week_raw as $row) {
    $cin = $row['user_cin'];
    if (!isset($manager_map[$cin]))
        continue;
    if (!isset($week_data[$cin])) {
        $week_data[$cin] = ['times' => [], 'early_count' => 0, 'late_count' => 0, 'very_late_count' => 0, 'total_days' => 0];
    }
    $dt = new DateTime($row['last_fill']);
    $total_min = (int) $dt->format('H') * 60 + (int) $dt->format('i');
    $week_data[$cin]['times'][] = $total_min;
    $week_data[$cin]['total_days']++;
    $disc = classify_time($total_min);
    if ($disc['key'] === 'early')
        $week_data[$cin]['early_count']++;
    elseif ($disc['key'] === 'late')
        $week_data[$cin]['late_count']++;
    else
        $week_data[$cin]['very_late_count']++;
}

$weekly_ranking = [];
foreach ($week_data as $cin => $data) {
    $avg_min = count($data['times']) > 0 ? round(array_sum($data['times']) / count($data['times'])) : 9999;
    $avg_h = str_pad(floor($avg_min / 60), 2, '0', STR_PAD_LEFT);
    $avg_m = str_pad($avg_min % 60, 2, '0', STR_PAD_LEFT);

    $weekly_ranking[] = [
        'cin' => $cin,
        'name' => mask_name($manager_map[$cin]['name']),
        'cin_masked' => mask_cin($cin),
        'department' => $manager_map[$cin]['department'] ?? '-',
        'location' => $manager_map[$cin]['location'] ?? '-',
        'avg_time' => "$avg_h:$avg_m",
        'avg_min' => $avg_min,
        'total_days' => $data['total_days'],
        'early_count' => $data['early_count'],
        'late_count' => $data['late_count'],
        'very_late_count' => $data['very_late_count'],
        'discipline' => classify_time($avg_min),
        'score' => $data['early_count'] * 3 + $data['late_count'] * 1, // weighted score
    ];
}
usort($weekly_ranking, fn($a, $b) => $b['score'] - $a['score'] ?: $a['avg_min'] - $b['avg_min']);

// =============================================
// C. MONTHLY RANKING
// =============================================
$stmt_month = $pdo->prepare("
    SELECT user_cin, day_date, MAX(updated_at) as last_fill 
    FROM sqdc_daily 
    WHERE day_date BETWEEN ? AND ? 
    GROUP BY user_cin, day_date
");
$stmt_month->execute([$month_start, $month_end]);
$month_raw = $stmt_month->fetchAll(PDO::FETCH_ASSOC);

$month_data = [];
foreach ($month_raw as $row) {
    $cin = $row['user_cin'];
    if (!isset($manager_map[$cin]))
        continue;
    if (!isset($month_data[$cin])) {
        $month_data[$cin] = ['times' => [], 'early_count' => 0, 'late_count' => 0, 'very_late_count' => 0, 'total_days' => 0];
    }
    $dt = new DateTime($row['last_fill']);
    $total_min = (int) $dt->format('H') * 60 + (int) $dt->format('i');
    $month_data[$cin]['times'][] = $total_min;
    $month_data[$cin]['total_days']++;
    $disc = classify_time($total_min);
    if ($disc['key'] === 'early')
        $month_data[$cin]['early_count']++;
    elseif ($disc['key'] === 'late')
        $month_data[$cin]['late_count']++;
    else
        $month_data[$cin]['very_late_count']++;
}

$monthly_ranking = [];
foreach ($month_data as $cin => $data) {
    $avg_min = count($data['times']) > 0 ? round(array_sum($data['times']) / count($data['times'])) : 9999;
    $avg_h = str_pad(floor($avg_min / 60), 2, '0', STR_PAD_LEFT);
    $avg_m = str_pad($avg_min % 60, 2, '0', STR_PAD_LEFT);
    $pct = $data['total_days'] > 0 ? round(($data['early_count'] / $data['total_days']) * 100) : 0;

    $monthly_ranking[] = [
        'cin' => $cin,
        'name' => mask_name($manager_map[$cin]['name']),
        'cin_masked' => mask_cin($cin),
        'department' => $manager_map[$cin]['department'] ?? '-',
        'location' => $manager_map[$cin]['location'] ?? '-',
        'avg_time' => "$avg_h:$avg_m",
        'avg_min' => $avg_min,
        'total_days' => $data['total_days'],
        'early_count' => $data['early_count'],
        'late_count' => $data['late_count'],
        'very_late_count' => $data['very_late_count'],
        'early_pct' => $pct,
        'discipline' => classify_time($avg_min),
        'score' => $data['early_count'] * 3 + $data['late_count'] * 1,
    ];
}
usort($monthly_ranking, fn($a, $b) => $b['score'] - $a['score'] ?: $a['avg_min'] - $b['avg_min']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>🏆 ترتيب الانضباط -
        <?= $selected_date ?>
    </title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0;
            margin-bottom: 25px;
            border-bottom: 3px solid #e9ecef;
        }

        .tab-btn {
            padding: 12px 25px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            color: #777;
            border-bottom: 3px solid transparent;
            margin-bottom: -3px;
            transition: all 0.2s;
        }

        .tab-btn:hover {
            color: #333;
            background: #f8f9fa;
        }

        .tab-btn.active {
            color: #007bff;
            border-bottom-color: #007bff;
            background: #f0f7ff;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Controls */
        .controls-bar {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            background: #f8f9fa;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .controls-bar label {
            font-weight: 600;
            font-size: 13px;
            color: #555;
        }

        .controls-bar input,
        .controls-bar select {
            padding: 6px 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
        }

        /* Ranking Table */
        .rank-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .rank-table th {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 12px 10px;
            text-align: center;
            font-size: 13px;
        }

        .rank-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }

        .rank-table tbody tr:hover {
            background: #f0f7ff;
        }

        .rank-table td:nth-child(2) {
            text-align: right;
        }

        /* Medal */
        .medal {
            font-size: 22px;
        }

        .rank-num {
            font-weight: bold;
            color: #555;
            font-size: 16px;
        }

        /* Time Badge */
        .time-badge {
            padding: 4px 12px;
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

        /* Score bar */
        .score-bar {
            display: flex;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            min-width: 80px;
        }

        .score-bar .early {
            background: #28a745;
        }

        .score-bar .late {
            background: #fd7e14;
        }

        .score-bar .vlate {
            background: #dc3545;
        }

        /* Summary Cards */
        .summary-cards {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .s-card {
            flex: 1;
            min-width: 150px;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-top: 4px solid #ccc;
        }

        .s-card h4 {
            margin: 0 0 5px;
            font-size: 13px;
            color: #666;
        }

        .s-card .val {
            font-size: 28px;
            font-weight: bold;
        }

        /* Period label */
        .period-label {
            font-size: 13px;
            color: #666;
            background: #f0f0f0;
            padding: 6px 14px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 15px;
        }

        /* Print btn */
        .btn-print {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        .btn-print:hover {
            background: #138496;
        }

        @media print {

            .tabs,
            .controls-bar,
            .top-nav,
            .btn-print {
                display: none !important;
            }

            .tab-content {
                display: block !important;
            }

            .time-badge,
            .time-early,
            .time-late,
            .time-very-late {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .score-bar,
            .score-bar .early,
            .score-bar .late,
            .score-bar .vlate {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .rank-table th {
                background: #333 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .s-card {
                box-shadow: none;
                border: 1px solid #ccc;
            }
        }
    </style>
</head>

<body>
    <!-- NAV -->
    <div class="top-nav no-print">
        <div class="top-nav-header">
            <h3>🏆 ترتيب الانضباط</h3>
        </div>
        <div class="nav-links">
            <a href="admin.php">🔙 Admin</a>
            <a href="admin_daily.php?date=<?= $selected_date ?>">📊 Daily Snapshot</a>
            <a href="admin_reports.php">📅 Monthly Report</a>
            <a href="index.php?logout=1" class="logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- PRINT HEADER -->
        <div class="print-header">
            <div class="print-logo">🏭 CANDYTEX S.A.R.L<br><small style="font-size:10pt; font-weight:normal;">Excellence
                    in Textiles</small></div>
            <div style="text-align:center;">
                <h2 style="margin:0;">🏆 ترتيب انضباط التوقيت</h2>
                <p style="margin:5px 0;">Classement Ponctualité / Discipline Ranking</p>
            </div>
            <div class="doc-info"><b>Ref:</b> OP-SQDC-005<br><b>Rev:</b> 1.0 (2026)<br><b>Type:</b> Motivational</div>
        </div>

        <!-- TABS -->
        <div class="tabs no-print">
            <button class="tab-btn <?= $tab === 'daily' ? 'active' : '' ?>" onclick="switchTab('daily')">📅
                يومي</button>
            <button class="tab-btn <?= $tab === 'weekly' ? 'active' : '' ?>" onclick="switchTab('weekly')">📆
                أسبوعي</button>
            <button class="tab-btn <?= $tab === 'monthly' ? 'active' : '' ?>" onclick="switchTab('monthly')">🗓️
                شهري</button>
        </div>

        <!-- ===================== -->
        <!-- TAB 1: DAILY -->
        <!-- ===================== -->
        <div id="tab-daily" class="tab-content <?= $tab === 'daily' ? 'active' : '' ?>">
            <div class="controls-bar no-print">
                <label>التاريخ:</label>
                <input type="date" value="<?= $selected_date ?>" onchange="location.href='?tab=daily&date='+this.value">
                <span style="flex-grow:1;"></span>
                <button class="btn-print" onclick="window.print()">🖨️ طباعة</button>
            </div>

            <div class="period-label">📅 يوم
                <?= $selected_date ?>
            </div>

            <?php
            $d_early = count(array_filter($daily_ranking, fn($r) => $r['discipline']['key'] === 'early'));
            $d_late = count(array_filter($daily_ranking, fn($r) => $r['discipline']['key'] === 'late'));
            $d_vlate = count(array_filter($daily_ranking, fn($r) => $r['discipline']['key'] === 'very-late'));
            $d_absent = count($managers) - count($daily_ranking);
            ?>
            <div class="summary-cards">
                <div class="s-card" style="border-top-color:#007bff;">
                    <h4>المشاركون</h4>
                    <div class="val" style="color:#007bff;">
                        <?= count($daily_ranking) ?>/
                        <?= count($managers) ?>
                    </div>
                </div>
                <div class="s-card" style="border-top-color:#28a745;">
                    <h4>✅ منضبط</h4>
                    <div class="val" style="color:#28a745;">
                        <?= $d_early ?>
                    </div>
                </div>
                <div class="s-card" style="border-top-color:#fd7e14;">
                    <h4>⚠️ متأخر</h4>
                    <div class="val" style="color:#fd7e14;">
                        <?= $d_late ?>
                    </div>
                </div>
                <div class="s-card" style="border-top-color:#dc3545;">
                    <h4>🔴 متأخر جداً</h4>
                    <div class="val" style="color:#dc3545;">
                        <?= $d_vlate ?>
                    </div>
                </div>
                <div class="s-card" style="border-top-color:#adb5bd;">
                    <h4>❌ لم يملأ</h4>
                    <div class="val" style="color:#adb5bd;">
                        <?= $d_absent ?>
                    </div>
                </div>
            </div>

            <table class="rank-table">
                <thead>
                    <tr>
                        <th style="width:60px;">المرتبة</th>
                        <th>الاسم</th>
                        <th>القسم</th>
                        <th>الموقع</th>
                        <th>⏰ الوقت</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_ranking as $i => $r):
                        $rank = $i + 1;
                        $medal = match ($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => ''};
                        ?>
                        <tr>
                            <td>
                                <?= $medal ? "<span class='medal'>$medal</span>" : "<span class='rank-num'>$rank</span>" ?>
                            </td>
                            <td style="text-align:right;">
                                <strong>
                                    <?= htmlspecialchars($r['name']) ?>
                                </strong><br>
                                <small style="color:#999;">
                                    <?= htmlspecialchars($r['cin_masked']) ?>
                                </small>
                            </td>
                            <td>
                                <?= htmlspecialchars($r['department']) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($r['location']) ?>
                            </td>
                            <td><span class="time-badge <?= $r['discipline']['class'] ?>">
                                    <?= $r['fill_time'] ?>
                                </span></td>
                            <td>
                                <?= $r['discipline']['label'] ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($daily_ranking)): ?>
                        <tr>
                            <td colspan="6" style="padding:30px; color:#999;">لا توجد بيانات لهذا اليوم</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ===================== -->
        <!-- TAB 2: WEEKLY -->
        <!-- ===================== -->
        <div id="tab-weekly" class="tab-content <?= $tab === 'weekly' ? 'active' : '' ?>">
            <div class="controls-bar no-print">
                <label>اختر تاريخاً في الأسبوع:</label>
                <input type="date" value="<?= $selected_date ?>"
                    onchange="location.href='?tab=weekly&date='+this.value">
                <span style="flex-grow:1;"></span>
                <button class="btn-print" onclick="window.print()">🖨️ طباعة</button>
            </div>

            <div class="period-label">📆 الأسبوع:
                <?= $week_start ?> ←
                <?= $week_end ?>
            </div>

            <?php
            $w_total = count($weekly_ranking);
            $w_early_avg = $w_total > 0 ? round(array_sum(array_column($weekly_ranking, 'early_count')) / $w_total, 1) : 0;
            ?>
            <div class="summary-cards">
                <div class="s-card" style="border-top-color:#007bff;">
                    <h4>المشاركون</h4>
                    <div class="val" style="color:#007bff;">
                        <?= $w_total ?>
                    </div>
                </div>
                <div class="s-card" style="border-top-color:#28a745;">
                    <h4>متوسط أيام الانضباط</h4>
                    <div class="val" style="color:#28a745;">
                        <?= $w_early_avg ?>
                    </div>
                </div>
            </div>

            <table class="rank-table">
                <thead>
                    <tr>
                        <th style="width:60px;">المرتبة</th>
                        <th>الاسم</th>
                        <th>القسم</th>
                        <th>أيام</th>
                        <th>✅ منضبط</th>
                        <th>⚠️ متأخر</th>
                        <th>🔴 جداً</th>
                        <th>⏰ المعدل</th>
                        <th>📊 التقدم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($weekly_ranking as $i => $r):
                        $rank = $i + 1;
                        $medal = match ($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => ''};
                        $total = $r['total_days'] ?: 1;
                        $ep = round(($r['early_count'] / $total) * 100);
                        $lp = round(($r['late_count'] / $total) * 100);
                        $vp = 100 - $ep - $lp;
                        ?>
                        <tr>
                            <td>
                                <?= $medal ? "<span class='medal'>$medal</span>" : "<span class='rank-num'>$rank</span>" ?>
                            </td>
                            <td style="text-align:right;">
                                <strong>
                                    <?= htmlspecialchars($r['name']) ?>
                                </strong><br>
                                <small style="color:#999;">
                                    <?= htmlspecialchars($r['cin_masked']) ?>
                                </small>
                            </td>
                            <td>
                                <?= htmlspecialchars($r['department']) ?>
                            </td>
                            <td><strong>
                                    <?= $r['total_days'] ?>
                                </strong></td>
                            <td style="color:#28a745; font-weight:bold;">
                                <?= $r['early_count'] ?>
                            </td>
                            <td style="color:#fd7e14; font-weight:bold;">
                                <?= $r['late_count'] ?>
                            </td>
                            <td style="color:#dc3545; font-weight:bold;">
                                <?= $r['very_late_count'] ?>
                            </td>
                            <td><span class="time-badge <?= $r['discipline']['class'] ?>">
                                    <?= $r['avg_time'] ?>
                                </span></td>
                            <td>
                                <div class="score-bar">
                                    <div class="early" style="width:<?= $ep ?>%;"></div>
                                    <div class="late" style="width:<?= $lp ?>%;"></div>
                                    <div class="vlate" style="width:<?= $vp ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($weekly_ranking)): ?>
                        <tr>
                            <td colspan="9" style="padding:30px; color:#999;">لا توجد بيانات لهذا الأسبوع</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ===================== -->
        <!-- TAB 3: MONTHLY -->
        <!-- ===================== -->
        <div id="tab-monthly" class="tab-content <?= $tab === 'monthly' ? 'active' : '' ?>">
            <div class="controls-bar no-print">
                <label>الشهر:</label>
                <input type="month" value="<?= $selected_month ?>"
                    onchange="location.href='?tab=monthly&month='+this.value+'&date='+this.value+'-01'">
                <span style="flex-grow:1;"></span>
                <button class="btn-print" onclick="window.print()">🖨️ طباعة</button>
            </div>

            <div class="period-label">🗓️ شهر
                <?= $selected_month ?> (
                <?= $month_start ?> →
                <?= $month_end ?>)
            </div>

            <?php
            $m_total = count($monthly_ranking);
            $m_early_avg = $m_total > 0 ? round(array_sum(array_column($monthly_ranking, 'early_pct')) / $m_total) : 0;
            ?>
            <div class="summary-cards">
                <div class="s-card" style="border-top-color:#007bff;">
                    <h4>المشاركون</h4>
                    <div class="val" style="color:#007bff;">
                        <?= $m_total ?>
                    </div>
                </div>
                <div class="s-card" style="border-top-color:#28a745;">
                    <h4>متوسط نسبة الانضباط</h4>
                    <div class="val" style="color:#28a745;">
                        <?= $m_early_avg ?>%
                    </div>
                </div>
            </div>

            <table class="rank-table">
                <thead>
                    <tr>
                        <th style="width:60px;">المرتبة</th>
                        <th>الاسم</th>
                        <th>القسم</th>
                        <th>أيام</th>
                        <th>✅ منضبط</th>
                        <th>⚠️ متأخر</th>
                        <th>🔴 جداً</th>
                        <th>نسبة الانضباط</th>
                        <th>⏰ المعدل</th>
                        <th>📊 التقدم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthly_ranking as $i => $r):
                        $rank = $i + 1;
                        $medal = match ($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => ''};
                        $total = $r['total_days'] ?: 1;
                        $ep = round(($r['early_count'] / $total) * 100);
                        $lp = round(($r['late_count'] / $total) * 100);
                        $vp = 100 - $ep - $lp;
                        // Color for percentage
                        $pct_color = $r['early_pct'] >= 80 ? '#28a745' : ($r['early_pct'] >= 50 ? '#fd7e14' : '#dc3545');
                        ?>
                        <tr>
                            <td>
                                <?= $medal ? "<span class='medal'>$medal</span>" : "<span class='rank-num'>$rank</span>" ?>
                            </td>
                            <td style="text-align:right;">
                                <strong>
                                    <?= htmlspecialchars($r['name']) ?>
                                </strong><br>
                                <small style="color:#999;">
                                    <?= htmlspecialchars($r['cin_masked']) ?>
                                </small>
                            </td>
                            <td>
                                <?= htmlspecialchars($r['department']) ?>
                            </td>
                            <td><strong>
                                    <?= $r['total_days'] ?>
                                </strong></td>
                            <td style="color:#28a745; font-weight:bold;">
                                <?= $r['early_count'] ?>
                            </td>
                            <td style="color:#fd7e14; font-weight:bold;">
                                <?= $r['late_count'] ?>
                            </td>
                            <td style="color:#dc3545; font-weight:bold;">
                                <?= $r['very_late_count'] ?>
                            </td>
                            <td><strong style="color:<?= $pct_color ?>; font-size:16px;">
                                    <?= $r['early_pct'] ?>%
                                </strong></td>
                            <td><span class="time-badge <?= $r['discipline']['class'] ?>">
                                    <?= $r['avg_time'] ?>
                                </span></td>
                            <td>
                                <div class="score-bar">
                                    <div class="early" style="width:<?= $ep ?>%;"></div>
                                    <div class="late" style="width:<?= $lp ?>%;"></div>
                                    <div class="vlate" style="width:<?= $vp ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthly_ranking)): ?>
                        <tr>
                            <td colspan="10" style="padding:30px; color:#999;">لا توجد بيانات لهذا الشهر</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PRINT FOOTER -->
        <div class="print-footer">
            <div>Generé par système le:
                <?= date('Y-m-d H:i') ?> | User:
                <?= $_SESSION['user_name'] ?? 'Admin' ?>
            </div>
            <div>Page <span class="page-number"></span></div>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.location.href = url.toString();
        }
    </script>
</body>

</html>
<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Access Control: Realistically, this should be for Admins only.
// For now, we allow any logged-in user to see the "Factory Status".
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$year = date('Y');
$month = date('m');
if (isset($_GET['year']))
    $year = intval($_GET['year']);
if (isset($_GET['month']))
    $month = intval($_GET['month']);

$month_name = date("F", mktime(0, 0, 0, $month, 10));

// --- WORST CASE LOGIC ---
// 1. Fetch ALL data for this month
$sql = "SELECT day_date, category, status FROM sqdc_daily 
        WHERE MONTH(day_date) = ? AND YEAR(day_date) = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$month, $year]);
$all_records = $stmt->fetchAll();

// 2. Aggregate
$factory_data = []; // [category][date] = 'status'

// Helper to rank status severity
function getSeverity($status)
{
    switch ($status) {
        case 'red':
            return 3;
        case 'orange':
            return 2;
        case 'green':
            return 1;
        default:
            return 0;
    }
}

$temp_agg = []; // [category][date] = max_severity

foreach ($all_records as $row) {
    $cat = $row['category'];
    $date = $row['day_date'];
    $sev = getSeverity($row['status']);

    if (!isset($temp_agg[$cat][$date]))
        $temp_agg[$cat][$date] = 0;

    // Max logic (Worst Case)
    if ($sev > $temp_agg[$cat][$date]) {
        $temp_agg[$cat][$date] = $sev;
    }
}

// Convert back to color strings
foreach ($temp_agg as $cat => $dates) {
    foreach ($dates as $date => $sev) {
        $color = 'gray';
        if ($sev == 3)
            $color = 'red';
        elseif ($sev == 2)
            $color = 'orange';
        elseif ($sev == 1)
            $color = 'green';

        $factory_data[$cat][$date] = $color;
    }
}

$columns = [
    'S' => ['title' => 'SAFETY', 'sub' => 'السلامة / SÉCURITÉ'],
    'Q' => ['title' => 'QUALITY', 'sub' => 'الجودة / QUALITÉ'],
    'D' => ['title' => 'DELIVERY', 'sub' => 'التسليم / LIVRAISON'],
    '5S' => ['title' => '5S / +', 'sub' => 'التحسين / Amélioration'],
    'C' => ['title' => 'COST', 'sub' => 'التكلفة / COÛT']
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factory Global Status</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <!-- Mobile Top Navigation -->
    <div class="top-nav">
        <div class="top-nav-header">
            <h3>🏭 Factory View</h3>
        </div>
        <div class="nav-links">
            <a href="index.php">📊 لوحة</a>
            <a href="global.php" class="active">🏭 عام</a>
            <a href="admin.php">⚙️ إدارة</a>
            <a href="index.php?logout=1" class="logout">خروج</a>
        </div>
        <form method="GET" class="date-filter">
            <input type="number" name="year" value="<?php echo $year; ?>" placeholder="سنة">
            <input type="number" name="month" value="<?php echo $month; ?>" placeholder="شهر">
            <button type="submit">🔍 عرض</button>
        </form>
    </div>

    <!-- Desktop Sidebar -->
    <div class="sidebar">
        <div class="profile">
            <h3>🏭 Factory View</h3>
            <p>Global Status</p>
        </div>
        <hr>
        <div class="filters">
            <form method="GET">
                <label>Year / السنة</label>
                <input type="number" name="year" value="<?php echo $year; ?>">
                <label>Month / الشهر</label>
                <input type="number" name="month" value="<?php echo $month; ?>">
                <button type="submit">Filter</button>
            </form>
        </div>
        <a href="index.php" class="logout-btn" style="background:#007bff;">📊 Board</a>
        <a href="index.php?logout=1" class="logout-btn" style="background:#dc3545;">Logout</a>
    </div>

    <div class="main-content">
        <div class="header">
            <h2>🏭 FACTORY GLOBAL STATUS <span style="font-size:0.6em; color:#666;">(الوضع العام للمصنع)</span></h2>
            <h3>
                <?php echo "$month_name $year"; ?>
            </h3>
        </div>

        <!-- Legend -->
        <div class="legend"
            style="padding:10px; background:#fff; margin-bottom:20px; border-radius:8px; display:flex; gap:20px; align-items:center;">
            <strong>Standard:</strong>
            <span style="display:flex; align-items:center; gap:5px;">
                <div class="day-box status-green" style="width:20px; height:20px;"></div> Met/OK
            </span>
            <span style="display:flex; align-items:center; gap:5px;">
                <div class="day-box status-orange" style="width:20px; height:20px;"></div> Action Req
            </span>
            <span style="display:flex; align-items:center; gap:5px;">
                <div class="day-box status-red" style="width:20px; height:20px;"></div> Missed/Danger
            </span>
        </div>

        <div class="sqdc-grid">
            <?php
            foreach ($columns as $key => $info) {
                echo "<div class='kpi-column'>";
                echo "<h3>{$info['title']}<br><small style='font-size:0.6em'>{$info['sub']}</small></h3>";
                echo "<div class='days-container'>";

                for ($d = 1; $d <= 31; $d++) {
                    $date_key = "$year-$month-$d";
                    $status = $factory_data[$key][$date_key] ?? 'gray';

                    // Formatting date for check
                    $real_date = checkdate($month, $d, $year);
                    $opacity = $real_date ? '1' : '0.3';

                    // No click event for Global view (Read-Only)
                    echo "<div class='day-box status-$status' style='opacity:$opacity'>$d</div>";
                }
                echo "</div></div>";
            }
            ?>
        </div>

        <div class="info-box" style="margin-top:20px; padding:20px; background:#fff; border-radius:8px;">
            <h3>ℹ️ How this works (كيف يعمل هذا):</h3>
            <p><strong>Worst Case Logic:</strong> If ANY department is <span
                    style="color:red; font-weight:bold;">RED</span>, the Factory is <span
                    style="color:red; font-weight:bold;">RED</span>.</p>
            <p><strong>قاعدة الأسوأ:</strong> إذا كان أي قسم <span style="color:red; font-weight:bold;">أحمر</span>، فإن
                المصنع بالكامل يظهر <span style="color:red; font-weight:bold;">بالأحمر</span>.</p>
        </div>
    </div>
</body>

</html>
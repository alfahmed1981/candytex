<?php
session_start();
require 'db.php';
require 'includes/auth.php';

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

// --- Fetch ALL data joined with users to get location ---
$sql = "SELECT sd.day_date, sd.category, sd.status, COALESCE(u.location, 'غير محدد') as location 
        FROM sqdc_daily sd
        JOIN users u ON sd.user_cin = u.cin
        WHERE MONTH(sd.day_date) = ? AND YEAR(sd.day_date) = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$month, $year]);
$all_records = $stmt->fetchAll();

// Fetch distinct locations
$loc_stmt = $pdo->query("SELECT DISTINCT location FROM users WHERE location IS NOT NULL AND location != '' ORDER BY location");
$all_locations = $loc_stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($all_locations))
    $all_locations = ['All'];

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

// Aggregate: [location][category][date] = max_severity
$temp_agg = [];
foreach ($all_records as $row) {
    $loc = $row['location'] ?: 'غير محدد';
    $cat = $row['category'];
    $date = $row['day_date'];
    $sev = getSeverity($row['status']);

    // Per-location
    if (!isset($temp_agg[$loc][$cat][$date]))
        $temp_agg[$loc][$cat][$date] = 0;
    if ($sev > $temp_agg[$loc][$cat][$date])
        $temp_agg[$loc][$cat][$date] = $sev;

    // Global aggregate
    if (!isset($temp_agg['__ALL__'][$cat][$date]))
        $temp_agg['__ALL__'][$cat][$date] = 0;
    if ($sev > $temp_agg['__ALL__'][$cat][$date])
        $temp_agg['__ALL__'][$cat][$date] = $sev;
}

// Convert severity back to color strings
$location_data = [];
foreach ($temp_agg as $loc => $cats) {
    foreach ($cats as $cat => $dates) {
        foreach ($dates as $date => $sev) {
            $color = 'gray';
            if ($sev == 3)
                $color = 'red';
            elseif ($sev == 2)
                $color = 'orange';
            elseif ($sev == 1)
                $color = 'green';
            $location_data[$loc][$cat][$date] = $color;
        }
    }
}

$loc_emoji = ['Candy 1' => '🏭', 'Candy 2' => '🏗️', 'Flora 1' => '🌸', '__ALL__' => '🌍', 'غير محدد' => '❓'];

$columns = [
    'S' => ['title' => 'S', 'sub' => 'السلامة'],
    'Q' => ['title' => 'Q', 'sub' => 'الجودة'],
    'D' => ['title' => 'D', 'sub' => 'التسليم'],
    '5S' => ['title' => '5S', 'sub' => 'التحسين'],
    'C' => ['title' => 'C', 'sub' => 'التكلفة']
];

// Build display order
$display_locations = array_merge(['__ALL__'], $all_locations);
if (isset($location_data['غير محدد']))
    $display_locations[] = 'غير محدد';
$display_locations = array_unique($display_locations);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factory Global Status</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .location-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border-top: 4px solid #007bff;
        }

        .location-section.loc-all {
            border-top-color: #6f42c1;
        }

        .location-section.loc-candy1 {
            border-top-color: #28a745;
        }

        .location-section.loc-candy2 {
            border-top-color: #fd7e14;
        }

        .location-section.loc-flora1 {
            border-top-color: #e83e8c;
        }

        .location-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .location-header h3 {
            margin: 0;
            font-size: 1.3em;
        }

        .location-stats {
            display: flex;
            gap: 8px;
        }

        .stat-pill {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }

        .tab-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 8px 16px;
            border: 2px solid #007bff;
            background: white;
            color: #007bff;
            border-radius: 20px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.2s;
        }

        .tab-btn:hover,
        .tab-btn.active {
            background: #007bff;
            color: white;
        }

        .tab-btn.active {
            box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
        }
    </style>
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
            <h2>🏭 FACTORY STATUS <span style="font-size:0.6em; color:#666;">(الوضع العام للمصنع)</span></h2>
            <h3><?php echo "$month_name $year"; ?></h3>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-bar">
            <button class="tab-btn active" onclick="showLocation('all')">🌍 الكل</button>
            <?php foreach ($all_locations as $loc): ?>
                <button class="tab-btn" onclick="showLocation('<?= htmlspecialchars($loc) ?>')">
                    <?= $loc_emoji[$loc] ?? '📍' ?>     <?= htmlspecialchars($loc) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Legend -->
        <div class="legend"
            style="padding:10px; background:#fff; margin-bottom:20px; border-radius:8px; display:flex; gap:20px; align-items:center;">
            <strong>Standard:</strong>
            <span style="display:flex; align-items:center; gap:5px;">
                <div class="day-box status-green" style="width:20px; height:20px;"></div> OK
            </span>
            <span style="display:flex; align-items:center; gap:5px;">
                <div class="day-box status-orange" style="width:20px; height:20px;"></div> Action
            </span>
            <span style="display:flex; align-items:center; gap:5px;">
                <div class="day-box status-red" style="width:20px; height:20px;"></div> Danger
            </span>
        </div>

        <?php foreach ($display_locations as $loc):
            $loc_grid = $location_data[$loc] ?? [];
            $loc_class = '';
            if ($loc === '__ALL__')
                $loc_class = 'loc-all';
            elseif ($loc === 'Candy 1')
                $loc_class = 'loc-candy1';
            elseif ($loc === 'Candy 2')
                $loc_class = 'loc-candy2';
            elseif ($loc === 'Flora 1')
                $loc_class = 'loc-flora1';

            $display_name = $loc === '__ALL__' ? '🌍 نظرة شاملة (All Locations)' : ($loc_emoji[$loc] ?? '📍') . ' ' . htmlspecialchars($loc);

            // Calculate stats for this location
            $green_count = 0;
            $red_count = 0;
            $orange_count = 0;
            foreach ($loc_grid as $cat => $dates) {
                foreach ($dates as $date => $color) {
                    if ($color === 'green')
                        $green_count++;
                    elseif ($color === 'red')
                        $red_count++;
                    elseif ($color === 'orange')
                        $orange_count++;
                }
            }
            ?>
            <div class="location-section <?= $loc_class ?>"
                data-location="<?= $loc === '__ALL__' ? 'all' : htmlspecialchars($loc) ?>">
                <div class="location-header">
                    <h3><?= $display_name ?></h3>
                    <div class="location-stats">
                        <?php if ($green_count): ?><span class="stat-pill" style="background:#28a745;">✓
                                <?= $green_count ?></span><?php endif; ?>
                        <?php if ($orange_count): ?><span class="stat-pill" style="background:#fd7e14;">⚠
                                <?= $orange_count ?></span><?php endif; ?>
                        <?php if ($red_count): ?><span class="stat-pill" style="background:#dc3545;">✗
                                <?= $red_count ?></span><?php endif; ?>
                        <?php if (!$green_count && !$orange_count && !$red_count): ?><span class="stat-pill"
                                style="background:#ccc;">لا بيانات</span><?php endif; ?>
                    </div>
                </div>

                <div class="sqdc-grid">
                    <?php foreach ($columns as $key => $info): ?>
                        <div class="kpi-column">
                            <h3><?= $info['title'] ?><br><small style="font-size:0.6em"><?= $info['sub'] ?></small></h3>
                            <div class="days-container">
                                <?php for ($d = 1; $d <= 31; $d++):
                                    $date_key = sprintf("%04d-%02d-%02d", $year, $month, $d);
                                    $status = $loc_grid[$key][$date_key] ?? 'gray';
                                    $real_date = checkdate($month, $d, $year);
                                    $opacity = $real_date ? '1' : '0.3';
                                    ?>
                                    <div class="day-box status-<?= $status ?>" style="opacity:<?= $opacity ?>"><?= $d ?></div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="info-box" style="margin-top:20px; padding:20px; background:#fff; border-radius:8px;">
            <h3>ℹ️ كيف يعمل:</h3>
            <p><strong>قاعدة الأسوأ:</strong> إذا كان أي مدير في الموقع <span
                    style="color:red; font-weight:bold;">أحمر</span>، فإن الموقع يظهر <span
                    style="color:red; font-weight:bold;">بالأحمر</span> في ذلك اليوم.</p>
            <p><strong>"نظرة شاملة"</strong> تعرض أسوأ حالة عبر جميع المواقع.</p>
        </div>
    </div>

    <script>
        function showLocation(loc) {
            // Update tabs
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            // Show/hide sections
            document.querySelectorAll('.location-section').forEach(section => {
                const sectionLoc = section.dataset.location;
                if (loc === 'all') {
                    section.style.display = ''; // Show all
                } else {
                    section.style.display = (sectionLoc === loc) ? '' : 'none';
                }
            });
        }
    </script>
</body>

</html>
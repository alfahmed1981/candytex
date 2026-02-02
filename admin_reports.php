<?php
session_start();
require 'db.php';

// Security Check: ONLY Admins
if (!isset($_SESSION['user_cin']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied. Admins Only.");
}

// Get selected Month/Year (default to current)
$selected_month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('m');
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');

// Calculate number of days in selected month
$days_in_month = (int)date('t', mktime(0, 0, 0, $selected_month, 1, $selected_year));

// Fetch all Managers
$stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'manager' ORDER BY name");
$stmt->execute();
$managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all Logs for this Month/Year
// We want to know how many categories (S,Q,D,5S,C) are filled per day per user.
// Group by user_cin and day_date
$sql = "
    SELECT 
        user_cin, 
        day_date, 
        COUNT(*) as categories_filled,
        GROUP_CONCAT(status) as statuses
    FROM sqdc_daily 
    WHERE MONTH(day_date) = ? AND YEAR(day_date) = ?
    GROUP BY user_cin, day_date
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$selected_month, $selected_year]);
$raw_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Re-organize logs into a structured array: $logs[cin][day] = count
$logs = [];
foreach ($raw_logs as $log) {
    $day = (int) date('d', strtotime($log['day_date']));
    $logs[$log['user_cin']][$day] = $log['categories_filled'];
}

// Previous/Next Month Logic
$prev_month = $selected_month - 1;
$prev_year = $selected_year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $selected_month + 1;
$next_year = $selected_year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

$month_name = date('F', mktime(0, 0, 0, $selected_month, 10)); // e.g. "January"
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Tracking Report - SQD+C</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 1400px;
            /* Wider for the matrix */
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
            /* Allow horizontal scroll if needed */
        }

        h1 {
            color: #333;
            margin-bottom: 5px;
        }

        .header-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }

        .btn {
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: #007bff;
        }

        .btn-secondary {
            background: #6c757d;
        }

        /* Matrix Table */
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: center;
        }

        .report-table th {
            background: #f1f1f1;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .report-table th.user-col {
            text-align: left;
            min-width: 150px;
            position: sticky;
            left: 0;
            background: #e9ecef;
            z-index: 20;
        }

        .report-table td.user-col {
            text-align: left;
            font-weight: bold;
            position: sticky;
            left: 0;
            background: #fff;
            border-right: 2px solid #dee2e6;
            z-index: 15;
        }

        /* Status Cells */
        .status-cell {
            width: 25px;
            height: 25px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            font-weight: bold;
        }

        .status-complete {
            /* 5/5 filled */
            background-color: #d4edda;
            color: #155724;
        }

        .status-partial {
            /* 1-4 filled */
            background-color: #fff3cd;
            color: #856404;
        }

        .status-empty {
            /* 0 filled */
            background-color: #f8d7da;
            color: #721c24;
            opacity: 0.3;
            /* Make empty days less distracting */
        }

        .weekend {
            background-color: #f8f9fa;
        }
    </style>
</head>

<body>

    <div class="top-nav">
        <div class="top-nav-header">
            <h3>📊 Tracking Report</h3>
        </div>
        <div class="nav-links">
            <a href="admin.php">🔙 Back to Admin</a>
            <a href="index.php?logout=1" class="logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="header-controls">
            <div>
                <h1>📅 Daily Submission Tracking</h1>
                <p>Monitor which managers have completed their SQDC logs.</p>
            </div>

            <div style="display:flex; gap:10px; align-items:center;">
                <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="btn btn-secondary">◀ Prev</a>
                <h2 style="margin:0; min-width: 200px; text-align:center;">
                    <?= $month_name ?>
                    <?= $selected_year ?>
                </h2>
                <a href="?month=<?= $next_month ?>&year=<?= $next_year ?>" class="btn btn-secondary">Next ▶</a>
            </div>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <th class="user-col">Manager Name</th>
                    <?php for ($d = 1; $d <= $days_in_month; $d++):
                        $timestamp = mktime(0, 0, 0, $selected_month, $d, $selected_year);
                        $day_name = date('D', $timestamp); // Mon, Tue...
                        $is_weekend = ($day_name == 'Sat' || $day_name == 'Sun');
                        ?>
                        <th class="<?= $is_weekend ? 'weekend' : '' ?>" title="<?= date('Y-m-d', $timestamp) ?>">
                            <?= $d ?><br>
                            <small style="font-weight:normal;">
                                <?= substr($day_name, 0, 1) ?>
                            </small>
                        </th>
                    <?php endfor; ?>
                    <th>%</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($managers as $mgr):
                    $cin = $mgr['cin'];
                    $filled_days = 0;
                    ?>
                    <tr>
                        <td class="user-col">
                            <?= htmlspecialchars($mgr['name']) ?>
                            <br><small style="color:#666; font-weight:normal;">
                                <?= htmlspecialchars($cin) ?>
                            </small>
                        </td>

                        <?php for ($d = 1; $d <= $days_in_month; $d++):
                            $timestamp = mktime(0, 0, 0, $selected_month, $d, $selected_year);
                            $day_name = date('D', $timestamp);
                            $is_weekend = ($day_name == 'Sat' || $day_name == 'Sun');

                            $count = isset($logs[$cin][$d]) ? $logs[$cin][$d] : 0;

                            // Determine Status
                            $symbol = "·";
                            $class = "status-empty";

                            if ($count >= 5) {
                                $symbol = "✓";
                                $class = "status-complete";
                                $filled_days++;
                            } elseif ($count > 0) {
                                $symbol = "!";
                                $class = "status-partial";
                            }
                            ?>
                            <td class="<?= $is_weekend ? 'weekend' : '' ?>">
                                <div class="status-cell <?= $class ?>" title="<?= $count ?>/5 categories filled">
                                    <?= $symbol ?>
                                </div>
                            </td>
                        <?php endfor; ?>

                        <!-- Stats Column -->
                        <?php
                        $compliance = round(($filled_days / $days_in_month) * 100);
                        $color = $compliance >= 80 ? 'green' : ($compliance >= 50 ? 'orange' : 'red');
                        ?>
                        <td style="font-weight:bold; color:<?= $color ?>">
                            <?= $compliance ?>%
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:20px; text-align:right;">
            <span style="display:inline-block; margin-left:15px;">
                <span class="status-cell status-complete">✓</span> Complete (5/5)
            </span>
            <span style="display:inline-block; margin-left:15px;">
                <span class="status-cell status-partial">!</span> Partial (1-4/5)
            </span>
            <span style="display:inline-block; margin-left:15px;">
                <span class="status-cell status-empty">·</span> Empty (0/5)
            </span>
        </div>

    </div>
</body>

</html>
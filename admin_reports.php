<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Security Check: ONLY Admins
if (!isset($_SESSION['user_cin']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied. Admins Only.");
}

// Get selected Month/Year (default to current)
$selected_month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('m');
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');

// Calculate number of days in selected month
$days_in_month = (int) date('t', mktime(0, 0, 0, $selected_month, 1, $selected_year));

// Fetch all Managers
$stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'manager' ORDER BY name");
$stmt->execute();
$managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all Logs for this Month/Year
$sql = "
    SELECT 
        user_cin, 
        day_date, 
        COUNT(*) as categories_filled
    FROM sqdc_daily 
    WHERE MONTH(day_date) = ? AND YEAR(day_date) = ? AND status != 'gray'
    GROUP BY user_cin, day_date
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$selected_month, $selected_year]);
$raw_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
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
            cursor: pointer;
            transition: transform 0.2s;
        }

        .status-cell:hover {
            transform: scale(1.2);
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
        }

        .status-complete {
            background-color: #d4edda;
            color: #155724;
        }

        .status-partial {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-empty {
            background-color: #f8d7da;
            color: #721c24;
            opacity: 0.3;
        }

        .weekend {
            background-color: #f8f9fa;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-box {
            background: white;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .kpi-buttons {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin: 20px 0;
        }

        .kpi-btn {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 10px 5px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
        }

        .kpi-btn:hover {
            transform: translateY(-3px);
        }

        .kpi-green {
            background: #28a745;
            color: white;
            border-color: #28a745;
        }

        .kpi-orange {
            background: #fd7e14;
            color: white;
            border-color: #fd7e14;
        }

        .kpi-red {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        .kpi-blue {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        .kpi-gray {
            background: #f8f9fa;
            color: #ccc;
            border-color: #ccc;
        }
    </style>
</head>

<body>

    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <div class="header-controls">
            <div>
                <h1>📅 Daily Submission Tracking</h1>
                <p>Monitor & Edit managers' SQDC logs. <small>(Click any cell to edit)</small></p>
            </div>

            <div style="display:flex; gap:10px; align-items:center;">
                <a href="?month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="btn btn-secondary">◀ Prev</a>
                <h2 style="margin:0; min-width: 200px; text-align:center;">
                    <?= $month_name ?> <?= $selected_year ?>
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
                        $day_name = date('D', $timestamp);
                        $is_weekend = ($day_name == 'Sat' || $day_name == 'Sun');
                        ?>
                        <th class="<?= $is_weekend ? 'weekend' : '' ?>" title="<?= date('Y-m-d', $timestamp) ?>">
                            <?= $d ?><br>
                            <small style="font-weight:normal;"><?= substr($day_name, 0, 1) ?></small>
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
                            <br><small style="color:#666; font-weight:normal;"><?= htmlspecialchars($cin) ?></small>
                        </td>

                        <?php for ($d = 1; $d <= $days_in_month; $d++):
                            $timestamp = mktime(0, 0, 0, $selected_month, $d, $selected_year);
                            $date_str = date('Y-m-d', $timestamp);
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
                                <div class="status-cell <?= $class ?>"
                                    onclick="openEditModal('<?= $cin ?>', '<?= $mgr['name'] ?>', '<?= $date_str ?>')">
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
    </div>

    <!-- DATA EDIT MODAL -->
    <div id="editModal" class="modal-overlay" onclick="if(event.target === this) closeEditModal()">
        <div class="modal-box">
            <h3 id="modalTitle">Edit Daily Status</h3>
            <p id="modalSub" style="color:#666; margin-bottom:20px;">Fetching data...</p>

            <div class="kpi-buttons" id="kpiContainer">
                <!-- KPIs will be injected here -->
            </div>

            <p style="font-size:12px; color:#888; margin-top:10px;">Click letters to cycle status</p>

            <button onclick="closeEditModal()" class="btn btn-secondary" style="margin-top:15px; width:100%;">Close &
                Refresh</button>
        </div>
    </div>

    <script>
        let currentCin = null;
        let currentDate = null;
        const statusOrder = ['green', 'orange', 'red', 'blue', 'gray'];
        const CSRF_TOKEN = '<?php echo csrf_token(); ?>';

        function openEditModal(cin, name, date) {
            currentCin = cin;
            currentDate = date;

            document.getElementById('modalTitle').innerText = name;
            document.getElementById('modalSub').innerText = date;
            document.getElementById('editModal').style.display = 'flex';
            document.getElementById('kpiContainer').innerHTML = '<p>Loading...</p>';

            // Fetch Details
            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_day_details',
                    target_cin: cin,
                    date: date
                })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        renderKpiButtons(data.data);
                    } else {
                        alert('Error fetching data');
                    }
                });
        }

        function renderKpiButtons(kpiData) {
            const container = document.getElementById('kpiContainer');
            container.innerHTML = '';

            ['S', 'Q', 'D', '5S', 'C'].forEach(kpi => {
                const status = kpiData[kpi] || 'gray';
                const btn = document.createElement('div');
                btn.className = `kpi-btn kpi-${status}`;
                btn.innerHTML = `${kpi}<br><small>${status}</small>`;
                btn.onclick = () => cycleStatus(kpi, status, btn);
                container.appendChild(btn);
            });
        }

        function cycleStatus(kpi, currentStatus, btnElement) {
            // Determine next status
            const currentIndex = statusOrder.indexOf(currentStatus);
            const nextStatus = statusOrder[(currentIndex + 1) % statusOrder.length];

            // Optimistic UI Update
            btnElement.className = `kpi-btn kpi-${nextStatus}`;
            btnElement.innerHTML = `${kpi}<br><small>${nextStatus}</small>`;
            btnElement.onclick = () => cycleStatus(kpi, nextStatus, btnElement);

            // Send Update
            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_day',
                    target_cin: currentCin,
                    date: currentDate,
                    kpi: kpi,
                    status: nextStatus,
                    csrf_token: CSRF_TOKEN
                })
            }).then(res => res.json()).then(data => {
                if (!data.success) {
                    // Revert on failure (simple alert for now)
                    alert('Save failed!');
                }
            });
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            location.reload(); // Refresh matrix to show updated summary
        }
    </script>

</body>

</html>
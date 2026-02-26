<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Security Check: ONLY Admins
if (!isset($_SESSION['user_cin']) || !is_admin()) {
    die("Access Denied. Admins Only.");
}

// Fetch database statistics
$dbname = "candytex_dash"; // default
try {
    $stmt = $pdo->query("SELECT database()");
    $dbname = $stmt->fetchColumn();
} catch (Exception $e) {
}

$sql = "SELECT 
            table_name AS 'Table', 
            table_rows AS 'Rows',
            (data_length + index_length) / 1024 / 1024 AS 'Size_MB'
        FROM information_schema.tables 
        WHERE table_schema = ? 
        ORDER BY table_rows DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$dbname]);
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also fetch some interesting usage metrics
$details = [];

// Get active vs inactive users
try {
    $details['Users Active'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
    $details['Users Pending'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
} catch (Exception $e) {
}

// Get total workers
try {
    $details['HR Employees Total'] = $pdo->query("SELECT COUNT(*) FROM hr_employees")->fetchColumn();
    $details['HR Attendance Logs'] = $pdo->query("SELECT COUNT(*) FROM hr_team_attendance")->fetchColumn();
} catch (Exception $e) {
}

// NCR / CAR ratios
try {
    $details['NCR Open'] = $pdo->query("SELECT COUNT(*) FROM ncr_reports WHERE status='Open'")->fetchColumn();
    $details['NCR Closed'] = $pdo->query("SELECT COUNT(*) FROM ncr_reports WHERE status='Closed'")->fetchColumn();
    $details['CAR Open'] = $pdo->query("SELECT COUNT(*) FROM car_reports WHERE status='Open'")->fetchColumn();
} catch (Exception $e) {
}

// Save locally so the AI agent can read it
$export_data = [
    'time' => date('Y-m-d H:i:s'),
    'tables' => $tables,
    'details' => $details
];
file_put_contents(__DIR__ . '/db_stats.json', json_encode($export_data, JSON_PRETTY_PRINT));

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Database Data Analysis - CandyTex</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .table-data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table-data th,
        .table-data td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .table-data th {
            background: #0b3c5d;
            color: white;
        }

        .grid-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #328cc1;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .card h4 {
            margin: 0 0 10px 0;
            color: #555;
        }

        .card p {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
            color: #0b3c5d;
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>
    <div class="main-content">
        <div class="container">
            <h2>📊 System Data Analysis (For AI Audit)</h2>
            <p style="color: green; font-weight:bold;">✅ Data has been analyzed and successfully exported for the AI
                Assistant.</p>
            <p>You can tell the AI that you have opened this page, so it can read the generated context.</p>

            <h3 style="margin-top: 30px;">Key Business Metrics</h3>
            <div class="grid-cards">
                <?php foreach ($details as $key => $val): ?>
                    <div class="card">
                        <h4>
                            <?= htmlspecialchars($key) ?>
                        </h4>
                        <p>
                            <?= number_format((float) $val) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3 style="margin-top: 30px;">Table Size & Density</h3>
            <table class="table-data">
                <thead>
                    <tr>
                        <th>Table Name</th>
                        <th>Row Count (Approx)</th>
                        <th>Size (MB)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tables as $t): ?>
                        <tr>
                            <td><strong>
                                    <?= htmlspecialchars($t['Table']) ?>
                                </strong></td>
                            <td>
                                <?= number_format($t['Rows']) ?>
                            </td>
                            <td>
                                <?= round($t['Size_MB'], 3) ?> MB
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>
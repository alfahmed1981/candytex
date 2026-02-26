<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Auth Check (Managers report, Admin/Maintenance resolves)
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];
$is_admin = is_admin();
$is_maintenance = false; // Could be expanded later for specific roles

// Auto-run schema migration
try {
    if (!function_exists('run_maintenance_sql')) {
        function run_maintenance_sql($pdo)
        {
            $file = __DIR__ . '/iso_maintenance_schema.sql';
            if (!file_exists($file))
                return;
            $sql = file_get_contents($file);
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $cleaned = trim($query);
                if (!empty($cleaned)) {
                    try {
                        $pdo->exec($cleaned);
                    } catch (PDOException $e) {
                    }
                }
            }
        }
    }
    run_maintenance_sql($pdo);
} catch (Exception $e) {
}

$msg = "";
$error = "";

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // 1. Report Breakdown
    if (isset($_POST['report_breakdown'])) {
        $machine_id = intval($_POST['machine_id']);
        $issue = trim($_POST['issue_description']);
        $priority = $_POST['priority'] ?? 'Medium';

        $ticket_number = 'MTN-' . date('Ymd') . '-' . rand(1000, 9999);

        try {
            // Create Ticket
            $stmt = $pdo->prepare("INSERT INTO maintenance_tickets (ticket_number, machine_id, reported_by_cin, issue_description, priority) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$ticket_number, $machine_id, $user_cin, $issue, $priority]);

            // Mark Machine as Down
            $pdo->prepare("UPDATE machines SET status = 'Down' WHERE id = ?")->execute([$machine_id]);

            audit_log($pdo, 'tpm_report', "Reported Breakdown: $ticket_number for Machine #$machine_id");
            $msg = "✅ Machine Breakdown Reported Successfully! Ticket: $ticket_number";
        } catch (PDOException $e) {
            $error = "Error saving report: " . $e->getMessage();
        }
    }

    // 2. Resolve Ticket (Admin/Maintenance Only)
    if (isset($_POST['resolve_ticket']) && $is_admin) {
        $ticket_id = intval($_POST['ticket_id']);
        $machine_id = intval($_POST['machine_id']);
        $downtime = intval($_POST['downtime_minutes']);
        $notes = trim($_POST['resolution_notes']);
        $parts = trim($_POST['parts_replaced']);

        try {
            // Close Ticket
            $stmt = $pdo->prepare("UPDATE maintenance_tickets SET status = 'Closed', downtime_minutes = ?, resolved_by_cin = ?, resolution_notes = ?, parts_replaced = ?, resolved_at = NOW() WHERE id = ?");
            $stmt->execute([$downtime, $user_cin, $notes, $parts, $ticket_id]);

            // Bring Machine Back Up
            $pdo->prepare("UPDATE machines SET status = 'Running', last_maintenance_date = CURRENT_DATE WHERE id = ?")->execute([$machine_id]);

            audit_log($pdo, 'tpm_resolve', "Resolved Ticket: #$ticket_id (Downtime: $downtime mins)");
            $msg = "🔧 Ticket Closed! Machine is back online.";
        } catch (PDOException $e) {
            $error = "Error resolving ticket: " . $e->getMessage();
        }
    }
}

// Fetch Core Data
$machines = [];
$open_tickets = [];
$recent_closed = [];
$mttr = 0;

try {
    $stmt_m = $pdo->query("SELECT * FROM machines ORDER BY department, name");
    if ($stmt_m)
        $machines = $stmt_m->fetchAll();

    $stmt_o = $pdo->query("SELECT t.*, m.name as machine_name, m.machine_code, u.name as reporter_name 
                             FROM maintenance_tickets t 
                             JOIN machines m ON t.machine_id = m.id 
                             LEFT JOIN users u ON t.reported_by_cin = u.cin 
                             WHERE t.status != 'Closed' 
                             ORDER BY t.priority DESC, t.reported_at DESC");
    if ($stmt_o)
        $open_tickets = $stmt_o->fetchAll();

    $stmt_c = $pdo->query("SELECT t.*, m.name as machine_name 
                              FROM maintenance_tickets t 
                              JOIN machines m ON t.machine_id = m.id 
                              WHERE t.status = 'Closed' 
                              ORDER BY t.resolved_at DESC LIMIT 5");
    if ($stmt_c)
        $recent_closed = $stmt_c->fetchAll();

    // Calculate MTTR (Mean Time To Repair in minutes) for the dashboard
    $stmt_mttr = $pdo->query("SELECT AVG(downtime_minutes) FROM maintenance_tickets WHERE status = 'Closed'");
    if ($stmt_mttr)
        $mttr = $stmt_mttr->fetchColumn() ?: 0;
} catch (PDOException $e) {
    // Tables might not exist yet if schema failed to load
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>ISO 9001 - Maintenance (TPM)</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
        }

        @media(max-width: 768px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }
        }

        .box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .box h3 {
            margin-top: 0;
            color: #0b3c5d;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .alert {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            color: white;
        }

        .btn-red {
            background: #dc3545;
        }

        .btn-green {
            background: #28a745;
        }

        .btn-blue {
            background: #007bff;
        }

        .ticket-card {
            border-left: 4px solid #dc3545;
            background: #fff8f8;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-High {
            background: #dc3545;
            color: white;
        }

        .badge-Critical {
            background: #8b0000;
            color: white;
        }

        .badge-Medium {
            background: #fd7e14;
            color: white;
        }

        .badge-Low {
            background: #28a745;
            color: white;
        }

        .badge-Running {
            background: #28a745;
            color: white;
        }

        .badge-Down {
            background: #dc3545;
            color: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th,
        td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #f8f9fa;
        }

        .stat-banner {
            background: #0b3c5d;
            color: white;
            padding: 15px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-item h2 {
            margin: 0;
            font-size: 28px;
        }

        .stat-item p {
            margin: 5px 0 0 0;
            font-size: 12px;
            opacity: 0.8;
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="main-content">
        <div class="container">
            <h2>🏭 Total Productive Maintenance (TPM)</h2>
            <p>ISO 9001: Infrastructure & Operational Environment Management</p>

            <?php if ($msg): ?>
                <div class="alert alert-success">
                    <?= $msg ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= $error ?>
                </div>
            <?php endif; ?>

            <div class="stat-banner">
                <div class="stat-item">
                    <h2>
                        <?= count($open_tickets) ?>
                    </h2>
                    <p>Machines Down (Active Tickets)</p>
                </div>
                <div class="stat-item">
                    <h2>
                        <?= number_format($mttr, 1) ?> <small>mins</small>
                    </h2>
                    <p>MTTR (Mean Time To Repair)</p>
                </div>
                <div class="stat-item">
                    <h2>
                        <?= count($machines) ?>
                    </h2>
                    <p>Total Tracked Machines</p>
                </div>
            </div>

            <div class="grid-layout">
                <!-- Left Column: Report Issue -->
                <div class="box">
                    <h3>🚨 Report Breakdown</h3>
                    <p style="font-size: 12px; color: #666;">Production managers: Please log machine breakdowns here to
                        calculate downtime (Delivery 'D' parameter).</p>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                        <div class="form-group">
                            <label>Select Machine</label>
                            <select name="machine_id" class="form-control" required>
                                <option value="">-- Choose Machine --</option>
                                <?php foreach ($machines as $m): ?>
                                    <option value="<?= $m['id'] ?>">
                                        <?= htmlspecialchars($m['machine_code'] . ' - ' . $m['name']) ?>
                                        (
                                        <?= $m['status'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority" class="form-control" required>
                                <option value="Low">Low (Affects efficiency loosely)</option>
                                <option value="Medium" selected>Medium (Standard Repair)</option>
                                <option value="High">High (Impacting Production Target)</option>
                                <option value="Critical">Critical (Whole line stopped)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Issue Description</label>
                            <textarea name="issue_description" class="form-control" rows="4" required
                                placeholder="What is wrong with the machine?"></textarea>
                        </div>

                        <button type="submit" name="report_breakdown" class="btn btn-red" style="width: 100%;">Submit
                            Work Order</button>
                    </form>
                </div>

                <!-- Right Column: Open Tickets & Resolution -->
                <div>
                    <div class="box">
                        <h3>🛠️ Active Work Orders (Downtime)</h3>
                        <?php if (empty($open_tickets)): ?>
                            <p style="color: green; font-weight: bold;">✅ All machines are running smoothly.</p>
                        <?php else: ?>
                            <?php foreach ($open_tickets as $t): ?>
                                <div class="ticket-card">
                                    <div class="ticket-header">
                                        <strong>
                                            <?= htmlspecialchars($t['ticket_number']) ?> :
                                            <?= htmlspecialchars($t['machine_name']) ?>
                                        </strong>
                                        <span class="badge badge-<?= $t['priority'] ?>">
                                            <?= $t['priority'] ?>
                                        </span>
                                    </div>
                                    <p style="font-size: 13px; margin: 5px 0;"><strong>Reported By:</strong>
                                        <?= htmlspecialchars($t['reporter_name'] ?: $t['reported_by_cin']) ?> at
                                        <?= $t['reported_at'] ?>
                                    </p>
                                    <p
                                        style="font-size: 14px; background: white; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                                        <?= nl2br(htmlspecialchars($t['issue_description'])) ?>
                                    </p>

                                    <?php if ($is_admin): ?>
                                        <div style="margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 10px;">
                                            <button type="button" class="btn btn-green"
                                                onclick="toggleResolveForm(<?= $t['id'] ?>)">Close Ticket / Resolve</button>

                                            <form method="POST" id="resolve_form_<?= $t['id'] ?>"
                                                style="display: none; margin-top: 15px; background: #e9ecef; padding: 15px; border-radius: 6px;">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                                <input type="hidden" name="machine_id" value="<?= $t['machine_id'] ?>">

                                                <div class="form-group">
                                                    <label>Total Downtime (Minutes) ⚠️</label>
                                                    <input type="number" name="downtime_minutes" class="form-control" required
                                                        min="1" placeholder="e.g. 45">
                                                </div>

                                                <div class="form-group">
                                                    <label>How was it fixed? (Notes)</label>
                                                    <textarea name="resolution_notes" class="form-control" rows="2"
                                                        required></textarea>
                                                </div>

                                                <div class="form-group">
                                                    <label>Parts Replaced (Optional)</label>
                                                    <input type="text" name="parts_replaced" class="form-control"
                                                        placeholder="e.g. Needles, Motor Belt">
                                                </div>

                                                <button type="submit" name="resolve_ticket" class="btn btn-blue">Save & Mark
                                                    Running</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="box">
                        <h3>📋 Recently Resolved Maintenance</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Machine</th>
                                    <th>Downtime</th>
                                    <th>Resolution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_closed)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center;">No history yet.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($recent_closed as $rc): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($rc['ticket_number']) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($rc['machine_name']) ?>
                                        </td>
                                        <td style="color: red; font-weight: bold;">
                                            <?= $rc['downtime_minutes'] ?> min
                                        </td>
                                        <td><small>
                                                <?= htmlspecialchars($rc['resolution_notes']) ?>
                                            </small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div> <!-- End Right Column -->
            </div>
        </div>
    </div>

    <script>
        function toggleResolveForm(id) {
            const form = document.getElementById('resolve_form_' + id);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>

</html>
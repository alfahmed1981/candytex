<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// HR Managers and Admins can generate payroll
require_hr_or_admin();

$is_hr = is_hr();
$hr_location_id = null;
if ($is_hr) {
    $stmt_hr = $pdo->prepare("SELECT l.id FROM users u JOIN locations l ON u.location COLLATE utf8mb4_unicode_ci = l.name COLLATE utf8mb4_unicode_ci WHERE u.cin = ?");
    $stmt_hr->execute([$_SESSION['user_cin']]);
    $hr_location_id = $stmt_hr->fetchColumn();
    if (!$hr_location_id) {
        die("⛔ Your account is not properly assigned to a factory location. Please contact Admin.");
    }
}

$msg = '';

$current_year = date('Y');
$current_month = date('n');

$sel_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
$sel_month = isset($_GET['month']) ? intval($_GET['month']) : $current_month;

// Payroll period is 26th of previous month to 25th of current month
$prev_month = $sel_month - 1;
$prev_year = $sel_year;
if ($prev_month == 0) {
    $prev_month = 12;
    $prev_year--;
}

$start_date = sprintf("%04d-%02d-26", $prev_year, $prev_month);
$end_date = sprintf("%04d-%02d-25", $sel_year, $sel_month);

// Handle Generation & Regeneration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payroll'])) {
    require_csrf();

    try {
        $pdo->beginTransaction();

        // Fetch active employees (and inactive ones who worked this period)
        $emp_query = "SELECT id, hourly_rate, payment_type FROM hr_employees";
        if ($is_hr) {
            $emp_query .= " WHERE location_id = " . intval($hr_location_id);
        }
        $emp_stmt = $pdo->query($emp_query);
        $employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);

        $ins_stmt = $pdo->prepare("INSERT INTO hr_payroll 
            (employee_id, payroll_month, payroll_year, period_start, period_end, total_hours, brut_salary, cnss_deduction, transport_allowance, advances, frais, net_salary, rounded_net, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft')
            ON DUPLICATE KEY UPDATE 
            total_hours=VALUES(total_hours), brut_salary=VALUES(brut_salary), net_salary=VALUES(net_salary), rounded_net=VALUES(rounded_net)");

        foreach ($employees as $emp) {
            $eid = $emp['id'];
            $rate = floatval($emp['hourly_rate']);
            $payment_type = $emp['payment_type'] ?? 'Hourly';

            // Sum hours worked in the period
            $hr_stmt = $pdo->prepare("SELECT SUM(hours_worked) FROM hr_attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ?");
            $hr_stmt->execute([$eid, $start_date, $end_date]);
            $total_hours = floatval($hr_stmt->fetchColumn() ?: 0);

            // Only generate record if they actually worked, or if they already have an existing payroll record that needs updating
            $check_stmt = $pdo->prepare("SELECT brut_salary, cnss_deduction, transport_allowance, advances, frais FROM hr_payroll WHERE employee_id = ? AND payroll_month = ? AND payroll_year = ?");
            $check_stmt->execute([$eid, $sel_month, $sel_year]);
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($total_hours > 0 || $existing) {

                $cnss = $existing ? floatval($existing['cnss_deduction']) : 0.00;
                $trans = $existing ? floatval($existing['transport_allowance']) : 0.00;
                $adv = $existing ? floatval($existing['advances']) : 0.00;
                $frais = $existing ? floatval($existing['frais']) : 0.00;
                $existing_brut = $existing ? floatval($existing['brut_salary']) : 0.00;

                // MATH RULES based on Excel vs Manual
                // If a brut salary was already imported from Excel (e.g. > 0), preserve it.
                // Otherwise, calculate it manually from the system's Rate.
                if ($existing_brut > 0) {
                    $brut = $existing_brut;
                } else {
                    if ($payment_type === 'Monthly') {
                        $brut = $rate; // Monthly rate is fixed
                    } else {
                        $brut = $total_hours * $rate; // Hourly computation
                    }
                }

                $net = $brut - $cnss - $adv + $trans + $frais;

                // ROUNDING RULE: (e.g. 2412.53 -> 2420)
                $rounded_net = ceil($net / 10) * 10;

                $ins_stmt->execute([
                    $eid,
                    $sel_month,
                    $sel_year,
                    $start_date,
                    $end_date,
                    $total_hours,
                    $brut,
                    $cnss,
                    $trans,
                    $adv,
                    $frais,
                    $net,
                    $rounded_net
                ]);
            }
        }

        $pdo->commit();
        audit_log($pdo, 'hr_generate_payroll', "Generated payroll for $sel_month/$sel_year");
        $msg = "<script>Swal.fire('Success', 'Payroll calculations updated based on attendance.', 'success');</script>";

    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "<script>Swal.fire('Error', 'Failed to generate: " . addslashes($e->getMessage()) . "', 'error');</script>";
    }
}

// Handle Adjustments (CNSS, Transport, Advances)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_adjustments'])) {
    require_csrf();
    try {
        $pdo->beginTransaction();
        $upd_stmt = $pdo->prepare("UPDATE hr_payroll SET cnss_deduction=?, advances=?, transport_allowance=?, frais=?, 
                                   net_salary = brut_salary - ? - ? + ? + ?, 
                                   rounded_net = CEIL((brut_salary - ? - ? + ? + ?) / 10) * 10 
                                   WHERE id=?");

        foreach ($_POST['adj'] as $pid => $data) {
            $cnss = floatval($data['cnss'] ?: 0);
            $adv = floatval($data['advances'] ?: 0);
            $trans = floatval($data['transport'] ?: 0);
            $frais = floatval($data['frais'] ?: 0);

            $upd_stmt->execute([$cnss, $adv, $trans, $frais, $cnss, $adv, $trans, $frais, $cnss, $adv, $trans, $frais, $pid]);
        }
        $pdo->commit();
        $msg = "<script>Swal.fire('Success', 'Adjustments saved. Net salaries recalculated.', 'success');</script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "<script>Swal.fire('Error', 'Failed to save: " . addslashes($e->getMessage()) . "', 'error');</script>";
    }
}

// Fetch Payroll Data
$query = "SELECT p.*, e.matricule, e.full_name, e.function_title, e.department, e.payment_type, e.hourly_rate 
                       FROM hr_payroll p 
                       JOIN hr_employees e ON p.employee_id = e.id 
                       WHERE p.payroll_month = ? AND p.payroll_year = ?";
$params = [$sel_month, $sel_year];

if ($is_hr) {
    $query .= " AND e.location_id = ?";
    $params[] = $hr_location_id;
}

$query .= " ORDER BY e.department, e.full_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payroll_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$tot_brut = 0;
$tot_cnss = 0;
$tot_trans = 0;
$tot_adv = 0;
$tot_frais = 0;
$tot_net = 0;
$tot_arrond = 0;
foreach ($payroll_records as $r) {
    $tot_brut += floatval($r['brut_salary']);
    $tot_cnss += floatval($r['cnss_deduction']);
    $tot_trans += floatval($r['transport_allowance']);
    $tot_adv += floatval($r['advances']);
    $tot_frais += floatval($r['frais']);
    $tot_net += floatval($r['net_salary']);
    $tot_arrond += floatval($r['rounded_net']);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR - Payroll Generation</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .summary-card h4 {
            margin: 0;
            color: #666;
            font-size: 0.9em;
        }

        .summary-card p {
            margin: 5px 0 0 0;
            font-size: 1.4em;
            font-weight: bold;
            color: #0b3c5d;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 0.85em;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: right;
        }

        th {
            background: #f8f9fa;
            text-align: center;
        }

        td.text-left {
            text-align: left;
        }

        .adj-input {
            width: 60px;
            padding: 4px;
            text-align: right;
            border: 1px solid #ccc;
            border-radius: 3px;
        }

        .row-total {
            background: #e8f5e9;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>
    <?= $msg ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h2>💵 Payroll Generator / إدارة كشف الرواتب</h2>
                <p>Period:
                    <?= $start_date ?> to
                    <?= $end_date ?>
                </p>
            </div>

            <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit" name="generate_payroll" class="btn-save" style="background:#28a745;"
                    onclick="return confirm('This will calculate Brut salaries based on attendance. Proceed?');">
                    🔄
                    <?= empty($payroll_records) ? 'Generate' : 'Recalculate' ?> Payslips
                </button>
            </form>
        </div>

        <!-- Month Filter -->
        <div class="filter-card"
            style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <form method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
                <div class="form-group" style="margin: 0;">
                    <label>Month</label>
                    <select name="month" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                            <option value="<?= $i ?>" <?= $i == $sel_month ? 'selected' : '' ?>>
                                <?= str_pad($i, 2, '0', STR_PAD_LEFT) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>Year</label>
                    <input type="number" name="year" value="<?= $sel_year ?>"
                        style="width:80px; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <button type="submit" class="btn-save" style="background:#0b3c5d;">Load</button>
            </form>
        </div>

        <?php if (!empty($payroll_records)): ?>
            <!-- Totals -->
            <div class="summary-cards">
                <div class="summary-card">
                    <h4>Total Brut</h4>
                    <p>
                        <?= number_format($tot_brut, 2) ?>
                    </p>
                </div>
                <div class="summary-card" style="border-bottom: 4px solid #dc3545;">
                    <h4>Total CNSS</h4>
                    <p>
                        <?= number_format($tot_cnss, 2) ?>
                    </p>
                </div>
                <div class="summary-card" style="border-bottom: 4px solid #dc3545;">
                    <h4>Total Advances</h4>
                    <p>
                        <?= number_format($tot_adv, 2) ?>
                    </p>
                </div>
                <div class="summary-card" style="border-bottom: 4px solid #f39c12;">
                    <h4>Total Frais</h4>
                    <p>
                        <?= number_format($tot_frais, 2) ?>
                    </p>
                </div>
                <div class="summary-card" style="border-bottom: 4px solid #28a745;">
                    <h4>Total Transport</h4>
                    <p>
                        <?= number_format($tot_trans, 2) ?>
                    </p>
                </div>
                <div class="summary-card" style="border-bottom: 4px solid #00cec9;">
                    <h4>Total NET rounded</h4>
                    <p>
                        <?= number_format($tot_arrond, 2) ?>
                    </p>
                </div>
            </div>

            <!-- Grid -->
            <form method="POST">
                <?= csrf_field() ?>
                <div style="overflow-x: auto; margin-bottom: 20px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Matricule</th>
                                <th>Name</th>
                                <th>Function</th>
                                <th>Taux (Rate)</th>
                                <th>Total Hrs (N/H)</th>
                                <th style="background:#e8f5e9;">BRUT</th>
                                <th style="background:#ffebee;">CNSS</th>
                                <th style="background:#ffebee;">AV (Advances)</th>
                                <th style="background:#fff3e0;">FRS (Frais)</th>
                                <th style="background:#e3f2fd;">TRANS</th>
                                <th style="background:#f3e5f5;">NET</th>
                                <th style="background:#e0f7fa;">ARROND (Rounded)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payroll_records as $r): ?>
                                <tr>
                                    <td class="text-left">
                                        <?= htmlspecialchars($r['matricule']) ?>
                                    </td>
                                    <td class="text-left" style="font-weight:bold;">
                                        <?= htmlspecialchars($r['full_name']) ?>
                                    </td>
                                    <td class="text-left">
                                        <?= htmlspecialchars($r['function_title']) ?>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <?= number_format($r['hourly_rate'], 2) ?>
                                        <br><small
                                            style="color:#888;"><?= $r['payment_type'] === 'Monthly' ? 'MAD/month' : 'MAD/h' ?></small>
                                    </td>
                                    <td style="font-weight:bold;">
                                        <?= number_format($r['total_hours'], 2) ?>
                                    </td>
                                    <td style="background:#e8f5e9; font-weight:bold;">
                                        <?= number_format($r['brut_salary'], 2) ?>
                                    </td>

                                    <!-- Inputs for manual adjustments -->
                                    <td style="background:#ffebee;">
                                        <input type="number" step="0.01" name="adj[<?= $r['id'] ?>][cnss]"
                                            value="<?= $r['cnss_deduction'] ?>" class="adj-input">
                                    </td>
                                    <td style="background:#ffebee;">
                                        <input type="number" step="0.01" name="adj[<?= $r['id'] ?>][advances]"
                                            value="<?= $r['advances'] ?>" class="adj-input">
                                    </td>
                                    <td style="background:#fff3e0;">
                                        <input type="number" step="0.01" name="adj[<?= $r['id'] ?>][frais]"
                                            value="<?= $r['frais'] ?>" class="adj-input">
                                    </td>
                                    <td style="background:#e3f2fd;">
                                        <input type="number" step="0.01" name="adj[<?= $r['id'] ?>][transport]"
                                            value="<?= $r['transport_allowance'] ?>" class="adj-input">
                                    </td>

                                    <td style="background:#f3e5f5;">
                                        <?= number_format($r['net_salary'], 3) ?>
                                    </td>
                                    <td style="background:#e0f7fa; font-weight:bold; font-size:1.1em;">
                                        <?= number_format($r['rounded_net'], 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="text-align: right;">
                    <button type="submit" name="save_adjustments" class="btn-save">💾 Save Adjustments & Recalculate
                        Nets</button>
                    <!-- Export to excel (UI only for now) -->
                    <button type="button" class="btn-details" onclick="window.print()" style="margin-left:10px;">🖨️ Print
                        Sheet</button>
                </div>
            </form>
        <?php else: ?>
            <div
                style="background: white; padding: 40px; text-align: center; border-radius: 8px; color: #666; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3>No payroll generated for
                    <?= str_pad($sel_month, 2, '0', STR_PAD_LEFT) ?>/
                    <?= $sel_year ?>
                </h3>
                <p>Ensure attendance is logged, then click "Generate Payslips" above.</p>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
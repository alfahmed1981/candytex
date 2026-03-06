<?php
session_start();
require 'db.php';
require 'includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Only Admins or HR can import bulk attendance
if (!isset($_SESSION['user_cin']) || (!is_admin() && !is_hr())) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized. Admin or HR access required.']);
    exit;
}

// Get the raw POST data
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

if (!isset($data['records']) || !is_array($data['records'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload format.']);
    exit;
}

// CSRF check
$csrf_token = '';
if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (isset($headers['X-CSRF-Token'])) {
        $csrf_token = $headers['X-CSRF-Token'];
    } elseif (isset($headers['X-Csrf-Token'])) {
        $csrf_token = $headers['X-Csrf-Token'];
    }
}
if (empty($csrf_token) && isset($data['csrf_token'])) {
    $csrf_token = $data['csrf_token'];
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($data['records']) || !is_array($data['records'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload format. Missing records array.']);
    exit;
}

$records = $data['records'];
$user_cin = $_SESSION['user_cin'];
$success_count = 0;
$skipped_count = 0;

try {
    $pdo->beginTransaction();

    // Prepare a fast bulk insert statement using ON DUPLICATE KEY UPDATE
    // This is much faster than running 12,000 separate queries.
    // However, since PDO prepared statements with thousands of params can hit limits,
    // we'll chunk them.

    // Fetch active employees (and track those already mapped)
    $emp_map = [];
    $stmt_emp = $pdo->query("SELECT matricule, id FROM hr_employees");
    while ($row = $stmt_emp->fetch(PDO::FETCH_ASSOC)) {
        $emp_map[(string) $row['matricule']] = $row['id'];
    }

    $stmt_ins_emp = $pdo->prepare("
        INSERT INTO hr_employees 
        (matricule, full_name, function_title, gender, hire_date, hourly_rate, payment_type, status, location_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?)
    ");

    $stmt_ins_att = $pdo->prepare("
        INSERT INTO hr_attendance (employee_id, work_date, hours_worked, status, recorded_by) 
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            hours_worked = VALUES(hours_worked), 
            status = VALUES(status)
    ");

    // NEW: Auto-logging absences continuously
    $stmt_ins_abs = $pdo->prepare("
        INSERT INTO hr_absences (employee_id, absence_type, start_date, end_date, recorded_by)
        VALUES (?, ?, ?, ?, ?)
    ");

    $absences_to_log = [];

    // First pass: Handle missing employees
    $current_location_id = null;
    if (isset($_SESSION['user_cin'])) {
        $loc_stmt = $pdo->prepare("SELECT l.id FROM users u JOIN locations l ON u.location COLLATE utf8mb4_unicode_ci = l.name COLLATE utf8mb4_unicode_ci WHERE u.cin = ?");
        $loc_stmt->execute([$_SESSION['user_cin']]);
        $current_location_id = $loc_stmt->fetchColumn() ?: 1; // Default to 1 if unknown
    }

    $unique_emps = [];
    foreach ($records as $r) {
        $mat = (string) $r['matricule'];
        if (!isset($unique_emps[$mat])) {
             $unique_emps[$mat] = $r;
        }
    }

    $new_emp_count = 0;
    foreach ($unique_emps as $mat => $r) {
        if (!isset($emp_map[$mat]) && !empty($r['full_name'])) {
            // Auto-create missing employee
            $hire_date = !empty($r['hire_date']) ? $r['hire_date'] : date('Y-m-d');
            $hourly_rate = isset($r['hourly_rate']) ? floatval($r['hourly_rate']) : 0.00;
            $payment_type = (isset($r['sheet_type']) && $r['sheet_type'] === 'MENS') ? 'Monthly' : 'Hourly';
            
            $stmt_ins_emp->execute([
                $mat, 
                $r['full_name'], 
                $r['function_title'], 
                $r['gender'], 
                $hire_date, 
                $hourly_rate, 
                $payment_type,
                $current_location_id
            ]);
            $emp_map[$mat] = $pdo->lastInsertId();
            $new_emp_count++;
        }
    }

    // Second pass: Insert attendance and group absences
    foreach ($records as $r) {
        $mat = (string) $r['matricule'];
        if (!isset($emp_map[$mat])) {
            $skipped_count++;
            continue; 
        }

        $emp_id = $emp_map[$mat];
        $date = $r['date'];
        $hours = floatval($r['hours']);
        $status = $r['status'];

        $stmt_ins_att->execute([$emp_id, $date, $hours, $status, $user_cin]);
        $success_count++;

        // Track standard absences for bulk logging into hr_absences
        if (in_array($status, ['M', 'MAT', 'ACC', 'A', 'AT'])) {
            $status_code = $status === 'AT' ? 'ACC' : $status;
            
            if (!isset($absences_to_log[$emp_id])) {
                 $absences_to_log[$emp_id] = [];
            }

            // If we already have a running block of the same type ending yesterday
            $prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
            $found_block = false;
            foreach ($absences_to_log[$emp_id] as &$block) {
                if ($block['type'] === $status_code && $block['end_date'] === $prev_date) {
                    $block['end_date'] = $date;
                    $found_block = true;
                    break;
                }
            }
            if (!$found_block) {
                $absences_to_log[$emp_id][] = [
                    'type' => $status_code,
                    'start_date' => $date,
                    'end_date' => $date
                ];
            }
        }
    }

    // Save grouped absences
    $abs_logged = 0;
    foreach ($absences_to_log as $emp_id => $blocks) {
        foreach ($blocks as $block) {
            // Check if it already exists to avoid duplicates
            $chk = $pdo->prepare("SELECT id FROM hr_absences WHERE employee_id = ? AND start_date = ? AND end_date = ? AND absence_type COLLATE utf8mb4_unicode_ci = ?");
            $chk->execute([$emp_id, $block['start_date'], $block['end_date'], $block['type']]);
            if ($chk->rowCount() == 0) {
                $stmt_ins_abs->execute([$emp_id, $block['type'], $block['start_date'], $block['end_date'], $user_cin]);
                $abs_logged++;
            }
        }
    }

    $pdo->commit();

    // Now insert payrolls (Using a separate transaction for safety, or same. Let's use the same)
    $stmt_cnss = $pdo->prepare("UPDATE hr_employees SET cnss_number = ? WHERE id = ? AND (cnss_number IS NULL OR cnss_number = '')");

    $stmt_payroll = $pdo->prepare("
        INSERT INTO hr_payroll 
        (employee_id, payroll_month, payroll_year, period_start, period_end, cnss_deduction, advances, frais, brut_salary, net_salary, rounded_net, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft')
        ON DUPLICATE KEY UPDATE 
            cnss_deduction = VALUES(cnss_deduction),
            advances = VALUES(advances),
            frais = VALUES(frais),
            brut_salary = VALUES(brut_salary),
            net_salary = VALUES(net_salary),
            rounded_net = VALUES(rounded_net)
    ");

    $payroll_count = 0;
    $cnss_updates = 0;
    $payroll_month = 12;
    $payroll_year = 2025;
    $period_start = "2025-11-26";
    $period_end = "2025-12-25";

    if (isset($data['payrolls']) && is_array($data['payrolls'])) {
        foreach ($data['payrolls'] as $p) {
            $mat = (string) $p['matricule'];
            if (!isset($emp_map[$mat]))
                continue;

            $emp_id = $emp_map[$mat];

            if (!empty($p['cnss'])) {
                $stmt_cnss->execute([$p['cnss'], $emp_id]);
                if ($stmt_cnss->rowCount() > 0)
                    $cnss_updates++;
            }

            $stmt_payroll->execute([
                $emp_id,
                $payroll_month,
                $payroll_year,
                $period_start,
                $period_end,
                $p['cnss_deduction'], // cnss_deduction
                $p['advances'],       // advances
                $p['frais'],          // frais (expenses)
                $p['brut'],           // brut_salary
                $p['net_salary'],     // net_salary
                $p['rounded_net']     // rounded_net
            ]);
            $payroll_count++;
        }
    }

    // Log the import
    audit_log($pdo, 'hr_excel_import', "Imported $success_count attendance & $payroll_count payrolls (New Emps: $new_emp_count, Absences: $abs_logged)");

    echo json_encode([
        'success' => true,
        'message' => "Successfully imported $success_count attendance records, $abs_logged absence periods, and $payroll_count payroll snapshots. Created $new_emp_count new employees!"
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

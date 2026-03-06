<?php
session_start();
require 'db.php';
require 'includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure no PHP warnings/notices corrupt the JSON output
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

// Only Admins or HR can import bulk attendance
if (!isset($_SESSION['user_cin']) || (!is_admin() && !is_hr())) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized. Admin or HR access required.']);
    exit;
}

// Get the raw POST data
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

if (!isset($data['records']) || !is_array($data['records'])) {
    ob_clean();
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
    ob_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($data['records']) || !is_array($data['records'])) {
    ob_clean();
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

    // Calculate period dates early
    $payroll_month = isset($data['target_month']) ? (int)$data['target_month'] : (int)date('m');
    $payroll_year = isset($data['target_year']) ? (int)$data['target_year'] : (int)date('Y');

    $min_date = '9999-12-31';
    $max_date = '0000-00-00';
    if (!empty($records)) {
        foreach ($records as $r) {
            if ($r['date'] < $min_date) $min_date = $r['date'];
            if ($r['date'] > $max_date) $max_date = $r['date'];
        }
    } else {
        $min_date = date("Y-m-26", strtotime("$payroll_year-$payroll_month-01 -1 month"));
        $max_date = date("Y-m-25", strtotime("$payroll_year-$payroll_month-01"));
    }
    $period_start = $min_date;
    $period_end = $max_date;

    // Second pass: Insert attendance and group absences
    $employee_absence_blocks = [];
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

        // Initialize current_block for this employee if not set
        if (!isset($employee_absence_blocks[$emp_id])) {
            $employee_absence_blocks[$emp_id] = [
                'current_block' => null,
                'blocks' => []
            ];
        }
        $current_block = &$employee_absence_blocks[$emp_id]['current_block'];
        $blocks = &$employee_absence_blocks[$emp_id]['blocks'];

        // Track standard absences for bulk logging into hr_absences
        // Normalize status for ACC/AT
        $normalized_status = $status;
        if ($status === 'AT') {
            $normalized_status = 'ACC';
        }

        if (in_array($normalized_status, ['A', 'M', 'MAT', 'ACC'])) { 
            if ($current_block && $current_block['type'] === $normalized_status) {
                // Check if dates are EXACTLY consecutive (1 day gap maximum)
                $diff = strtotime($date) - strtotime($current_block['end_date']);
                // 1 day = 86400 seconds. If gap is > 86400, it's not contiguous.
                if ($diff <= 86400) { 
                    $current_block['end_date'] = $date;
                } else {
                    // Gap detected (weekend or empty day), close current block and start a new one
                    $blocks[] = $current_block;
                    $current_block = ['type' => $normalized_status, 'start_date' => $date, 'end_date' => $date];
                }
            } else {
                // Different type of absence or no current block, close previous and start new
                if ($current_block) {
                    $blocks[] = $current_block;
                }
                $current_block = ['type' => $normalized_status, 'start_date' => $date, 'end_date' => $date];
            }
        } elseif ($status === 'P') {
            // If the employee is present, break any ongoing absence block immediately
            if ($current_block) {
                $blocks[] = $current_block;
                $current_block = null;
            }
        }
    }

    // After processing all records, close any remaining open absence blocks
    foreach ($employee_absence_blocks as $emp_id => &$data_for_emp) {
        if ($data_for_emp['current_block']) {
            $data_for_emp['blocks'][] = $data_for_emp['current_block'];
        }
        // Move the finalized blocks to absences_to_log
        $absences_to_log[$emp_id] = $data_for_emp['blocks'];
    }


    // Save grouped absences
    $abs_logged = 0;
    
    // Clear old absences in this period for these employees to prevent ghost duplicates from changed logic
    if (!empty($emp_map)) {
        $emp_ids = array_values($emp_map);
        $placeholders = implode(',', array_fill(0, count($emp_ids), '?'));
        // Delete any absence that overlaps with the current payroll period
        $del_abs_stmt = $pdo->prepare("DELETE FROM hr_absences WHERE employee_id IN ($placeholders) AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?))");
        $params_del = array_merge($emp_ids, [$period_start, $period_end, $period_start, $period_end]);
        $del_abs_stmt->execute($params_del);
    }

    foreach ($absences_to_log as $emp_id => $blocks) {
        foreach ($blocks as $block) {
            $stmt_ins_abs->execute([$emp_id, $block['type'], $block['start_date'], $block['end_date'], $user_cin]);
            $abs_logged++;
        }
    }

    $pdo->commit();

    // Now insert payrolls (Using a separate transaction for safety, or same. Let's use the same)
    $stmt_cnss = $pdo->prepare("UPDATE hr_employees SET cnss_number = ? WHERE id = ? AND (cnss_number IS NULL OR cnss_number = '')");

    $stmt_payroll = $pdo->prepare("
        INSERT INTO hr_payroll 
        (employee_id, payroll_month, payroll_year, period_start, period_end, total_hours, cnss_deduction, advances, frais, brut_salary, net_salary, rounded_net, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft')
        ON DUPLICATE KEY UPDATE 
            total_hours = VALUES(total_hours),
            cnss_deduction = VALUES(cnss_deduction),
            advances = VALUES(advances),
            frais = VALUES(frais),
            brut_salary = VALUES(brut_salary),
            net_salary = VALUES(net_salary),
            rounded_net = VALUES(rounded_net)
    ");

    $payroll_count = 0;
    $cnss_updates = 0;
    // Explicitly use target month and year sent from the frontend
    // (Already calculated above)

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
                isset($p['total_hours']) ? (float)$p['total_hours'] : 0, // total_hours
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

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => "Successfully imported $success_count attendance records, $abs_logged absence periods, and $payroll_count payroll snapshots. Created $new_emp_count new employees!"
    ]);

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_clean();
    http_response_code(500);
    // DEBUG: Output exact error message to JSON
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

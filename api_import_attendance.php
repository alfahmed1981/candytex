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

    // First, cache all matricules to IDs to avoid a subquery for every row
    $emp_map = [];
    $stmt_emp = $pdo->query("SELECT matricule, id FROM hr_employees WHERE status = 'Active'");
    while ($row = $stmt_emp->fetch(PDO::FETCH_ASSOC)) {
        $emp_map[(string) $row['matricule']] = $row['id'];
    }

    $stmt_ins = $pdo->prepare("
        INSERT INTO hr_attendance (employee_id, work_date, hours_worked, status, recorded_by) 
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            hours_worked = VALUES(hours_worked), 
            status = VALUES(status)
    ");

    foreach ($records as $r) {
        $mat = (string) $r['matricule'];
        if (!isset($emp_map[$mat])) {
            $skipped_count++;
            continue; // Skip if employee doesn't exist or is inactive
        }

        $emp_id = $emp_map[$mat];
        $date = $r['date'];
        $hours = floatval($r['hours']);
        $status = $r['status'];

        $stmt_ins->execute([$emp_id, $date, $hours, $status, $user_cin]);
        $success_count++;
    }

    $pdo->commit();

    // Now insert payrolls (Using a separate transaction for safety, or same. Let's use the same)
    $stmt_cnss = $pdo->prepare("UPDATE hr_employees SET cnss_number = ? WHERE id = ? AND (cnss_number IS NULL OR cnss_number = '')");

    $stmt_payroll = $pdo->prepare("
        INSERT INTO hr_payroll 
        (employee_id, payroll_month, payroll_year, period_start, period_end, cnss_deduction, advances, brut_salary, net_salary, rounded_net, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft')
        ON DUPLICATE KEY UPDATE 
            cnss_deduction = VALUES(cnss_deduction),
            advances = VALUES(advances),
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
                $p['brut'],           // brut_salary
                $p['net_salary'],     // net_salary
                $p['rounded_net']     // rounded_net
            ]);
            $payroll_count++;
        }
    }

    // Log the import
    audit_log($pdo, 'hr_excel_import', "Imported $success_count attendance & $payroll_count payrolls (CNSS Linked: $cnss_updates)");

    echo json_encode([
        'success' => true,
        'message' => "Successfully imported $success_count attendance records and $payroll_count payroll snapshots. Linked $cnss_updates new CNSS numbers!"
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

<?php
session_start();
require 'db.php';
require 'includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Only Admins can import bulk attendance
if (!isset($_SESSION['user_cin']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized. Admin access required.']);
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
$headers = getallheaders();
$csrf_token = '';
if (isset($headers['X-CSRF-Token'])) {
    $csrf_token = $headers['X-CSRF-Token'];
} elseif (isset($data['csrf_token'])) {
    $csrf_token = $data['csrf_token'];
}

if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
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

    // Log the import
    audit_log($pdo, 'hr_excel_import', "Imported $success_count attendance records from Excel");

    echo json_encode([
        'success' => true,
        'message' => "Successfully imported $success_count records. Skipped $skipped_count unknown matricules."
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

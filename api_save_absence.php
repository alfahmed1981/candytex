<?php
session_start();
require 'db.php';
require 'includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Only Admins or HR can record advanced absences
if (!isset($_SESSION['user_cin']) || (!is_admin() && !is_hr())) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized. Admin or HR access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Support both JSON payload and FormData
$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (strpos($contentType, 'application/json') !== false) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?: [];
} else {
    $data = $_POST;
}

// Verify CSRF token
$csrf_token = '';
if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (isset($headers['X-CSRF-Token']))
        $csrf_token = $headers['X-CSRF-Token'];
}
if (empty($csrf_token) && isset($data['csrf_token'])) {
    $csrf_token = $data['csrf_token'];
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

$employee_id = intval($data['employee_id'] ?? 0);
$type = trim($data['type'] ?? ''); // 'M', 'MAT', 'AT', 'MP', 'CP', 'R'
$start = trim($data['start_date'] ?? '');
$end = trim($data['end_date'] ?? '');
$cert_num = trim($data['cert_num'] ?? '');
$comments = trim($data['comments'] ?? '');
$is_extension = filter_var($data['is_extension'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
$duration_minutes = intval($data['lateness_minutes'] ?? 0);
$exit_time = !empty($data['exit_time']) ? $data['exit_time'] : null;
$return_time = !empty($data['return_time']) ? $data['return_time'] : null;

// New CNSS Fields
$doctor_name = trim($data['doctor_name'] ?? '');
$doctor_inpe = trim($data['doctor_inpe'] ?? '');
$certificate_date = !empty($data['certificate_date']) ? $data['certificate_date'] : null;
$maternity_expected_date = !empty($data['maternity_expected_date']) ? $data['maternity_expected_date'] : null;
$accident_date = !empty($data['accident_date']) ? $data['accident_date'] : null;
$accident_location = trim($data['accident_location'] ?? '');
$extension_reason = trim($data['extension_reason'] ?? '');

if (!$employee_id || !$type || !$start || !$end) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields (employee, type, start, end).']);
    exit;
}

// Security: HR can only add absences for their factory
if (is_hr()) {
    $stmt_hr = $pdo->prepare("SELECT l.id FROM users u JOIN locations l ON u.location COLLATE utf8mb4_unicode_ci = l.name COLLATE utf8mb4_unicode_ci WHERE u.cin = ?");
    $stmt_hr->execute([$_SESSION['user_cin']]);
    $hr_location_id = $stmt_hr->fetchColumn();

    $emp_loc_stmt = $pdo->prepare("SELECT location_id FROM hr_employees WHERE id = ?");
    $emp_loc_stmt->execute([$employee_id]);
    $emp_loc_id = $emp_loc_stmt->fetchColumn();

    if ($hr_location_id != $emp_loc_id) {
        http_response_code(403);
        echo json_encode(['error' => 'You cannot manage absences for employees outside your factory.']);
        exit;
    }
}

// Handle File Upload
$document_path = null;
if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['document']['tmp_name'];
    $fileName = $_FILES['document']['name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedfileExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
    if (in_array($fileExtension, $allowedfileExtensions)) {
        $uploadFileDir = __DIR__ . '/uploads/absences/';
        if (!is_dir($uploadFileDir)) {
            mkdir($uploadFileDir, 0755, true);
        }

        // New format: emp_{id}_{type}_{start}_{uniqid}.ext
        $newFileName = sprintf(
            "emp_%d_%s_%s_%s.%s",
            $employee_id,
            $type,
            preg_replace('/[^0-9\-]/', '', $start),
            uniqid(),
            $fileExtension
        );
        $dest_path = $uploadFileDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            $document_path = 'uploads/absences/' . $newFileName;
        } else {
            error_log("Failed to move uploaded document.");
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Upload failed. Allowed file types: ' . implode(',', $allowedfileExtensions)]);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    if ($type === 'R') {
        // Lateness (Retard) Logic
        if ($duration_minutes <= 0) {
            throw new Exception("Lateness duration must be > 0.");
        }
        $stmt_r = $pdo->prepare("INSERT INTO hr_latenesses (employee_id, lateness_date, duration_minutes, reason, deducted_from_pay, recorded_by) VALUES (?, ?, ?, ?, 1, ?)");
        $stmt_r->execute([$employee_id, $start, $duration_minutes, $comments, $_SESSION['user_cin']]);

        // Also update regular attendance for today to reflect 'R' (Retard)
        $stmt_update_att = $pdo->prepare("INSERT INTO hr_attendance (employee_id, work_date, hours_worked, status, recorded_by) 
                                          VALUES (?, ?, 0, 'R', ?) 
                                          ON DUPLICATE KEY UPDATE status = 'R', recorded_by = ?");
        $stmt_update_att->execute([$employee_id, $start, $_SESSION['user_cin'], $_SESSION['user_cin']]);
    } else {
        // Long-term Absences Logic (Maladie, Maternité, Accident, Mise a pied, Congé Payé)

        // Find parent absence if this is an extension
        $parent_id = null;
        if ($is_extension) {
            $stmt_parent = $pdo->prepare("SELECT id FROM hr_absences WHERE employee_id = ? AND absence_type = ? ORDER BY end_date DESC LIMIT 1");
            $stmt_parent->execute([$employee_id, $type]);
            $parent_id_result = $stmt_parent->fetchColumn();
            if ($parent_id_result) {
                $parent_id = $parent_id_result;
            }
        }

        $stmt_abs = $pdo->prepare("INSERT INTO hr_absences 
            (employee_id, absence_type, start_date, end_date, certificate_number, is_extension, parent_absence_id, comments, recorded_by,
             doctor_name, doctor_inpe, certificate_date, maternity_expected_date, accident_date, accident_location, extension_reason, document_path, exit_time, return_time) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_abs->execute([
            $employee_id,
            $type,
            $start,
            $end,
            $cert_num,
            $is_extension,
            $parent_id,
            $comments,
            $_SESSION['user_cin'],
            $doctor_name,
            $doctor_inpe,
            $certificate_date,
            $maternity_expected_date,
            $accident_date,
            $accident_location,
            $extension_reason,
            $document_path,
            $exit_time,
            $return_time
        ]);

        // RETROSPECTIVE & FUTURE ATTENDANCE UPDATE
        // Overwrite the `hr_attendance` table for every single day in this date range.
        $begin = new DateTime($start);
        $finish = new DateTime($end);
        $finish = $finish->modify('+1 day'); // DatePeriod includes start but excludes end without +1

        $interval = new DateInterval('P1D');
        $daterange = new DatePeriod($begin, $interval, $finish);

        $stmt_sync = $pdo->prepare("INSERT INTO hr_attendance (employee_id, work_date, hours_worked, status, recorded_by) 
                                    VALUES (?, ?, 0, ?, ?) 
                                    ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by = VALUES(recorded_by)");

        foreach ($daterange as $date) {
            $current_date_str = $date->format("Y-m-d");
            // Skip Sundays if we assume 6 day work week? 
            // In Morocco, normally Sunday is a rest day. We'll mark it with the same absence code, or skip.
            // Let's mark it with the absence code so it's continuous on the grid.
            $stmt_sync->execute([$employee_id, $current_date_str, $type, $_SESSION['user_cin']]);
        }
    }

    $pdo->commit();
    audit_log($pdo, 'hr_save_absence', "Saved Absence Type $type for Emp ID $employee_id from $start");

    echo json_encode(['success' => true, 'message' => 'Absence recorded. Attendance grid updated automatically.']);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Error saving absence: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// --- JSON Response Helper ---
function api_response($success, $data = [], $message = '', $code = 200)
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $data, $message ? ['message' => $message] : []));
    exit;
}

// --- AUTH CHECK ---
if (!isset($_SESSION['user_cin'])) {
    api_response(false, [], 'Unauthorized', 403);
}

$user_cin = $_SESSION['user_cin'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    api_response(false, [], 'Invalid request');
}

// --- CSRF VERIFICATION ---
// Allow read-only actions without CSRF, require it for mutations
$readonly_actions = ['get_day_details'];
if (!in_array($input['action'], $readonly_actions)) {
    if (!verify_csrf($input['csrf_token'] ?? '')) {
        api_response(false, [], 'CSRF verification failed. Please refresh the page.', 403);
    }
}

// --- INPUT VALIDATION HELPERS ---
$allowed_kpis = ['S', 'Q', 'D', '5S', 'C'];
$allowed_statuses = ['green', 'orange', 'red', 'blue', 'gray'];
$allowed_cm_statuses = ['Open', 'In Progress', 'Done'];

function validate_date($date)
{
    return preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date);
}

// --- MAIN TRY-CATCH ---
try {
    $action = $input['action'];

    // ===============================
    // ACTION: update_day
    // ===============================
    if ($action === 'update_day') {
        $kpi = $input['kpi'] ?? '';
        $date = $input['date'] ?? '';
        $status = $input['status'] ?? '';

        // Validate inputs
        if (!in_array($kpi, $allowed_kpis)) {
            api_response(false, [], 'Invalid KPI category');
        }
        if (!in_array($status, $allowed_statuses)) {
            api_response(false, [], 'Invalid status value');
        }
        if (!validate_date($date)) {
            api_response(false, [], 'Invalid date format');
        }

        $target_cin = $user_cin;
        if (isset($input['target_cin']) && $_SESSION['role'] === 'admin') {
            $target_cin = $input['target_cin'];
        }

        $sql = "INSERT INTO sqdc_daily (user_cin, day_date, category, status) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE status = VALUES(status)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$target_cin, $date, $kpi, $status]);
        api_response(true);

        // ===============================
        // ACTION: get_day_details (read-only)
        // ===============================
    } elseif ($action === 'get_day_details') {
        $target_cin = $user_cin;
        if (isset($input['target_cin']) && $_SESSION['role'] === 'admin') {
            $target_cin = $input['target_cin'];
        }

        $date = $input['date'] ?? '';
        if (!validate_date($date)) {
            api_response(false, [], 'Invalid date format');
        }

        $stmt = $pdo->prepare("SELECT category, status FROM sqdc_daily WHERE user_cin = ? AND day_date = ?");
        $stmt->execute([$target_cin, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $result = [];
        foreach ($allowed_kpis as $cat) {
            $result[$cat] = $rows[$cat] ?? 'gray';
        }
        api_response(true, ['data' => $result]);

        // ===============================
        // ACTION: save_countermeasures (IMPROVED — individual insert, no bulk delete)
        // ===============================
    } elseif ($action === 'save_countermeasures') {
        if (!isset($input['data']) || !is_array($input['data'])) {
            api_response(false, [], 'No data provided');
        }

        $pdo->beginTransaction();

        $ins = $pdo->prepare("INSERT INTO countermeasures (user_cin, category, issue, action_plan, responsible, due_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($input['data'] as $row) {
            $cat = $row['category'] ?? 'S';
            $issue = trim($row['issue'] ?? '');
            $action_plan = trim($row['action_plan'] ?? '');
            $responsible = trim($row['responsible'] ?? '');
            $due_date = $row['due_date'] ?? '';
            $cm_status = $row['status'] ?? 'Open';

            // Validate each row
            if (!in_array($cat, $allowed_kpis))
                $cat = 'S';
            if (!in_array($cm_status, $allowed_cm_statuses))
                $cm_status = 'Open';
            if (empty($issue) || empty($action_plan))
                continue; // Skip empty rows
            if ($due_date && !validate_date($due_date))
                $due_date = date('Y-m-d');

            $ins->execute([$user_cin, $cat, $issue, $action_plan, $responsible, $due_date, $cm_status]);
        }

        $pdo->commit();
        api_response(true);

        // ===============================
        // ACTION: update_profile
        // ===============================
    } elseif ($action === 'update_profile') {
        $dept = trim($input['department'] ?? '');
        $loc = trim($input['location'] ?? '');
        $bdate = $input['birth_date'] ?? '';

        if (empty($dept) || empty($loc) || empty($bdate)) {
            api_response(false, [], 'All fields are required');
        }
        if (!validate_date($bdate)) {
            api_response(false, [], 'Invalid date format');
        }

        $stmt = $pdo->prepare("UPDATE users SET department = ?, location = ?, birth_date = ? WHERE cin = ?");
        $stmt->execute([$dept, $loc, $bdate, $_SESSION['user_cin']]);
        api_response(true);

        // ===============================
        // ACTION: update_own_profile
        // ===============================
    } elseif ($action === 'update_own_profile') {
        $name = strtoupper(trim($input['name'] ?? ''));
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? '');
        $whatsapp = trim($input['whatsapp'] ?? '');
        $dept = trim($input['department'] ?? '');
        $loc = trim($input['location'] ?? '');
        $bdate = $input['birth_date'] ?? '';

        if (empty($name) || empty($phone) || empty($dept) || empty($loc) || empty($bdate)) {
            api_response(false, [], 'All fields are required');
        }
        if (!validate_date($bdate)) {
            api_response(false, [], 'Invalid date format');
        }

        $stmt = $pdo->prepare("UPDATE users SET name = ?, phone = ?, email = ?, whatsapp = ?, department = ?, location = ?, birth_date = ? WHERE cin = ?");
        $stmt->execute([$name, $phone, $email ?: null, $whatsapp ?: null, $dept, $loc, $bdate, $_SESSION['user_cin']]);
        $_SESSION['user_name'] = $name;
        api_response(true);

    } else {
        api_response(false, [], 'Unknown action');
    }

} catch (PDOException $e) {
    // Rollback if in transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("API Error [{$input['action']}]: " . $e->getMessage());
    api_response(false, [], 'Database error. Please try again.', 500);
} catch (Exception $e) {
    error_log("API General Error: " . $e->getMessage());
    api_response(false, [], 'An error occurred. Please try again.', 500);
}
?>
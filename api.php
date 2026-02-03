<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_cin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_cin = $_SESSION['user_cin'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

if (isset($input['action'])) {

    if ($input['action'] === 'update_day') {
        $target_cin = $user_cin;
        // Allow Admin to override target user
        if (isset($input['target_cin']) && $_SESSION['role'] === 'admin') {
            $target_cin = $input['target_cin'];
        }

        $kpi = $input['kpi'];
        $date = $input['date'];
        $status = $input['status'];

        $sql = "INSERT INTO sqdc_daily (user_cin, day_date, category, status) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE status = VALUES(status)";

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$target_cin, $date, $kpi, $status])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }

    } elseif ($input['action'] === 'get_day_details') {
        $target_cin = $user_cin;
        // Allow Admin to view other users
        if (isset($input['target_cin']) && $_SESSION['role'] === 'admin') {
            $target_cin = $input['target_cin'];
        }

        $date = $input['date'];
        $stmt = $pdo->prepare("SELECT category, status FROM sqdc_daily WHERE user_cin = ? AND day_date = ?");
        $stmt->execute([$target_cin, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Returns ['S'=>'green', 'Q'=>'red', ...]

        // Ensure all categories exist with default 'gray'
        $result = [];
        foreach (['S', 'Q', 'D', '5S', 'C'] as $cat) {
            $result[$cat] = $rows[$cat] ?? 'gray';
        }

        echo json_encode(['success' => true, 'data' => $result]);

    } elseif ($input['action'] === 'save_countermeasures') {
        // Check user role
        $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

        $pdo->beginTransaction();

        // For non-admins: Only delete records created within the last hour
        // For admins: Delete all records (full control)
        if ($isAdmin) {
            $del = $pdo->prepare("DELETE FROM countermeasures WHERE user_cin = ?");
            $del->execute([$user_cin]);
        } else {
            // Delete only records created within the last 1 hour
            $del = $pdo->prepare("DELETE FROM countermeasures WHERE user_cin = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $del->execute([$user_cin]);
        }

        // Insert all new records
        $ins = $pdo->prepare("INSERT INTO countermeasures (user_cin, category, issue, action_plan, responsible, due_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($input['data'] as $row) {
            $ins->execute([
                $user_cin,
                $row['category'] ?? 'S',
                $row['issue'],
                $row['action_plan'],
                $row['responsible'],
                $row['due_date'],
                $row['status']
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);

    } elseif ($input['action'] === 'update_profile') {
        // --- UPDATE PROFILE ---
        $dept = $input['department'];
        $loc = $input['location'];
        $bdate = $input['birth_date'];

        $sql = "UPDATE users SET department = ?, location = ?, birth_date = ? WHERE cin = ?";
        $stmt = $pdo->prepare($sql);

        if ($stmt->execute([$dept, $loc, $bdate, $_SESSION['user_cin']])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'DB Error']);
        }
    }
}
?>
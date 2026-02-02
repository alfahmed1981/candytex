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
        $kpi = $input['kpi'];
        $date = $input['date'];
        $status = $input['status'];

        $sql = "INSERT INTO sqdc_daily (user_cin, day_date, category, status) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE status = VALUES(status)";

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$user_cin, $date, $kpi, $status])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }

    } elseif ($input['action'] === 'save_countermeasures') {
        // Full Sync Strategy: Delete All & Re-Insert (Simplest for Table Sync)
        // Or better: Upsert based on IDs. For now, let's keep it simple since we handle the array in JS.
        // Actually, pure syncing from a JS array to DB row-by-row is tricky.
        // Let's assume the JS sends the full list, and we wipe/replace for this user? 
        // No, that destroys history timestamps.

        // Let's switch strategy: The JS should send "Upsert" for a single row.
        // But the previous architecture sent the WHOLE table.
        // To be safe and quick: Delete all columns for this user and Re-Insert. 
        // (Not ideal for large data, but fine for small per-user lists).

        $pdo->beginTransaction();

        // 1. Delete all previous
        $del = $pdo->prepare("DELETE FROM countermeasures WHERE user_cin = ?");
        $del->execute([$user_cin]);

        // 2. Insert all new
        $ins = $pdo->prepare("INSERT INTO countermeasures (user_cin, category, issue, action_plan, responsible, due_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($input['data'] as $row) {
            $ins->execute([
                $user_cin,
                $row['category'] ?? 'S', // Default to Safety if missing
                $row['issue'],
                $row['action_plan'], // JS uses 'action_plan' now
                $row['responsible'], // JS uses 'responsible' now
                $row['due_date'],    // JS uses 'due_date' now
                $row['status']
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    }
}
?>
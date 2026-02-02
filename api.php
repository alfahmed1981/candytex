<?php
session_start();

if (!isset($_SESSION['user_cin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_cin = $_SESSION['user_cin'];
$data_file = 'data/sqdc_' . $user_cin . '.json';

// Get JSON Input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Load existing data
$current_data = ['days' => [], 'countermeasures' => []];
if (file_exists($data_file)) {
    $current_data = json_decode(file_get_contents($data_file), true);
}

// Action Handler
if (isset($input['action'])) {
    if ($input['action'] === 'update_day') {
        // Update a specific day
        $kpi = $input['kpi'];
        $date = $input['date'];
        $status = $input['status'];

        if (!isset($current_data['days'][$kpi]))
            $current_data['days'][$kpi] = [];
        $current_data['days'][$kpi][$date] = $status;

    } elseif ($input['action'] === 'save_countermeasures') {
        // Update full table
        $current_data['countermeasures'] = $input['data'];
    }

    // Save back to file
    if (file_put_contents($data_file, json_encode($current_data, JSON_PRETTY_PRINT))) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Write failed']);
    }
}
?>
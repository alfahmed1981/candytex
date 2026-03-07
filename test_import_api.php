<?php
// Simulate the backend API call to see the exact error output
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

$payload = json_encode([
    'csrf_token' => 'dummy',
    'records' => [
        ['matricule' => '123', 'date' => '2025-11-26', 'hours' => 8, 'status' => 'P']
    ],
    'payrolls' => []
]);

$_SESSION = [
    'user_cin' => 'admin_cin',
    'role' => 'admin',
    'csrf_token' => 'dummy'
];

ob_start();
include 'api_import_attendance.php';
$output = ob_get_clean();

echo "RAW OUTPUT:\n";
echo $output;

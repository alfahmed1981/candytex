<?php
session_start();
require 'db.php';
require 'includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure only admins or HR can export the full employee list
if (!isset($_SESSION['user_cin']) || (!is_admin() && !is_hr())) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized. Admin or HR access required.']);
    exit;
}

try {
    // Fetch active employees with joined location (Factory) data
    $stmt = $pdo->query("
        SELECT 
            e.matricule AS 'Matricule',
            e.cin AS 'CIN',
            e.first_name AS 'First Name',
            e.last_name AS 'Last Name',
            e.gender AS 'Gender',
            e.date_of_birth AS 'Date of Birth',
            e.phone_number AS 'Phone',
            e.address AS 'Address',
            e.marital_status AS 'Marital Status',
            e.children_count AS 'Children',
            l.name AS 'Factory',
            e.department AS 'Department',
            e.function_title AS 'Function',
            e.cnss_number AS 'CNSS Number',
            e.hire_date AS 'Hire Date',
            e.contract_type AS 'Contract',
            e.payment_type AS 'Payment Type',
            e.hourly_rate AS 'Salary/Rate',
            e.manager_cin AS 'Manager CIN',
            e.current_shift AS 'Shift',
            e.blood_group AS 'Blood Group',
            e.emergency_contact AS 'Emergency Contact',
            e.emergency_phone AS 'Emergency Phone',
            e.status AS 'Status'
        FROM hr_employees e
        LEFT JOIN locations l ON e.location_id = l.id
        ORDER BY l.name, e.department, e.first_name
    ");

    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count' => count($employees),
        'data' => $employees
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

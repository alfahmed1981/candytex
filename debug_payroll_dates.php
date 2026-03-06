<?php
require 'db.php';
try {
    $stmt = $pdo->query("SELECT DISTINCT payroll_month, payroll_year, period_start, period_end, COUNT(*) as record_count FROM hr_payroll GROUP BY payroll_month, payroll_year, period_start, period_end ORDER BY payroll_year DESC, payroll_month DESC LIMIT 5");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

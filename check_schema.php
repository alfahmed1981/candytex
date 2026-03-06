<?php
// Script to check hr_payroll table schema
require 'db.php';
try {
    $stmt = $pdo->query("DESCRIBE hr_payroll");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "TABLE hr_payroll COLUMNS:\n";
    foreach ($columns as $c) {
        echo "- " . $c['Field'] . " (" . $c['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

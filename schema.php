<?php
$pdo = new PDO("mysql:host=localhost;charset=utf8mb4", "root", "");
$stmt = $pdo->query("SHOW DATABASES");
$dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);

$target_db = null;
foreach ($dbs as $db) {
    if ($db === 'information_schema' || $db === 'mysql' || $db === 'performance_schema' || $db === 'phpmyadmin') continue;
    $pdo->exec("USE `$db`");
    $tables = $pdo->query("SHOW TABLES LIKE 'hr_employees'")->fetchAll();
    if (count($tables) > 0) {
        $target_db = $db;
        break;
    }
}

if ($target_db) {
    echo "Found HR tables in DB: $target_db\n";
    $emp = $pdo->query('DESCRIBE hr_employees')->fetchAll(PDO::FETCH_ASSOC);
    echo "hr_employees columns:\n";
    foreach($emp as $row) { echo "  {$row['Field']} ({$row['Type']})\n"; }

    $pay = $pdo->query('DESCRIBE hr_payroll')->fetchAll(PDO::FETCH_ASSOC);
    echo "hr_payroll columns:\n";
    foreach($pay as $row) { echo "  {$row['Field']} ({$row['Type']})\n"; }
} else {
    echo "HR tables not found in any database.\n";
}

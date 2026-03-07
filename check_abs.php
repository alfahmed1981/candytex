<?php
require 'db.php';
try {
    $stmt = $pdo->query("SELECT absence_type, COUNT(*) as c, MIN(start_date) as min_d, MAX(start_date) as max_d FROM hr_absences WHERE start_date >= '2026-01-26' GROUP BY absence_type");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

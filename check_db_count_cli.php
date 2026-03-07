<?php

// Simulate $_SESSION roughly just to get past db require if it looks for auth
$_SESSION = ['role' => 'admin'];
require 'db.php';

$stmt = $pdo->query("SELECT absence_type, COUNT(*) as c FROM hr_absences WHERE start_date >= '2026-02-01' AND start_date <= '2026-02-28' OR end_date >= '2026-02-01' AND end_date <= '2026-02-28' GROUP BY absence_type");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($res);

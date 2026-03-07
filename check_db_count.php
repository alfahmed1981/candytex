<?php
$host = 'localhost';
$dbname = 'candytex_dash'; // Assuming standard local db name
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT absence_type, COUNT(*) as c FROM hr_absences WHERE start_date >= '2026-02-01' AND start_date <= '2026-02-28' OR end_date >= '2026-02-01' AND end_date <= '2026-02-28' GROUP BY absence_type");
    $res = $stmt->fetchAll();
    print_r($res);
} catch (PDOException $e) {
    echo "Fallback to candytex_erp...\n";
    try {
        $dbname = 'candytex_erp';
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $stmt = $pdo->query("SELECT absence_type, COUNT(*) as c FROM hr_absences WHERE start_date >= '2026-02-01' AND start_date <= '2026-02-28' OR end_date >= '2026-02-01' AND end_date <= '2026-02-28' GROUP BY absence_type");
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        print_r($res);
    } catch (Exception $e2) {
        echo "Error: " . $e2->getMessage();
    }
}

<?php
require 'db.php';

try {
    // 1. Locations
    $pdo->exec("CREATE TABLE IF NOT EXISTS locations (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        name VARCHAR(100) UNIQUE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed Locations
    $locs = ['Candy 1', 'Candy 2', 'Flora 1'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO locations (name) VALUES (?)");
    foreach ($locs as $l)
        $stmt->execute([$l]);

    // 2. Departments
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        name VARCHAR(100) UNIQUE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed Departments
    $depts = ['Sewing', 'Cutting', 'Finishing', 'Packing', 'Warehouse', 'Maintenance', 'Quality', 'HR_Admin'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO departments (name) VALUES (?)");
    foreach ($depts as $d)
        $stmt->execute([$d]);

    // 3. Shifts
    $pdo->exec("CREATE TABLE IF NOT EXISTS shifts (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        name VARCHAR(100) NOT NULL,
        code VARCHAR(20) UNIQUE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed Shifts
    $shifts = [
        ['code' => 'A', 'name' => 'Shift A (Matin)'],
        ['code' => 'B', 'name' => 'Shift B (Après-midi)'],
        ['code' => 'C', 'name' => 'Shift C (Nuit)'],
        ['code' => 'Normal', 'name' => 'Normal Day']
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO shifts (code, name) VALUES (:code, :name)");
    foreach ($shifts as $s)
        $stmt->execute($s);

    // 4. Roles (System)
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_roles (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(50) UNIQUE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed Roles
    $roles = [
        ['slug' => 'admin', 'name' => 'Administrator'],
        ['slug' => 'manager', 'name' => 'Manager / Chef d\'équipe']
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO system_roles (slug, name) VALUES (:slug, :name)");
    foreach ($roles as $r)
        $stmt->execute($r);

    echo "✅ Tables created and seeded successfully!<br>";
    echo "<a href='admin_advanced.php'>Go to Advanced Settings</a>";

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

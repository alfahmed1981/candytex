<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Only admins can trigger database migrations
require_admin();

try {
    // The PAIE excel put CNSS prefixes like "D 23" into the department column. Let's nullify them.
    $sql = "UPDATE hr_employees SET department = NULL WHERE TRIM(department) REGEXP '^D ?[0-9]+'";
    $affected = $pdo->exec($sql);

    // Also remove them from the standalone departments table in admin_advanced if any leaked there
    $sql2 = "DELETE FROM departments WHERE TRIM(name) REGEXP '^D ?[0-9]+'";
    $affected2 = $pdo->exec($sql2);

    echo "<h1>Cleanup Successful!</h1>";
    echo "<p>Cleared $affected 'DXX' codes from employee records.</p>";
    echo "<p>Deleted $affected2 'DXX' codes from system departments.</p>";
    echo "<p>You can delete this script now.</p>";
} catch (PDOException $e) {
    echo "<h1>Cleanup Failed:</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
}


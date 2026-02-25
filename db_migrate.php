<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Only admins can trigger database migrations
require_admin();

$sql = file_get_contents('hr_schema_v6.sql');
try {
    $pdo->exec($sql);
    echo "<h1>Migration Successful!</h1><p>The hr_absences and hr_latenesses tables were created successfully. You can delete this file now.</p>";
} catch (PDOException $e) {
    echo "<h1>Migration Failed:</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
}


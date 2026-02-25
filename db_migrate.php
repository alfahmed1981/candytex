<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Only admins can trigger database migrations
require_admin();

$sql = file_get_contents('hr_schema_v7.sql');
try {
    $pdo->exec($sql);
    echo "<h1>Migration Successful!</h1><p>The CNSS and Moroccan Labor Law fields were successfully added to the hr_absences table. You can delete this file now.</p>";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') {
        echo "<h1>Migration Successful (Already Applied):</h1><p>The columns already exist. You're good to go.</p>";
    } else {
        echo "<h1>Migration Failed:</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
    }
}


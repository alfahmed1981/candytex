<?php
require 'db.php';

try {
    echo "<h1>🔄 Normalizing CINs to Uppercase...</h1>";

    // 1. Users
    $stmt = $pdo->query("UPDATE users SET cin = UPPER(cin)");
    echo "✅ Users Table Updated.<br>";

    // 2. Logs
    $stmt = $pdo->query("UPDATE sqdc_daily SET user_cin = UPPER(user_cin)");
    echo "✅ Logs Updated.<br>";

    // 3. Countermeasures
    $stmt = $pdo->query("UPDATE countermeasures SET user_cin = UPPER(user_cin)");
    echo "✅ Countermeasures Updated.<br>";

    echo "<h1>✨ Done!</h1>";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
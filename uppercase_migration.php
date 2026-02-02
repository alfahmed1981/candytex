<?php
require 'db.php';

try {
    echo "<h1>🔄 Normalizing CINs (Uppercase + No Spaces)...</h1>";

    // 1. Disable FKs
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    // 2. Users: UPPER(REPLACE(cin, ' ', ''))
    $stmt = $pdo->query("UPDATE users SET cin = UPPER(REPLACE(cin, ' ', ''))");
    echo "✅ Users Table Updated: " . $stmt->rowCount() . " rows.<br>";

    // 3. Logs
    $stmt = $pdo->query("UPDATE sqdc_daily SET user_cin = UPPER(REPLACE(user_cin, ' ', ''))");
    echo "✅ Logs Updated: " . $stmt->rowCount() . " rows.<br>";

    // 4. Countermeasures
    $stmt = $pdo->query("UPDATE countermeasures SET user_cin = UPPER(REPLACE(user_cin, ' ', ''))");
    echo "✅ Countermeasures Updated: " . $stmt->rowCount() . " rows.<br>";

    // 5. Enable FKs
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    echo "<h1>✨ Done! 'CD 123' is now 'CD123'.</h1>";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
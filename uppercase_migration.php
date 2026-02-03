<?php
require 'db.php';

try {
    echo "<h1>🔄 Normalizing Data (Uppercase CINs & Names + No Spaces in CIN)...</h1>";

    // 1. Disable FKs
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    // 2. Users: Fix CIN and Name
    $stmt = $pdo->query("UPDATE users SET cin = UPPER(REPLACE(cin, ' ', '')), name = UPPER(name)");
    echo "✅ Users Table Updated: " . $stmt->rowCount() . " rows (CIN & Names).<br>";

    // 3. Logs
    $stmt = $pdo->query("UPDATE sqdc_daily SET user_cin = UPPER(REPLACE(user_cin, ' ', ''))");
    echo "✅ Logs Updated: " . $stmt->rowCount() . " rows.<br>";

    // 4. Countermeasures
    $stmt = $pdo->query("UPDATE countermeasures SET user_cin = UPPER(REPLACE(user_cin, ' ', ''))");
    echo "✅ Countermeasures Updated: " . $stmt->rowCount() . " rows.<br>";

    // 5. Enable FKs
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    echo "<h1>✨ Done! Data is normalized.</h1>";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
<?php
require 'db.php';

try {
    echo "<h1>🔄 Smart Migration v2 (Aggressive)...</h1>";
    echo "<p>Fetching ALL users and checking case sensitivity in PHP.</p>";

    // Disable FK checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    // 1. Fetch ALL users
    $stmt = $pdo->query("SELECT cin FROM users");
    $all_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count_fixed = 0;

    foreach ($all_users as $current_cin) {
        $clean_cin = strtoupper(str_replace(' ', '', $current_cin));

        // PHP string comparison is binary/case-sensitive
        if ($current_cin === $clean_cin) {
            continue; // Already clean
        }

        echo "<hr>Fixing: <b>'$current_cin'</b> -> <b>'$clean_cin'</b><br>";

        // Check if target already exists (Collision)
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE cin = ? AND id != (SELECT id FROM users WHERE cin = ?)");
        $stmt_check->execute([$clean_cin, $current_cin]);
        $collision = $stmt_check->fetchColumn();

        if ($collision) {
            echo "⚠️ Collision detected! Merging data...<br>";

            // Update FKs to point to the 'clean' user
            $pdo->prepare("UPDATE IGNORE countermeasures SET user_cin = ? WHERE user_cin = ?")->execute([$clean_cin, $current_cin]);
            $pdo->prepare("UPDATE IGNORE sqdc_daily SET user_cin = ? WHERE user_cin = ?")->execute([$clean_cin, $current_cin]);

            // Delete the 'dirty' user
            $pdo->prepare("DELETE FROM users WHERE cin = ?")->execute([$current_cin]);
            echo " - 🗑️ Merged & Deleted old user.<br>";

        } else {
            echo "✨ Target free. Updating in place...<br>";

            // Should likely work, but if we have a primary key collision on string equality (CI collation) it might fail?
            // Let's try direct update.
            try {
                $pdo->prepare("UPDATE users SET cin = ? WHERE cin = ?")->execute([$clean_cin, $current_cin]);

                // Also update children, just in case they didn't cascading update (though they are loose mostly)
                $pdo->prepare("UPDATE countermeasures SET user_cin = ? WHERE user_cin = ?")->execute([$clean_cin, $current_cin]);
                $pdo->prepare("UPDATE sqdc_daily SET user_cin = ? WHERE user_cin = ?")->execute([$clean_cin, $current_cin]);

                echo " - ✅ Updated.<br>";
            } catch (PDOException $ex) {
                echo " - ❌ Update failed: " . $ex->getMessage() . "<br>";
            }
        }
        $count_fixed++;
    }

    // 2. Fix Names (Bulk)
    $pdo->query("UPDATE users SET name = UPPER(name)");
    echo "<hr>✅ All Names capitalized.<br>";

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    echo "<h1>🚀 Done! Fixed $count_fixed users.</h1>";

} catch (PDOException $e) {
    echo "<h1>❌ Error: " . $e->getMessage() . "</h1>";
}
?>
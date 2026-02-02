<?php
require 'db.php';

try {
    echo "<h1>🔄 Smart Migration (Handling Duplicates)...</h1>";

    // Disable FK checks temporarily
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    // 1. Get all users who have spaces or lowercase
    // We fetch them first to handle them one by one
    $stmt = $pdo->query("SELECT cin FROM users WHERE cin LIKE '% %' OR cin != UPPER(cin)");
    $users_to_fix = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "Found " . count($users_to_fix) . " users to fix.<br>";

    foreach ($users_to_fix as $bad_cin) {
        $clean_cin = strtoupper(str_replace(' ', '', $bad_cin));

        if ($bad_cin === $clean_cin) {
            continue; // Should not happen given the query, but safety first
        }

        echo "<hr>Processing: <b>'$bad_cin'</b> -> <b>'$clean_cin'</b><br>";

        // Check if clean_cin already exists
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE cin = ?");
        $stmt_check->execute([$clean_cin]);
        $exists = $stmt_check->fetchColumn();

        if ($exists) {
            echo "⚠️ Target '$clean_cin' already exists. Merging data...<br>";

            // A. Move Countermeasures (Simple Update)
            $stmt_cm = $pdo->prepare("UPDATE countermeasures SET user_cin = ? WHERE user_cin = ?");
            $stmt_cm->execute([$clean_cin, $bad_cin]);
            echo " - Moved " . $stmt_cm->rowCount() . " countermeasures.<br>";

            // B. Move SQDC Logs (Handle Duplicates)
            // We cannot just UPDATE because (user_cin, date, category) is unique.
            // Approach: Try UPDATE IGNORE equivalent.
            $sql_logs = "UPDATE IGNORE sqdc_daily SET user_cin = ? WHERE user_cin = ?";
            $stmt_logs = $pdo->prepare($sql_logs);
            $stmt_logs->execute([$clean_cin, $bad_cin]);
            echo " - Merged " . $stmt_logs->rowCount() . " logs (conflicts ignored).<br>";

            // C. Delete the Bad User (since we merged their data)
            $stmt_del = $pdo->prepare("DELETE FROM users WHERE cin = ?");
            $stmt_del->execute([$bad_cin]);
            echo " - 🗑️ Deleted old user '$bad_cin'.<br>";

        } else {
            echo "✨ Target is free. Updating directly...<br>";

            // Just update the User record
            $stmt_upd = $pdo->prepare("UPDATE users SET cin = ? WHERE cin = ?");
            $stmt_upd->execute([$clean_cin, $bad_cin]);

            // Update children
            $pdo->prepare("UPDATE countermeasures SET user_cin = ? WHERE user_cin = ?")->execute([$clean_cin, $bad_cin]);
            $pdo->prepare("UPDATE sqdc_daily SET user_cin = ? WHERE user_cin = ?")->execute([$clean_cin, $bad_cin]);

            echo " - ✅ Updated successfully.<br>";
        }
    }

    // 2. Fix Names (Bulk is fine here, names aren't unique inputs)
    $pdo->query("UPDATE users SET name = UPPER(name)");
    echo "<hr>✅ All Names capitalized.<br>";

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    echo "<h1>🚀 Smart Migration Complete!</h1>";

} catch (PDOException $e) {
    echo "<h1>❌ Error: " . $e->getMessage() . "</h1>";
    // echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
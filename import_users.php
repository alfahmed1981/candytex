<?php
require 'db.php';

$csv_file = 'users.csv';

if (($handle = fopen($csv_file, "r")) !== FALSE) {
    $header = fgetcsv($handle, 1000, ",");

    // Auto-detect columns
    $col_cin = -1;
    $col_phone = -1;
    $col_name = -1;

    foreach ($header as $index => $col) {
        $c = strtolower($col);
        if (strpos($c, 'national id') !== false || strpos($c, 'cnie') !== false)
            $col_cin = $index;
        if (strpos($c, 'phone') !== false)
            $col_phone = $index;
        if (strpos($c, 'name') !== false && strpos($c, 'id') === false)
            $col_name = $index;
    }

    echo "Found Columns: CIN [$col_cin], Phone [$col_phone], Name [$col_name]<br>";

    $stmt = $pdo->prepare("INSERT INTO users (cin, name, phone, role) VALUES (?, ?, ?, 'manager') ON DUPLICATE KEY UPDATE name=VALUES(name)");

    $count = 0;
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if ($col_cin > -1) {
            $cin = trim($data[$col_cin]);
            $phone = ($col_phone > -1) ? trim($data[$col_phone]) : '';
            $name = ($col_name > -1) ? trim($data[$col_name]) : 'User';

            if ($cin) {
                try {
                    $stmt->execute([$cin, $name, $phone]);
                    $count++;
                } catch (Exception $e) {
                    echo "Error importing $cin: " . $e->getMessage() . "<br>";
                }
            }
        }
    }
    fclose($handle);
    echo "<h2>Success! Imported $count users.</h2>";
} else {
    echo "CSV File not found.";
}
?>
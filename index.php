<?php
session_start();

// Configuration
$csv_file = 'users.csv';
$data_dir = 'data/';
if (!is_dir($data_dir)) mkdir($data_dir, 0755, true);

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Handle Login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $cin_input = trim(strtolower($_POST['cin']));
    $phone_input = trim($_POST['phone']);

    if (($handle = fopen($csv_file, "r")) !== FALSE) {
        $header = fgetcsv($handle, 1000, ",");
        // Find columns dynamically (simplistic approach based on known headers)
        $col_cin = -1; $col_phone = -1; $col_name = -1;
        
        foreach ($header as $index => $col) {
            $c = strtolower($col);
            if (strpos($c, 'national id') !== false || strpos($c, 'cnie') !== false) $col_cin = $index;
            if (strpos($c, 'phone') !== false) $col_phone = $index;
            if (strpos($c, 'name') !== false && strpos($c, 'id') === false) $col_name = $index;
        }

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($col_cin > -1 && $col_phone > -1) {
                $file_cin = trim(strtolower($data[$col_cin]));
                $file_phone = trim($data[$col_phone]);
                
                if ($file_cin === $cin_input && $file_phone === $phone_input) {
                    $_SESSION['user_cin'] = $file_cin;
                    $_SESSION['user_name'] = ($col_name > -1) ? $data[$col_name] : 'User';
                    header("Location: index.php");
                    exit;
                }
            }
        }
        fclose($handle);
        $error = "Invalid Credentials / بيانات خاطئة";
    } else {
        $error = "User verification file missing.";
    }
}

// Ensure Login
if (!isset($_SESSION['user_cin'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en" dir="ltr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SQD+C Login</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body class="login-body">
        <div class="login-container">
            <h1>🔐 SQD+C Board</h1>
            <?php if($error) echo "<p class='error'>$error</p>"; ?>
            <form method="POST">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>National ID (CNIE) / رقم البطاقة</label>
                    <input type="text" name="cin" required placeholder="AB123456">
                </div>
                <div class="form-group">
                    <label>Phone Number / رقم الهاتف</label>
                    <input type="text" name="phone" required placeholder="06...">
                </div>
                <button type="submit">Access Board / دخول</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- DASHBOARD LOGIC ---
$user_cin = $_SESSION['user_cin'];
$user_name = $_SESSION['user_name'];
$data_file = $data_dir . 'sqdc_' . $user_cin . '.json';

// Load Data
$sqdc_data = ['days' => [], 'countermeasures' => []];
if (file_exists($data_file)) {
    $sqdc_data = json_decode(file_get_contents($data_file), true);
}

// Helpers
$year = date('Y');
$month = date('m');
if (isset($_GET['year'])) $year = intval($_GET['year']);
if (isset($_GET['month'])) $month = intval($_GET['month']);

$month_name = date("F", mktime(0, 0, 0, $month, 10));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQD+C Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <!-- SweetAlert2 for nice popups -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="sidebar">
        <div class="profile">
            <h3>👤 <?php echo htmlspecialchars($user_name); ?></h3>
            <p><?php echo htmlspecialchars($user_cin); ?></p>
        </div>
        <hr>
        <div class="filters">
            <form method="GET">
                <label>Year</label>
                <input type="number" name="year" value="<?php echo $year; ?>">
                <label>Month</label>
                <input type="number" name="month" value="<?php echo $month; ?>">
                <button type="submit">Filter</button>
            </form>
        </div>
        <a href="?logout=1" class="logout-btn">Logout / خروج</a>
    </div>

    <div class="main-content">
        <div class="header">
            <h2>📊 SQD+C Digital Board - <?php echo "$month_name $year"; ?></h2>
        </div>

        <div class="sqdc-grid">
            <?php
            $columns = [
                'S' => 'SAFETY',
                'Q' => 'QUALITY',
                'D' => 'DELIVERY',
                '5S' => '5S / +',
                'C' => 'COST'
            ];

            foreach ($columns as $key => $title) {
                echo "<div class='kpi-column'>";
                echo "<h3>$title</h3>";
                echo "<div class='days-container'>";
                
                // Always 31 days for layout consistency
                for ($d = 1; $d <= 31; $d++) {
                    $date_key = "$year-$month-$d";
                    $status = $sqdc_data['days'][$key][$date_key] ?? 'gray';
                    
                    // Ghost out non-existent days (e.g., Feb 30)
                    $real_date = checkdate($month, $d, $year);
                    $opacity = $real_date ? '1' : '0.3';
                    $click_attr = $real_date ? "onclick=\"openDate('$key', '$date_key', '$status')\"" : "";

                    echo "<div class='day-box status-$status' style='opacity:$opacity' $click_attr>$d</div>";
                }
                echo "</div></div>";
            }
            ?>
        </div>

        <hr>
        <div class="countermeasures-section">
            <h3>🛠️ Counter Measures / الإجراءات المضادة</h3>
            <button onclick="addCounterMeasure()" class="add-btn">+ Add Issue</button>
            <table id="cm-table">
                <thead>
                    <tr>
                        <th>Issue / المشكلة</th>
                        <th>Action / الإجراء</th>
                        <th>Who / المسؤول</th>
                        <th>Due Date / التاريخ</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated by JS -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pass PHP data to JS -->
    <script>
        const initialCM = <?php echo json_encode($sqdc_data['countermeasures'] ?? []); ?>;
    </script>
    <script src="script.js"></script>
</body>
</html>

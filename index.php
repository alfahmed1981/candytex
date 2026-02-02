<?php
session_start();
require 'db.php'; // DB Connection

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Handle Login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $cin_input = trim($_POST['cin']);
    $phone_input = trim($_POST['phone']);

    // Database Auth
    $stmt = $pdo->prepare("SELECT * FROM users WHERE cin = ?");
    $stmt->execute([$cin_input]);
    $user = $stmt->fetch();

    if ($user) {
        // Verify Phone (Simple mismatch check since we authenticated by CIN)
        // In a stricter system, we would check phone exactly.
        if ($user['phone'] === $phone_input) {
            $_SESSION['user_cin'] = $user['cin'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            header("Location: index.php");
            exit;
        } else {
            $error = "Phone number does not match CIN.";
        }
    } else {
        $error = "User not found. Please ask Admin to import you.";
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
            <?php if ($error)
                echo "<p class='error'>$error</p>"; ?>
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

// Helpers
$year = date('Y');
$month = date('m');
if (isset($_GET['year']))
    $year = intval($_GET['year']);
if (isset($_GET['month']))
    $month = intval($_GET['month']);
$month_name = date("F", mktime(0, 0, 0, $month, 10));

// Load SQDC Data from DB
$sql = "SELECT category, day_date, status FROM sqdc_daily 
        WHERE user_cin = ? AND MONTH(day_date) = ? AND YEAR(day_date) = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_cin, $month, $year]);
$rows = $stmt->fetchAll();

// Reformat for View
$sqdc_data = ['days' => []];
foreach ($rows as $r) {
    if (!isset($sqdc_data['days'][$r['category']]))
        $sqdc_data['days'][$r['category']] = [];
    $sqdc_data['days'][$r['category']][$r['day_date']] = $r['status'];
}

// Load Countermeasures from DB
$cm_sql = "SELECT * FROM countermeasures WHERE user_cin = ? ORDER BY created_at DESC";
$cm_stmt = $pdo->prepare($cm_sql);
$cm_stmt->execute([$user_cin]);
$sqdc_data['countermeasures'] = $cm_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQD+C Dashboard</title>
    <link rel="stylesheet" href="style.css">
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
                <label>Year<br><small>سنة / Année</small></label>
                <input type="number" name="year" value="<?php echo $year; ?>">

                <label>Month<br><small>شهر / Mois</small></label>
                <input type="number" name="month" value="<?php echo $month; ?>">

                <button type="submit">Filter<br><small>تصفية / Filtrer</small></button>
            </form>
        </div>
        <a href="?logout=1" class="logout-btn">Logout<br><small>خروج / Déconnexion</small></a>
    </div>


    <div class="main-content">
        <div class="header">
            <h2>📊 SQD+C Digital Board<br><span style="font-size:0.6em; color:#666;">لوحة القيادة الرقمية / Tableau de
                    Bord Numérique</span> - <?php echo "$month_name $year"; ?></h2>
        </div>

        <div class="sqdc-grid">
            <?php
            $columns = [
                'S' => ['title' => 'SAFETY', 'sub' => 'السلامة / SÉCURITÉ'],
                'Q' => ['title' => 'QUALITY', 'sub' => 'الجودة / QUALITÉ'],
                'D' => ['title' => 'DELIVERY', 'sub' => 'التسليم / LIVRAISON'],
                '5S' => ['title' => '5S / +', 'sub' => 'التحسين / Amélioration'],
                'C' => ['title' => 'COST', 'sub' => 'التكلفة / COÛT']
            ];

            foreach ($columns as $key => $info) {
                echo "<div class='kpi-column'>";
                echo "<h3>{$info['title']}<br><small style='font-size:0.6em'>{$info['sub']}</small></h3>";
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
            <h3>🛠️ Counter Measures<br><span style="font-size:0.6em">الإجراءات المضادة / Contre-mesures</span></h3>
            <button onclick="addCounterMeasure()" class="add-btn">+ Add Issue<br><small>إضافة مشكلة /
                    Ajouter</small></button>
            <table id="cm-table">
                <thead>
                    <tr>
                        <th>Issue<br><small>المشكلة / Problème</small></th>
                        <th>Action<br><small>الإجراء / Action</small></th>
                        <th>Who<br><small>المسؤول / Qui</small></th>
                        <th>Due Date<br><small>الموعد / Échéance</small></th>
                        <th>Status<br><small>الحالة / Statut</small></th>
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
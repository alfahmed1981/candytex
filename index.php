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
        $login_ok = false;

        // 1. If Admin, Check Password
        if ($user['role'] === 'admin') {
            // If password set, verify it
            if (!empty($user['password']) && password_verify($phone_input, $user['password'])) {
                // User entered Password in the Phone field (since we reused the UI)
                // OR we can add a dedicated password field.
                // Let's keep it simple: "Phone field" acts as "Password" for admins.
                $login_ok = true;
            } else {
                $error = "Incorrect Password for Admin.";
            }
        }
        // 2. If Manager/Worker, Check Phone
        else {
            if ($user['phone'] === $phone_input) {
                $login_ok = true;
            } else {
                $error = "Phone number does not match CIN.";
            }
        }

        if ($login_ok) {
            $_SESSION['user_cin'] = $user['cin'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            // Redirect based on Role
            if ($user['role'] === 'admin') {
                header("Location: admin.php"); // Admins go to Dashboard directly
            } else {
                header("Location: index.php");
            }
            exit;
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
            <div style="text-align:center; margin-top:20px; padding-top:15px; border-top:1px solid rgba(255,255,255,0.2);">
                <a href="guide.php"
                    style="color:#28a745; text-decoration:none; font-size:14px; display:inline-flex; align-items:center; gap:8px;">
                    📖 <span>دليل الاستخدام للمبتدئين</span> | <span style="font-size:12px;">Guide d'utilisation</span>
                </a>
            </div>
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
    <!-- Mobile Top Navigation -->
    <div class="top-nav">
        <div class="top-nav-header">
            <h3>📊 SQD+C Board</h3>
            <span class="user-info">👤 <?php echo htmlspecialchars($user_name); ?></span>
        </div>
        <div class="nav-links">
            <a href="index.php" class="active">📊 لوحة</a>
            <a href="guide.php">📖 دليل</a>
            <a href="my_team.php">👥 فريق</a>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="admin.php">⚙️ إدارة</a>
            <?php endif; ?>
            <a href="?logout=1" class="logout">خروج</a>
        </div>
        <form method="GET" class="date-filter">
            <input type="number" name="year" value="<?php echo $year; ?>" placeholder="سنة">
            <input type="number" name="month" value="<?php echo $month; ?>" placeholder="شهر">
            <button type="submit">🔍 عرض</button>
        </form>
    </div>

    <!-- Desktop Sidebar (hidden on mobile) -->
    <div class="sidebar">
        <div class="profile">
            <h3>👤 <?php echo htmlspecialchars($user_name); ?></h3>
            <p><?php echo htmlspecialchars($user_cin); ?></p>
        </div>
        <hr>
        <div class="filters">
            <form method="GET">
                <label>Year / سنة</label>
                <input type="number" name="year" value="<?php echo $year; ?>">
                <label>Month / شهر</label>
                <input type="number" name="month" value="<?php echo $month; ?>">
                <button type="submit">Filter / تصفية</button>
            </form>
        </div>
        <a href="guide.php" class="logout-btn" style="background:#28a745;">📖 دليل الاستخدام</a>
        <a href="my_team.php" class="logout-btn" style="background:#17a2b8;">👥 My Team</a>
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="admin.php" class="logout-btn" style="background:#6f42c1;">⚙️ Admin</a>
            <a href="global.php" class="logout-btn" style="background:#fd7e14;">🏭 Global</a>
        <?php endif; ?>
        <a href="?logout=1" class="logout-btn" style="background:#dc3545;">Logout</a>
    </div>

    <div class="main-content">
        <div class="header">
            <h2>📊 SQD+C - <?php echo "$month_name $year"; ?></h2>
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
                    // Format date with leading zeros to match DB format (YYYY-MM-DD)
                    $date_key = sprintf("%04d-%02d-%02d", $year, $month, $d);
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
                        <th>Cat<br><small>فئة</small></th>
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
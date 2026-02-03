<?php
session_start();
require 'db.php'; // DB Connection

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Handle Login and Registration
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // --- ANTI-BOT CHECKS ---
    if (!empty($_POST['website_hp']))
        die("Bot detected.");
    if (isset($_POST['login_ts']) && (time() - intval($_POST['login_ts'])) < 1)
        die("Too fast.");

    // --- LOGIN LOGIC ---
    if ($_POST['action'] === 'login') {
        $cin = strtoupper(str_replace(' ', '', trim($_POST['cin'])));
        $cred_input = trim($_POST['password']); // Unified field

        $stmt = $pdo->prepare("SELECT * FROM users WHERE cin = ?");
        $stmt->execute([$cin]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['status'] === 'pending') {
                $error = "⏳ Account pending approval. Please wait.";
            } else {
                $login_ok = false;

                // 1. Password Check
                if (!empty($user['password']) && password_verify($cred_input, $user['password'])) {
                    $login_ok = true;
                }
                // 2. Legacy Phone Check (Treat input as phone)
                elseif (empty($user['password']) && $user['phone'] === $cred_input) {
                    $login_ok = true;
                }

                if ($login_ok) {
                    $_SESSION['user_cin'] = $user['cin'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];

                    if ($user['role'] === 'admin') {
                        header("Location: admin.php");
                    } else {
                        header("Location: index.php");
                    }
                    exit;
                } else {
                    $error = "❌ Incorrect Credential (Password or Phone).";
                }
            }
        } else {
            $error = "❌ User not found.";
        }
    }

    // --- REGISTRATION LOGIC ---
    if ($_POST['action'] === 'register') {
        $reg_cin = strtoupper(str_replace(' ', '', trim($_POST['cin'])));
        $reg_name = strtoupper(trim($_POST['name']));
        $reg_phone = trim($_POST['phone']);
        $reg_pass = $_POST['password'];
        $reg_role = $_POST['role'];

        // Validate
        if (empty($reg_cin) || empty($reg_name) || empty($reg_pass) || empty($reg_phone)) {
            $error = "All fields required.";
        } else {
            // Duplicate Check
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE cin = ?");
            $stmt->execute([$reg_cin]);
            if ($stmt->fetchColumn() > 0) {
                $error = "⚠️ CIN already registered.";
            } else {
                try {
                    $hash = password_hash($reg_pass, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (cin, name, phone, password, role, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                    $stmt->execute([$reg_cin, $reg_name, $reg_phone, $hash, $reg_role]);
                    $success = "✅ Registered! Please wait for Admin approval.";
                } catch (PDOException $e) {
                    // Friendly Error instead of 500
                    $error = "System Error: " . $e->getMessage();
                }
            }
        }
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
        <!-- Force CSS Reload -->
        <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    </head>

    <script>
        function toggleForm() {
            var loginForm = document.getElementById('login-form');
            var regForm = document.getElementById('reg-form');
            var title = document.getElementById('page-title');

            if (loginForm.style.display === 'none') {
                loginForm.style.display = 'block';
                regForm.style.display = 'none';
                title.innerText = '🔐 Login / دخول';
            } else {
                loginForm.style.display = 'none';
                regForm.style.display = 'block';
                title.innerText = '📝 Register / تسجيل جديد';
            }
        }
    </script>
    <style>
        .visually-hidden {
            position: absolute;
            left: -9999px;
        }
    </style>

    <body class="login-body">
        <div class="login-container">
            <h2 id="page-title">🔐 Login / دخول</h2>

            <?php if ($error): ?>
                <div class="error" style="background:#ffd2d2; color:#a00; padding:10px; border-radius:5px; margin-bottom:10px;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div style="background:#d4edda; color:#155724; padding:10px; border-radius:5px; margin-bottom:10px;">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- LOGIN FORM -->
            <div id="login-form">
                <form method="POST">
                    <input type="hidden" name="action" value="login">

                    <!-- ANTI-BOT TRAPS -->
                    <input type="text" name="website_hp" class="visually-hidden" tabindex="-1" autocomplete="off">
                    <input type="hidden" name="login_ts" value="<?= time() ?>">

                    <div class="form-group">
                        <label>CIN (رقم البطاقة)</label>
                        <input type="text" name="cin" placeholder="AB12345" required style="text-transform:uppercase;">
                    </div>

                    <!-- Note: For legacy support we keep 'phone' input? Or use 'password' input? -->
                    <!-- Let's use two fields: Phone (Legacy) OR Password (New) -->
                    <!-- To keep UI simple, let's just use "Password" field, but label it "Password or Phone" -->

                    <div class="form-group">
                        <label>Password (or Phone) / كلمة السر</label>
                        <input type="password" name="password" placeholder="******">
                    </div>

                    <!-- Hidden Phone field for strict legacy compatibility if needed? -->
                    <!-- My Logic above checks: if password matches OR if phone matches input -->
                    <!-- So we can reuse the "password" input name for both? -->
                    <!-- Wait, line 23 above: $phone_input = trim($_POST['phone']); -->
                    <!-- So i need a phone input or change logic above. -->
                    <!-- Let's add a hidden phone input mirroring password? No that's hacky. -->
                    <!-- Better: Add a phone input for legacy users visible? Or just change logic above to use $_POST['password'] as phone? -->
                    <!-- Let's add a separate Phone input for Legacy Users to avoid confusion? -->
                    <!-- User request: "Registration". New users use password. Old users use phone. -->
                    <!-- Simplest UX: User Enters CIN. -->
                    <!-- User Enters "Credential". -->
                    <!-- Backend checks if Credential matches Password OR Credential matches Phone. -->
                    <!-- I will update Logic above to use $_POST['password'] as the universal credential input. -->

                    <button type="submit">Login 🚀</button>
                </form>
                <p style="margin-top:15px; font-size:14px;">
                    New? <a href="#" onclick="toggleForm()">Create Account / تسجيل</a>
                </p>
            </div>

            <!-- REGISTRATION FORM -->
            <div id="reg-form" style="display:none;">
                <form method="POST">
                    <input type="hidden" name="action" value="register">

                    <div class="form-group">
                        <label>CIN (Unique ID)</label>
                        <input type="text" name="cin" placeholder="AB12345" required style="text-transform:uppercase;">
                    </div>

                    <div class="form-group">
                        <label>Full Name / الاسم الكامل</label>
                        <input type="text" name="name" placeholder="Name..." required>
                    </div>

                    <div class="form-group">
                        <label>Phone / الهاتف</label>
                        <input type="text" name="phone" placeholder="06..." required>
                    </div>

                    <div class="form-group">
                        <label>Password / كلمة السر</label>
                        <input type="password" name="password" required>
                    </div>

                    <div class="form-group">
                        <label>Role / الدور</label>
                        <select name="role" style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;">
                            <option value="manager">Manager / Chef d'équipe</option>
                            <!-- Admins: usually manual -->
                        </select>
                    </div>

                    <button type="submit" style="background:#28a745;">Register ✅</button>
                </form>
                <p style="margin-top:15px; font-size:14px;">
                    Have account? <a href="#" onclick="toggleForm()">Login / دخول</a>
                </p>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// --- DASHBOARD LOGIC ---
$user_cin = $_SESSION['user_cin'];
$user_cin = $_SESSION['user_cin'];
$user_name = $_SESSION['user_name'];

// --- FORCE PROFILE COMPLETION ---
// Check if Department, Location, or Birth Date is missing
$stmt_check = $pdo->prepare("SELECT department, location, birth_date FROM users WHERE cin = ?");
$stmt_check->execute([$user_cin]);
$u_check = $stmt_check->fetch();

if (empty($u_check['department']) || empty($u_check['location']) || empty($u_check['birth_date'])) {
    header("Location: complete_profile.php");
    exit;
}
// --------------------------------

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
    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>

</html>
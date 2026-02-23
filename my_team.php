<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Auth Check
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// --- SELF-HEALING DATABASE MIGRATION ---
try {
    $pdo->exec(file_get_contents('hr_schema_v2.sql'));
    $pdo->exec(file_get_contents('hr_schema_v3.sql'));
    $pdo->exec(file_get_contents('hr_schema_v4.sql'));
} catch (Exception $e) {
}

// Handle Form Submission (Daily Pointage)
$msg = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_pointage'])) {
        $p_date = $_POST['attendance_date'];

        try {
            $pdo->beginTransaction();
            foreach ($_POST['status'] as $emp_id => $emp_status) {
                // Determine the employee's shift dynamically or fallback
                $stmt_shift = $pdo->prepare("SELECT current_shift, manager_cin FROM hr_employees WHERE id = ?");
                $stmt_shift->execute([$emp_id]);
                $emp_data = $stmt_shift->fetch();
                $shift_code = $emp_data['current_shift'] ?: 'Normal';

                // Insert or Update Pointage for this day
                $stmt = $pdo->prepare("
                    INSERT INTO hr_team_attendance (employee_id, manager_cin, attendance_date, shift_code, status) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), manager_cin = VALUES(manager_cin)
                ");
                $stmt->execute([$emp_id, $emp_data['manager_cin'], $p_date, $shift_code, $emp_status]);

                // Auto-Removal Logic if Transferred or Left
                if ($emp_status === 'Transferred' || $emp_status === 'Left') {
                    // Remove from team
                    $pdo->prepare("UPDATE hr_employees SET manager_cin = NULL WHERE id = ?")->execute([$emp_id]);

                    // Log to history
                    $pdo->prepare("INSERT INTO hr_employee_history (employee_id, change_type, old_value, new_value, changed_by_cin) VALUES (?, 'TEAM_TRANSFER', ?, 'REMOVED_VIA_POINTAGE', ?)")
                        ->execute([$emp_id, $emp_data['manager_cin'], $user_cin]);
                }
            }
            $pdo->commit();
            $msg = "✅ Daily Pointage saved successfully for " . htmlspecialchars($p_date);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// ADMIN: Select Manager View
$view_cin = $user_cin;
$all_managers = [];
if ($is_admin) {
    // Fetch all managers/admins for dropdown
    $stmt_m = $pdo->query("SELECT cin, name FROM users WHERE role IN ('manager', 'admin') ORDER BY name");
    $all_managers = $stmt_m->fetchAll();

    if (isset($_GET['manager_cin']) && !empty($_GET['manager_cin'])) {
        $view_cin = $_GET['manager_cin'];
    }
}

// Date Filter
$filter_date = $_GET['date'] ?? date('Y-m-d');

// Fetch Team from HR Employees (Based on View CIN)
$my_team = [];
try {
    $stmt = $pdo->prepare("SELECT e.*, 
            (SELECT status FROM hr_team_attendance a WHERE a.employee_id = e.id AND a.attendance_date = ?) as today_status
            FROM hr_employees e 
            WHERE e.manager_cin = ? 
            ORDER BY e.first_name ASC");
    $stmt->execute([$filter_date, $view_cin]);
    $my_team = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage() . " Please ensure HR Schema V4 is loaded.";
}

// Get Name of current view
$view_name = "My Team";
if ($view_cin !== $user_cin && $is_admin) {
    foreach ($all_managers as $m) {
        if ($m['cin'] === $view_cin) {
            $view_name = "Team: " . $m['name'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Team Management</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 1000px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .form-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 14px;
        }

        th,
        td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #f1f1f1;
            color: #333;
        }

        .shift-badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            color: white;
        }

        .shift-A {
            background: #ffc107;
            color: #000;
        }

        /* Morning */
        .shift-B {
            background: #fd7e14;
        }

        /* Afternoon */
        .shift-C {
            background: #343a40;
        }

        /* Night */
        .shift-N {
            background: #28a745;
        }

        /* Admin/Normal */

        .alert {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* Mobile Responsive */
        @media screen and (max-width: 768px) {
            .container {
                margin: 10px;
                padding: 15px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .form-box {
                padding: 15px;
            }

            table {
                font-size: 13px;
            }

            th,
            td {
                padding: 10px 6px;
            }

            h2 {
                font-size: 1.3em;
            }
        }

        @media screen and (max-width: 480px) {
            .container {
                margin: 8px;
                padding: 12px;
            }

            .form-group input,
            .form-group select {
                padding: 12px 10px;
                font-size: 16px;
            }

            button[name="add_worker"] {
                padding: 14px;
                font-size: 16px;
            }
        }
    </style>
    <script>
        function validateCIN(input) {
            // Instant validation feedback
            const regex = /^[a-zA-Z0-9]+$/;
            if (!regex.test(input.value)) {
                input.style.borderColor = "red";
                input.title = "Only letters and numbers allowed. No spaces.";
            } else {
                input.style.borderColor = "green";
            }
        }
    </script>
</head>

<body>
    <!-- Mobile Top Navigation -->
    <div class="top-nav">
        <div class="top-nav-header">
            <h3>👥 My Team</h3>
        </div>
        <div class="nav-links">
            <a href="index.php">📊 لوحة</a>
            <a href="my_team.php" class="active">👥 فريق</a>
            <a href="guide.php">📖 دليل</a>
            <a href="index.php?logout=1" class="logout">خروج</a>
        </div>
    </div>

    <!-- Desktop Sidebar -->
    <div class="sidebar">
        <div class="profile">
            <h3>👥 HR Manager</h3>
            <p><?php echo $_SESSION['user_name']; ?></p>
        </div>
        <hr>
        <a href="index.php" class="logout-btn" style="background:#007bff;">📊 Board</a>
        <a href="index.php?logout=1" class="logout-btn" style="background:#dc3545;">Logout</a>
    </div>

    <div class="main-content">
        <div class="container">
            <h2>👷 <?= htmlspecialchars($view_name) ?> & Shift Management</h2>

            <?php if ($is_admin): ?>
                <div
                    style="background:#e9ecef; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #ccc;">
                    <form method="GET" style="display:flex; gap:10px; align-items:center;">
                        <label style="font-weight:bold;">👮 Admin View / اختر الفريق:</label>
                        <select name="manager_cin" onchange="this.form.submit()"
                            style="padding:8px; border-radius:4px; border:1px solid #aaa;">
                            <option value="<?= $_SESSION['user_cin'] ?>">My Team (Admin)</option>
                            <?php foreach ($all_managers as $mgr): ?>
                                <option value="<?= $mgr['cin'] ?>" <?= $view_cin === $mgr['cin'] ? 'selected' : '' ?>>
                                    Team: <?= htmlspecialchars($mgr['name']) ?> (<?= $mgr['cin'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date) ?>">
                    </form>
                </div>
            <?php endif; ?>

            <div class="form-box" style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h4 style="margin:0;">📅 Daily Pointage / الحضور اليومي</h4>
                    <p style="margin:5px 0 0 0; font-size:13px; color:#666;">
                        Note: Selecting "Transferred" or "Left" will permanently remove the worker from your team.
                    </p>
                </div>
                <form method="GET" style="display:flex; gap:10px;">
                    <?php if ($is_admin): ?>
                        <input type="hidden" name="manager_cin" value="<?= htmlspecialchars($view_cin) ?>">
                    <?php endif; ?>
                    <label style="align-self:center; font-weight:bold;">Date:</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>"
                        onchange="this.form.submit()" style="padding:8px; border:1px solid #ccc; border-radius:4px;">
                </form>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($msg): ?>
                <div class="alert alert-success"><?php echo $msg; ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($filter_date) ?>">
                <table>
                    <thead>
                        <tr>
                            <th>Matricule / Name</th>
                            <th>Function / Shift</th>
                            <th>Pointage Status (حالة الحضور)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($my_team) == 0): ?>
                            <tr>
                                <td colspan="3" style="text-align:center; padding:30px; color:#666;">
                                    No employees assigned to this team currently.<br><br>
                                    <small>أمين الموارد البشرية (HR Admin) هو المسؤول عن إضافة وتعيين العمال لفرقهم.</small>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($my_team as $w):
                            $status = $w['today_status'] ?: 'Present';
                            ?>
                                <tr>
                                <td>
                                    <strong><?= htmlspecialchars($w['matricule']) ?> -
                                        <?= htmlspecialchars($w['full_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($w['cin']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($w['function_title']) ?><br>
                                    <span
                                        class="shift-badge shift-<?= htmlspecialchars($w['current_shift']) ?>"><?= htmlspecialchars($w['current_shift'] ?: 'Normal') ?></span>
                                </td>
                                <td>
                                    <select name="status[<?= $w['id'] ?>]"
                                        style="padding:8px; width:100%; border-radius:4px; border:1px solid #ccc;">
                                        <option value="Present" <?= $status == 'Present' ? 'selected' : '' ?>>🟢 Present / حاضر
                                        </option>
                                        <option value="Absent" <?= $status == 'Absent' ? 'selected' : '' ?>>🔴 Absent / غائب
                                        </option>
                                        <option value="Sick" <?= $status == 'Sick' ? 'selected' : '' ?>>🏥 Sick / مريض</option>
                                        <option value="Transferred" <?= $status == 'Transferred' ? 'selected' : '' ?>>↪️
                                            Transferred / تحول لقسم آخر</option>
                                        <option value="Left" <?= $status == 'Left' ? 'selected' : '' ?>>🚪 Left / خرج</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (count($my_team) > 0): ?>
                    <div style="margin-top:20px; text-align:right;">
                        <button type="submit" name="save_pointage"
                            style="background:#0984e3; color:white; padding:12px 25px; border:none; border-radius:4px; font-weight:bold; cursor:pointer; font-size:16px;">
                            💾 Save Pointage / حفظ الحضور
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) closeSidebar();
        });
    </script>
</body>

</html>
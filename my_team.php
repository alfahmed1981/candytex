<?php
session_start();
require 'db.php';

// Auth Check
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// Handle Form Submission (Add Worker)
$msg = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_worker'])) {
        $w_cin = strtoupper(trim($_POST['cin'])); // Force Upper
        $w_name = strtoupper(trim($_POST['name']));
        $w_phone = trim($_POST['phone']);
        $w_location = $_POST['location'];
        $w_dept = $_POST['department'];
        $w_job = trim($_POST['job_title']);
        $w_shift = $_POST['shift'];

        // Target Manager (Who are we adding this worker for?)
        // If Admin is viewing a specific manager, add to THAT manager? 
        // Or always add to themselves? Usually "My Team" adds to ME.
        // Let's assume Admin adds to THEMSELVES unless we want advanced "Add for others". 
        // For simplicity and safety, keeps adding to Session User OR currently viewed manager?
        // Prompt says "each team leader sees only his team". Admin sees all.
        // Let's default to adding to the CURRENTLY VIEWED manager if Admin, or Session User.

        $target_manager = $user_cin;
        if ($is_admin && isset($_POST['target_manager']) && !empty($_POST['target_manager'])) {
            $target_manager = $_POST['target_manager'];
        }

        // Strict Validation
        if (!preg_match('/^[a-zA-Z0-9]+$/', $w_cin)) {
            $error = "❌ Security Alert: CIN must contain ONLY Latin letters and numbers.";
        } else {
            // GLOBAL UNIQUENESS CHECK
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM workers WHERE cin = ?");
            $stmt_check->execute([$w_cin]);
            $exists_workers = $stmt_check->fetchColumn();

            // Also check Users table to be safe?
            $stmt_check_users = $pdo->prepare("SELECT COUNT(*) FROM users WHERE cin = ?");
            $stmt_check_users->execute([$w_cin]);
            $exists_users = $stmt_check_users->fetchColumn();

            if ($exists_workers > 0 || $exists_users > 0) {
                $error = "⚠️ Error: This CIN ($w_cin) already exists in the system (Worker or User).";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO workers (cin, name, phone, location, department, job_title, shift, manager_cin) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$w_cin, $w_name, $w_phone, $w_location, $w_dept, $w_job, $w_shift, $target_manager]);
                    $msg = "✅ Worker added successfully to " . ($target_manager === $user_cin ? "your" : "selected") . " team!";
                } catch (PDOException $e) {
                    $error = "Database Error: " . $e->getMessage();
                }
            }
        }
    }

    // Delete Worker
    if (isset($_POST['delete_worker'])) {
        $del_id = $_POST['worker_id'];
        // Admin can delete anyone's worker. Manager only theirs.
        if ($is_admin) {
            $stmt = $pdo->prepare("DELETE FROM workers WHERE id = ?");
            $stmt->execute([$del_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM workers WHERE id = ? AND manager_cin = ?");
            $stmt->execute([$del_id, $user_cin]);
        }
        $msg = "🗑️ Worker removed.";
    }
}

// ADMIN: Select Manager View
$view_cin = $user_cin;
$all_managers = [];
if ($is_admin) {
    // Fetch all managers for dropdown
    $stmt_m = $pdo->query("SELECT cin, name FROM users WHERE role = 'manager' ORDER BY name");
    $all_managers = $stmt_m->fetchAll();

    if (isset($_GET['manager_cin']) && !empty($_GET['manager_cin'])) {
        $view_cin = $_GET['manager_cin'];
    }
}

// Fetch Team (Based on View CIN)
$stmt = $pdo->prepare("SELECT * FROM workers WHERE manager_cin = ? ORDER BY name");
$stmt->execute([$view_cin]);
$my_team = $stmt->fetchAll();

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
                    <div style="background:#e9ecef; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #ccc;">
                        <form method="GET" style="display:flex; gap:10px; align-items:center;">
                            <label style="font-weight:bold;">👮 Admin View / اختر الفريق:</label>
                            <select name="manager_cin" onchange="this.form.submit()" style="padding:8px; border-radius:4px; border:1px solid #aaa;">
                                <option value="<?= $_SESSION['user_cin'] ?>">My Team (Admin)</option>
                                <?php foreach ($all_managers as $mgr): ?>
                                        <option value="<?= $mgr['cin'] ?>" <?= $view_cin === $mgr['cin'] ? 'selected' : '' ?>>
                                            Team: <?= htmlspecialchars($mgr['name']) ?> (<?= $mgr['cin'] ?>)
                                        </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
            <?php endif; ?>
            <p style="color:#666; font-size:14px;">Manage your workforce. <br><small>إدارة فريق العمل / Gestion
                    d'équipe</small></p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <?php if ($msg): ?>
                <div class="alert alert-success">
                    <?php echo $msg; ?>
                </div>
            <?php endif; ?>

            <div class="form-box">
                <h4>+ Add New Worker to: <?= htmlspecialchars($view_name) ?></h4>
                <form method="POST">
                    <input type="hidden" name="target_manager" value="<?= htmlspecialchars($view_cin) ?>">
                    <div class="form-grid">
                        <!-- Row 1 -->
                        <div class="form-group">
                            <label>CIN (Unique ID)</label>
                            <input type="text" name="cin" placeholder="AB12345" required pattern="[A-Za-z0-9]+"
                                title="Letters and numbers only">
                        </div>
                        <div class="form-group">
                            <label>Full Name / الاسم الكامل</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-group">
                            <label>Phone / الهاتف</label>
                            <input type="text" name="phone" placeholder="06XXXXXXXX">
                        </div>

                        <!-- Row 2 -->
                        <div class="form-group">
                            <label>Site / موقع العمل</label>
                            <select name="location">
                                <option value="Candy 1">Candy 1</option>
                                <option value="Candy 2">Candy 2</option>
                                <option value="Flora 1">Flora 1</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Dept / القسم</label>
                            <select name="department">
                                <option value="Sewing">Sewing / الخياطة</option>
                                <option value="Cutting">Cutting / القص</option>
                                <option value="Finishing">Finishing / التشطيب</option>
                                <option value="Packing">Packing / التغليف</option>
                                <option value="Warehouse">Warehouse / المستودع</option>
                                <option value="Maintenance">Maintenance / الصيانة</option>
                                <option value="Quality">Quality / الجودة</option>
                                <option value="HR_Admin">HR & Admin / الإدارة</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Job Title / الوظيفة</label>
                            <input type="text" name="job_title" placeholder="Ex: Operator">
                        </div>

                        <!-- Row 3 -->
                        <div class="form-group">
                            <label>Shift / الفترة</label>
                            <select name="shift">
                                <option value="A">Shift A (Matin)</option>
                                <option value="B">Shift B (Après-midi)</option>
                                <option value="C">Shift C (Nuit)</option>
                                <option value="Normal">Normal Day</option>
                            </select>
                        </div>
                        <div class="form-group"></div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" name="add_worker" style="background:#28a745; cursor:pointer;">Save
                                Worker / حفظ</button>
                        </div>
                    </div>
                </form>
            </div>

            <h3>📋 Team List</h3>
            <table>
                <thead>
                    <tr>
                        <th>Info</th>
                        <th>Job / Location</th>
                        <th>Shift</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($my_team) == 0): ?>
                        <tr>
                            <td colspan="4" style="text-align:center;">No workers added yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($my_team as $w):
                        $badgeClass = 'shift-N';
                        if ($w['shift'] == 'A')
                            $badgeClass = 'shift-A';
                        if ($w['shift'] == 'B')
                            $badgeClass = 'shift-B';
                        if ($w['shift'] == 'C')
                            $badgeClass = 'shift-C';
                        ?>
                        <tr>
                            <td>
                                <strong>
                                    <?php echo htmlspecialchars($w['name']); ?>
                                </strong><br>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars($w['cin']); ?>
                                </small>
                                <?php if ($w['phone']): ?><br><small>📞
                                        <?php echo htmlspecialchars($w['phone']); ?>
                                    </small><?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($w['department']); ?> /
                                <?php echo htmlspecialchars($w['location']); ?><br>
                                <small style="color:#666;">
                                    <?php echo htmlspecialchars($w['job_title']); ?>
                                </small>
                            </td>
                            <td><span class="shift-badge <?php echo $badgeClass; ?>">
                                    <?php echo $w['shift']; ?>
                                </span></td>
                            <td>
                                <form method="POST" style="display:inline;"
                                    onsubmit="return confirm('Remove this worker?');">
                                    <input type="hidden" name="worker_id" value="<?php echo $w['id']; ?>">
                                    <button type="submit" name="delete_worker"
                                        style="background:none; color:red; border:none; cursor:pointer; font-size:16px;">🗑️</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
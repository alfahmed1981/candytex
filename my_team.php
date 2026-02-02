<?php
session_start();
require 'db.php';

// Auth Check
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];

// Handle Form Submission (Add Worker)
$msg = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_worker'])) {
        $w_cin = trim($_POST['cin']);
        $w_name = trim($_POST['name']);
        $w_phone = trim($_POST['phone']);
        $w_location = $_POST['location'];
        $w_dept = $_POST['department'];
        $w_job = trim($_POST['job_title']);
        $w_shift = $_POST['shift'];

        // Strict Validation
        if (!preg_match('/^[a-zA-Z0-9]+$/', $w_cin)) {
            $error = "❌ Security Alert: CIN must contain ONLY Latin letters and numbers.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO workers (cin, name, phone, location, department, job_title, shift, manager_cin) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$w_cin, $w_name, $w_phone, $w_location, $w_dept, $w_job, $w_shift, $user_cin]);
                $msg = "✅ Worker added successfully!";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = "⚠️ Error: This CIN already exists.";
                } else {
                    $error = "Database Error: " . $e->getMessage();
                }
            }
        }
    }

    // Delete Worker
    if (isset($_POST['delete_worker'])) {
        $del_id = $_POST['worker_id'];
        $stmt = $pdo->prepare("DELETE FROM workers WHERE id = ? AND manager_cin = ?");
        $stmt->execute([$del_id, $user_cin]);
        $msg = "🗑️ Worker removed.";
    }
}

// Fetch My Team
$stmt = $pdo->prepare("SELECT * FROM workers WHERE manager_cin = ? ORDER BY location, department, shift, name");
$stmt->execute([$user_cin]);
$my_team = $stmt->fetchAll();
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
    <!-- Mobile Header -->
    <div class="mobile-header">
        <h3>👥 My Team</h3>
        <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" onclick="closeSidebar()"></div>

    <div class="sidebar" id="sidebar">
        <button class="close-sidebar" onclick="closeSidebar()">✕</button>
        <div class="profile">
            <h3>👥 HR Manager</h3>
            <p><?php echo $_SESSION['user_name']; ?></p>
        </div>
        <hr>
        <a href="index.php" class="logout-btn" style="background:#007bff; margin-bottom:10px;">📊 Back to Board</a>
        <a href="index.php?logout=1" class="logout-btn" style="background:#dc3545;">Logout</a>
    </div>

    <div class="main-content">
        <div class="container">
            <h2>👷 My Team & Shift Management</h2>
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
                <h4>+ Add New Worker / إضافة عامل / Ajouter</h4>
                <form method="POST">
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
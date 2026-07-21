<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Security Check: ONLY Admins
require_admin();

// --- Self-healing: ensure email & whatsapp columns exist ---
try {
    $pdo->query("SELECT email FROM users LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER phone");
}
try {
    $pdo->query("SELECT whatsapp FROM users LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp VARCHAR(20) DEFAULT NULL AFTER email");
}

// --- Self-healing: ensure role column supports hr_admin ---
try {
    $col_info = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'")->fetch();
    if ($col_info && strpos($col_info['Type'], 'hr_admin') === false) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'viewer'");
    }
} catch (Exception $e) {
    error_log("Failed to migrate role column: " . $e->getMessage());
}

// --- Handle Stop Impersonation (GET - safe, read-only session restore) ---
if (isset($_GET['action']) && $_GET['action'] === 'stop_impersonation') {
    stop_impersonation();
    header("Location: admin.php");
    exit;
}

// --- Handle POST Actions (with CSRF) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf();

    // --- IMPERSONATION ---
    if ($_POST['action'] === 'login_as' && isset($_POST['cin'])) {
        $target_cin = $_POST['cin'];
        audit_log($pdo, 'impersonate', "Admin logged in as: $target_cin");
        if (start_impersonation($pdo, $target_cin)) {
            header("Location: index.php");
            exit;
        }
    }

    // --- DELETE USER ---
    if ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        audit_log($pdo, 'delete_user', "Deleted user ID: $id");
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: admin.php?msg=deleted");
        exit;
    }

    // --- APPROVE USER ---
    if ($_POST['action'] === 'approve' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        audit_log($pdo, 'approve_user', "Approved user ID: $id");
        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: admin.php?msg=Approved");
        exit;
    }

    // --- REJECT USER ---
    if ($_POST['action'] === 'reject' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        audit_log($pdo, 'reject_user', "Rejected user ID: $id");
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: admin.php?msg=Rejected");
        exit;
    }

    // --- EDIT USER ---
    if ($_POST['action'] === 'edit_user' && isset($_POST['edit_id'])) {
        $id = intval($_POST['edit_id']);
        $name = strtoupper(trim($_POST['edit_name']));
        $phone = trim($_POST['edit_phone']);
        $email = trim($_POST['edit_email'] ?? '');
        $whatsapp = trim($_POST['edit_whatsapp'] ?? '');
        $role = $_POST['edit_role'];
        $dept = trim($_POST['edit_department']);
        $loc = trim($_POST['edit_location']);
        $status = $_POST['edit_status'] ?? 'active';
        $birth = $_POST['edit_birth_date'] ?? null;
        if ($birth === '')
            $birth = null;
        
        $password = trim($_POST['edit_password'] ?? '');

        audit_log($pdo, 'edit_user', "Edited user ID: $id — Name: $name, Role: $role");
        
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, email=?, whatsapp=?, role=?, department=?, location=?, status=?, birth_date=?, password=? WHERE id=?");
            $stmt->execute([$name, $phone, $email ?: null, $whatsapp ?: null, $role, $dept, $loc, $status, $birth, $hashed, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, email=?, whatsapp=?, role=?, department=?, location=?, status=?, birth_date=? WHERE id=?");
            $stmt->execute([$name, $phone, $email ?: null, $whatsapp ?: null, $role, $dept, $loc, $status, $birth, $id]);
        }

        header("Location: admin.php?msg=Updated");
        exit;
    }

    // --- APPROVE PENDING EMPLOYEE (added by HR_Admin) ---
    if ($_POST['action'] === 'approve_employee' && isset($_POST['emp_id'])) {
        $emp_id = intval($_POST['emp_id']);
        audit_log($pdo, 'approve_employee', "Approved pending employee ID: $emp_id");
        $stmt = $pdo->prepare("UPDATE hr_employees SET status = 'Active' WHERE id = ? AND status = 'pending_approval'");
        $stmt->execute([$emp_id]);
        header("Location: admin.php?msg=Employee+Approved");
        exit;
    }

    // --- REJECT PENDING EMPLOYEE ---
    if ($_POST['action'] === 'reject_employee' && isset($_POST['emp_id'])) {
        $emp_id = intval($_POST['emp_id']);
        audit_log($pdo, 'reject_employee', "Rejected pending employee ID: $emp_id");
        $stmt = $pdo->prepare("DELETE FROM hr_employees WHERE id = ? AND status = 'pending_approval'");
        $stmt->execute([$emp_id]);
        header("Location: admin.php?msg=Employee+Rejected");
        exit;
    }
}

// --- ADD USER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    require_csrf();
    audit_log($pdo, 'add_user', "Adding user: " . trim($_POST['cin']));
    $cin = strtoupper(str_replace(' ', '', trim($_POST['cin'])));
    $name = strtoupper(trim($_POST['name']));
    $phone = $_POST['phone'];
    $role = $_POST['role'];

    // Default password for admins (should be changed later)
    $password = null;
    if ($role === 'admin') {
        $password = password_hash('123456', PASSWORD_DEFAULT);
    }

    // New Fields
    $dept = $_POST['department'] ?? null;
    $loc = $_POST['location'] ?? null;
    $email = trim($_POST['email'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');

    $stmt = $pdo->prepare("INSERT INTO users (cin, name, phone, email, whatsapp, role, password, department, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$cin, $name, $phone, $email ?: null, $whatsapp ?: null, $role, $password, $dept, $loc]);
        $msg = "User Added!";
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
    }
}

// --- FILTERING ---
$filter_role = $_GET['filter_role'] ?? '';
$filter_location = $_GET['filter_location'] ?? '';

$sql = "SELECT * FROM users WHERE status = 'active'";
$params = [];

if ($filter_role) {
    $sql .= " AND role = ?";
    $params[] = $filter_role;
}
if ($filter_location) {
    $sql .= " AND location = ?";
    $params[] = $filter_location;
}

$sql .= " ORDER BY COALESCE(location, 'ZZZ'), role, name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Group users by location
$grouped = [];
foreach ($users as $u) {
    $loc = $u['location'] ?: '— غير محدد / Non défini';
    $grouped[$loc][] = $u;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Panel - SQD+C</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #333;
        }

        .user-card {
            background: #f9f9f9;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 5px solid #007bff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 4px;
        }

        .user-card.admin {
            border-left-color: #28a745;
            background: #e8f5e9;
        }

        .role-badge {
            background: #007bff;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
        }

        .role-admin {
            background: #28a745;
        }

        input,
        select {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-right: 5px;
        }

        .btn {
            padding: 8px 15px;
            cursor: pointer;
            border: none;
            border-radius: 4px;
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        /* Edit Modal */
        .edit-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1100;
            justify-content: center;
            align-items: center;
        }

        .edit-overlay.show {
            display: flex;
        }

        .edit-modal {
            background: white;
            border-radius: 12px;
            padding: 25px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .edit-modal h3 {
            margin-top: 0;
            color: #007bff;
        }

        .edit-modal label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
            font-size: 13px;
            color: #555;
        }

        .edit-modal input,
        .edit-modal select {
            width: 100%;
            box-sizing: border-box;
            margin-top: 4px;
        }

        .edit-modal .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .btn-edit {
            background: #fd7e14;
        }


        .btn-green {
            background: #28a745;
        }

        .btn-red {
            background: #dc3545;
        }

        .btn-blue {
            background: #007bff;
        }

        .filter-bar {
            background: #eee;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        /* Column Toggle */
        .col-toggles {
            background: #f1f3f5;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .col-toggles label {
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            user-select: none;
            white-space: nowrap;
        }

        .col-toggles label input {
            margin: 0;
        }

        /* Table */
        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background: white;
        }

        .users-table th,
        .users-table td {
            padding: 10px 12px;
            text-align: right;
            border-bottom: 1px solid #e9ecef;
            white-space: nowrap;
        }

        .users-table th {
            background: #343a40;
            color: white;
            position: sticky;
            top: 0;
            font-weight: 600;
            font-size: 13px;
        }

        .users-table tr:hover {
            background: #f8f9fa;
        }

        .users-table .actions-cell {
            white-space: nowrap;
            display: flex;
            gap: 4px;
        }

        .users-table .actions-cell form {
            display: inline;
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .location-header {
            background: linear-gradient(135deg, #007bff, #6610f2);
            color: white;
            padding: 10px 15px;
            font-weight: bold;
            font-size: 15px;
            border-radius: 8px;
            margin: 20px 0 10px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .location-header:first-of-type {
            margin-top: 10px;
        }

        .location-header .count {
            background: rgba(255, 255, 255, 0.25);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 13px;
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="main-content">
        <div class="container">
            <h1>👥 User Management / إدارة المستخدمين</h1>
            <p>Add, edit, or impersonate users. <small>Gestion des utilisateurs</small></p>

            <!-- Quick Links -->
            <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
                <a href="admin_daily.php" class="btn btn-blue" style="background:#28a745;">📸 Daily Snapshot</a>
                <a href="admin_reports.php" class="btn btn-blue" style="background:#fd7e14;">📅 Monthly Matrix</a>
                <a href="admin_advanced.php" class="btn btn-blue" style="background:#6f42c1;">⚙️ Advanced</a>
                <a href="admin_email.php" class="btn btn-blue" style="background:#e83e8c;">📧 Email Settings</a>
                <a href="import_users.php" class="btn btn-blue" style="background:#17a2b8;">📥 Import CSV</a>
                <a href="hr_employees.php" class="btn btn-blue" style="background:#20c997;">👥 HR Management</a>
                <a href="iso_ncr.php" class="btn btn-blue" style="background:#0b3c5d;">🏭 ISO NCR/CAR</a>
                <a href="iso_risk.php" class="btn btn-blue" style="background:#c0392b;">📋 Risk Register</a>
                <a href="iso_docs.php" class="btn btn-blue" style="background:#2e7d32;">📄 Document Control</a>
                <a href="meetings.php" class="btn btn-blue" style="background:#e65100;">🗓️ Meetings</a>
                <a href="admin_backup.php" class="btn btn-blue" style="background:#455a64;">💾 Backup</a>
            </div>

            <?php if (isset($msg))
                echo "<p style='color:green; background:#d4edda; padding:10px; border:1px solid #c3e6cb; border-radius:4px;'>$msg</p>"; ?>

            <!-- PENDING APPROVALS -->
            <?php
            $pending_stmt = $pdo->query("SELECT * FROM users WHERE status = 'pending' ORDER BY id DESC");
            $pending_users = $pending_stmt->fetchAll();
            ?>
            <?php if (count($pending_users) > 0): ?>
                <div
                    style="background:#fff3cd; color:#856404; padding:15px; border:1px solid #ffeeba; border-radius:8px; margin-bottom:20px;">
                    <h3>🔔 Pending Registrations (<?= count($pending_users) ?>) / طلبات التسجيل</h3>
                    <table style="width:100%; margin-top:10px; background:white; border-collapse:collapse;">
                        <?php foreach ($pending_users as $pu): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:10px;">
                                    <strong><?= htmlspecialchars($pu['name']) ?></strong>
                                    (<?= htmlspecialchars($pu['cin']) ?>)<br>
                                    <small>Role: <?= htmlspecialchars($pu['role']) ?></small>
                                </td>
                                <td style="text-align:right; padding:10px; display:flex; gap:5px; justify-content:flex-end;">
                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= $pu['id'] ?>">
                                        <button type="submit" class="btn btn-green" style="padding:5px 10px; font-size:12px;">✅
                                            Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Reject this user?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?= $pu['id'] ?>">
                                        <button type="submit" class="btn btn-red" style="padding:5px 10px; font-size:12px;">❌
                                            Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>

            <!-- PENDING EMPLOYEES (added by HR_Admin) -->
            <?php
            try {
                $pending_emp_stmt = $pdo->query("SELECT e.*, l.name as location_name FROM hr_employees e LEFT JOIN locations l ON e.location_id = l.id WHERE e.status = 'pending_approval' ORDER BY e.id DESC");
                $pending_employees = $pending_emp_stmt->fetchAll();
            } catch (Exception $e) {
                $pending_employees = [];
            }
            ?>
            <?php if (count($pending_employees) > 0): ?>
                <div
                    style="background:#e8f4fd; color:#0c5460; padding:15px; border:1px solid #bee5eb; border-radius:8px; margin-bottom:20px;">
                    <h3>👥 Pending Employees (<?= count($pending_employees) ?>) / موظفون بانتظار الموافقة</h3>
                    <p style="font-size:0.85em; margin:5px 0 10px;">تمت إضافتهم من طرف HR_Admin — يحتاجون لموافقتك قبل التفعيل</p>
                    <table style="width:100%; margin-top:10px; background:white; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f0f0f0;">
                                <th style="padding:8px; text-align:left;">Employee</th>
                                <th style="padding:8px; text-align:left;">Matricule</th>
                                <th style="padding:8px; text-align:left;">Location</th>
                                <th style="padding:8px; text-align:left;">Department</th>
                                <th style="padding:8px; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending_employees as $pe): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:10px;">
                                    <strong><?= htmlspecialchars($pe['full_name']) ?></strong><br>
                                    <small>CIN: <?= htmlspecialchars($pe['cin']) ?></small>
                                </td>
                                <td style="padding:10px;"><?= htmlspecialchars($pe['matricule']) ?></td>
                                <td style="padding:10px;"><?= htmlspecialchars($pe['location_name'] ?? 'N/A') ?></td>
                                <td style="padding:10px;"><?= htmlspecialchars($pe['department'] ?? 'N/A') ?></td>
                                <td style="text-align:right; padding:10px; display:flex; gap:5px; justify-content:flex-end;">
                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve_employee">
                                        <input type="hidden" name="emp_id" value="<?= $pe['id'] ?>">
                                        <button type="submit" class="btn btn-green" style="padding:5px 10px; font-size:12px;">✅
                                            Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Reject and delete this employee?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject_employee">
                                        <input type="hidden" name="emp_id" value="<?= $pe['id'] ?>">
                                        <button type="submit" class="btn btn-red" style="padding:5px 10px; font-size:12px;">❌
                                            Reject</button>
                                    </form>
                                    <a href="hr_employees.php?status=All&search=<?= urlencode($pe['cin']) ?>" 
                                       class="btn" style="padding:5px 10px; font-size:12px; background:#17a2b8; color:white; border-radius:4px; text-decoration:none;">👁️ View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php
            // Fetch Dynamic Data for Dropdowns
            $d_stmt = $pdo->query("SELECT name FROM departments ORDER BY name");
            $dept_list = $d_stmt->fetchAll(PDO::FETCH_COLUMN);

            $l_stmt = $pdo->query("SELECT name FROM locations ORDER BY name");
            $loc_list = $l_stmt->fetchAll(PDO::FETCH_COLUMN);

            $role_list = [
                'admin' => 'المدير العام / General Admin',
                'hr' => 'مدير المصنع (HR) / Factory Admin',
                'hr_admin' => 'مدير موارد بشرية (بحسب الموقع) / HR Manager (Location)',
                'manager' => 'رئيس فريق / Team Leader',
                'viewer' => 'مشاهد / Viewer'
            ];
            ?>

            <!-- Add User Form -->
            <div style="background:#f1f1f1; padding:20px; border-radius:8px; margin-bottom:20px;">
                <h3>+ Add New User / إضافة مستخدم</h3>
                <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap;">
                    <?= csrf_field() ?>
                    <input type="text" name="cin" placeholder="CIN (Login ID)" required
                        style="width:120px; text-transform:uppercase;">
                    <input type="text" name="name" placeholder="Full Name / الاسم" required>
                    <input type="text" name="phone" placeholder="Phone" required style="width:120px;">
                    <input type="email" name="email" placeholder="Email" style="width:150px;">
                    <input type="text" name="whatsapp" placeholder="WhatsApp" style="width:120px;">

                    <!-- Dynamic Department -->
                    <select name="department" style="width:130px;">
                        <option value="">Dept...</option>
                        <?php foreach ($dept_list as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Dynamic Location -->
                    <select name="location" style="width:130px;">
                        <option value="">Loc...</option>
                        <?php foreach ($loc_list as $l): ?>
                            <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Dynamic Roles -->
                    <select name="role">
                        <?php foreach ($role_list as $slug => $r_name): ?>
                            <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($r_name) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="add_user" class="btn btn-green">Add User</button>
                </form>
            </div>

            <!-- Filters -->
            <div class="filter-bar">
                <strong>🔍 Filter:</strong>
                <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <select name="filter_role">
                        <option value="">All Roles / كل الأدوار</option>
                        <option value="admin" <?= $filter_role == 'admin' ? 'selected' : '' ?>>Admin / مدير</option>
                        <option value="manager" <?= $filter_role == 'manager' ? 'selected' : '' ?>>Team Leader / رئيس فريق
                        </option>
                        <option value="viewer" <?= $filter_role == 'viewer' ? 'selected' : '' ?>>Viewer / مشاهد</option>
                    </select>
                    <select name="filter_location">
                        <option value="">All Locations / كل المواقع</option>
                        <?php foreach ($loc_list as $l): ?>
                            <option value="<?= htmlspecialchars($l) ?>" <?= $filter_location == $l ? 'selected' : '' ?>>
                                <?= htmlspecialchars($l) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-blue">Filter</button>
                    <a href="admin.php" class="btn" style="background:#6c757d;">Reset</a>
                </form>
            </div>

            <!-- Column Toggles -->
            <div class="col-toggles">
                <strong style="font-size:13px;">👁️ Columns / الأعمدة:</strong>
                <label><input type="checkbox" checked onchange="toggleCol('col-id')">#</label>
                <label><input type="checkbox" checked onchange="toggleCol('col-cin')">CIN</label>
                <label><input type="checkbox" checked onchange="toggleCol('col-name')">Name / الاسم</label>
                <label><input type="checkbox" checked onchange="toggleCol('col-phone')">Phone / الهاتف</label>
                <label><input type="checkbox" checked onchange="toggleCol('col-role')">Role / الدور</label>
                <label><input type="checkbox" checked onchange="toggleCol('col-dept')">Department / القسم</label>
                <label><input type="checkbox" checked onchange="toggleCol('col-loc')">Location / الموقع</label>
                <label><input type="checkbox" onchange="toggleCol('col-email')">Email / البريد</label>
                <label><input type="checkbox" onchange="toggleCol('col-whatsapp')">WhatsApp</label>
                <label><input type="checkbox" onchange="toggleCol('col-birth')">Birth Date / تاريخ الميلاد</label>
                <label><input type="checkbox" onchange="toggleCol('col-status')">Status / الحالة</label>
                <label><input type="checkbox" onchange="toggleCol('col-created')">Created / تاريخ التسجيل</label>
            </div>

            <!-- User List -->
            <h3>📋 User List / قائمة المستخدمين (<?= count($users) ?>)</h3>

            <?php if (count($users) == 0): ?>
                <p style="text-align:center; color:#999; padding:30px;">No users match the current filters. / لا يوجد
                    مستخدمون يطابقون الفلتر.</p>
            <?php else: ?>
                <?php foreach ($grouped as $location => $loc_users): ?>
                    <div class="location-header">
                        🏭 <?= htmlspecialchars($location) ?>
                        <span class="count"><?= count($loc_users) ?></span>
                    </div>
                    <div class="table-wrapper">
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th class="col-id">#</th>
                                    <th class="col-cin">CIN</th>
                                    <th class="col-name">Name<br><small>الاسم</small></th>
                                    <th class="col-phone">Phone<br><small>الهاتف</small></th>
                                    <th class="col-role">Role<br><small>الدور</small></th>
                                    <th class="col-dept">Department<br><small>القسم</small></th>
                                    <th class="col-loc">Location<br><small>الموقع</small></th>
                                    <th class="col-email" style="display:none;">Email<br><small>البريد</small></th>
                                    <th class="col-whatsapp" style="display:none;">WhatsApp</th>
                                    <th class="col-birth" style="display:none;">Birth Date<br><small>تاريخ الميلاد</small></th>
                                    <th class="col-status" style="display:none;">Status<br><small>الحالة</small></th>
                                    <th class="col-created" style="display:none;">Created<br><small>تاريخ التسجيل</small></th>
                                    <th>Actions<br><small>إجراءات</small></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loc_users as $u):
                                    $roleBadge = match ($u['role']) {
                                        'admin' => 'background:#28a745;',
                                        'manager' => 'background:#007bff;',
                                        default => 'background:#6c757d;'
                                    };
                                    $roleLabel = match ($u['role']) {
                                        'admin' => 'Admin',
                                        'manager' => 'Team Leader',
                                        default => 'Viewer'
                                    };
                                    ?>
                                    <tr>
                                        <td class="col-id"><?= $u['id'] ?></td>
                                        <td class="col-cin" style="font-family:monospace;"><?= htmlspecialchars($u['cin']) ?></td>
                                        <td class="col-name"><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                                        <td class="col-phone"><?= htmlspecialchars($u['phone']) ?></td>
                                        <td class="col-role"><span class="role-badge"
                                                style="<?= $roleBadge ?>"><?= $roleLabel ?></span></td>
                                        <td class="col-dept"><?= htmlspecialchars($u['department'] ?? '—') ?></td>
                                        <td class="col-loc"><?= htmlspecialchars($u['location'] ?? '—') ?></td>
                                        <td class="col-email" style="display:none;"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                                        <td class="col-whatsapp" style="display:none;">
                                            <?= htmlspecialchars($u['whatsapp'] ?? '—') ?>
                                        </td>
                                        <td class="col-birth" style="display:none;"><?= $u['birth_date'] ?? '—' ?></td>
                                        <td class="col-status" style="display:none;"><span
                                                style="color:<?= $u['status'] === 'active' ? '#28a745' : '#fd7e14' ?>;"><?= $u['status'] === 'active' ? '✅ Active' : '⏳ Pending' ?></span>
                                        </td>
                                        <td class="col-created" style="display:none;">
                                            <?= isset($u['created_at']) ? date('Y-m-d', strtotime($u['created_at'])) : '—' ?>
                                        </td>
                                        <td>
                                            <div class="actions-cell">
                                                <button type="button" class="btn btn-edit" style="padding:4px 8px; font-size:12px;"
                                                    onclick='openEditModal(<?= json_encode([
                                                        "id" => $u["id"],
                                                        "name" => $u["name"],
                                                        "phone" => $u["phone"],
                                                        "email" => $u["email"] ?? "",
                                                        "whatsapp" => $u["whatsapp"] ?? "",
                                                        "role" => $u["role"],
                                                        "department" => $u["department"] ?? "",
                                                        "location" => $u["location"] ?? "",
                                                        "status" => $u["status"] ?? "active",
                                                        "birth_date" => $u["birth_date"] ?? ""
                                                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏️</button>
                                                <form method="POST"
                                                    onsubmit="return confirm('Login as <?= htmlspecialchars(addslashes($u['name']), ENT_QUOTES) ?>?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="login_as">
                                                    <input type="hidden" name="cin" value="<?= htmlspecialchars($u['cin']) ?>">
                                                    <button type="submit" class="btn btn-blue"
                                                        style="padding:4px 8px; font-size:12px;">🕵️</button>
                                                </form>
                                                <?php if ($u['cin'] !== 'admin'): ?>
                                                    <form method="POST" onsubmit="return confirm('Delete this user?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="btn btn-red"
                                                            style="padding:4px 8px; font-size:12px;">🗑️</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="edit-overlay" id="editOverlay" onclick="if(event.target===this)closeEditModal()">
        <div class="edit-modal" style="max-width:600px; max-height:90vh; overflow-y:auto;">
            <h3>✏️ Edit User / تعديل المستخدم</h3>
            <form method="POST" id="editForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="edit_id" id="edit_id">

                <label>Name / الاسم</label>
                <input type="text" name="edit_name" id="edit_name" required style="text-transform:uppercase;">

                <label>Phone / الهاتف</label>
                <input type="text" name="edit_phone" id="edit_phone">

                <label>📧 Email / البريد الإلكتروني</label>
                <input type="email" name="edit_email" id="edit_email" placeholder="user@example.com">

                <label>📱 WhatsApp / هاتف واتساب</label>
                <input type="text" name="edit_whatsapp" id="edit_whatsapp" placeholder="06XXXXXXXX">

                <label>Role / الدور</label>
                <select name="edit_role" id="edit_role">
                    <?php foreach ($role_list as $slug => $r_name): ?>
                        <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($r_name) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Department / القسم</label>
                <select name="edit_department" id="edit_department">
                    <option value="">-- Select / اختيار --</option>
                    <?php foreach ($dept_list as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Location / الموقع</label>
                <select name="edit_location" id="edit_location">
                    <option value="">-- Select / اختيار --</option>
                    <?php foreach ($loc_list as $l): ?>
                        <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Status / الحالة</label>
                <select name="edit_status" id="edit_status">
                    <option value="active">Active / نشط</option>
                    <option value="pending">Pending / معلق</option>
                </select>

                <label>Birth Date / تاريخ الميلاد</label>
                <input type="date" name="edit_birth_date" id="edit_birth_date">

                <hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;">
                <label style="color:#d35400;">New Password / كلمة المرور الجديدة</label>
                <input type="text" name="edit_password" id="edit_password" placeholder="Leave blank to keep current password / اتركها فارغة إذا لم ترد التغيير">


                <div class="modal-actions">
                    <button type="button" class="btn" style="background:#6c757d;" onclick="closeEditModal()">Cancel /
                        إلغاء</button>
                    <button type="submit" class="btn btn-blue">💾 Save / حفظ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_name').value = data.name;
            document.getElementById('edit_phone').value = data.phone;
            document.getElementById('edit_email').value = data.email || '';
            document.getElementById('edit_whatsapp').value = data.whatsapp || '';
            document.getElementById('edit_role').value = data.role;
            document.getElementById('edit_department').value = data.department;
            document.getElementById('edit_location').value = data.location;
            document.getElementById('edit_status').value = data.status || 'active';
            document.getElementById('edit_birth_date').value = data.birth_date || '';
            document.getElementById('editOverlay').classList.add('show');
        }
        function closeEditModal() {
            document.getElementById('editOverlay').classList.remove('show');
        }
        function toggleCol(className) {
            document.querySelectorAll('.' + className).forEach(el => {
                el.style.display = el.style.display === 'none' ? '' : 'none';
            });
        }
    </script>
</body>

</html>
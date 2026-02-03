<?php
session_start();
require 'db.php';

// Security Check: ONLY Admins
if (!isset($_SESSION['user_cin']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied. Admins Only.");
}

// Handle Actions
if (isset($_GET['action'])) {

    // --- IMPERSONATION (The "Login As" Feature) ---
    if ($_GET['action'] === 'login_as' && isset($_GET['cin'])) {
        $target_cin = $_GET['cin'];

        // Fetch target user details
        $stmt = $pdo->prepare("SELECT * FROM users WHERE cin = ?");
        $stmt->execute([$target_cin]);
        $target = $stmt->fetch();

        if ($target) {
            // Switch Session to Target User
            $_SESSION['user_cin'] = $target['cin'];
            $_SESSION['user_name'] = $target['name'];
            $_SESSION['role'] = $target['role'];

            // Optional: Set a flag to remember we are impersonating
            $_SESSION['is_impersonating'] = true;

            header("Location: index.php");
            exit;
        }
    }

    // --- DELETE USER ---
    if ($_GET['action'] === 'delete' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header("Location: admin.php?msg=deleted");
        exit;
    }

    // --- APPROVE USER ---
    if ($_GET['action'] === 'approve' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header("Location: admin.php?msg=Approved");
        exit;
    }

    // --- REJECT USER ---
    if ($_GET['action'] === 'reject' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header("Location: admin.php?msg=Rejected");
        exit;
    }
}

// --- ADD USER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
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

    $stmt = $pdo->prepare("INSERT INTO users (cin, name, phone, role, password, department, location) VALUES (?, ?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$cin, $name, $phone, $role, $password, $dept, $loc]);
        $msg = "User Added!";
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
    }
}

// --- FILTERING ---
// Note: location and department columns may not exist yet
$filter_role = $_GET['filter_role'] ?? '';

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($filter_role) {
    $sql .= " AND role = ?";
    $params[] = $filter_role;
}

$sql .= " ORDER BY role, name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
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
    </style>
</head>

<body>
    <!-- Mobile Top Navigation -->
    <div class="top-nav">
        <div class="top-nav-header">
            <h3>⚙️ Admin Panel</h3>
        </div>
        <div class="nav-links">
            <a href="index.php">📊 لوحة</a>
            <a href="admin.php" class="active">⚙️ إدارة</a>
            <a href="global.php">🏭 عام</a>
            <a href="index.php?logout=1" class="logout">خروج</a>
        </div>
    </div>

    <!-- Desktop Sidebar -->
    <div class="sidebar">
        <h3>⚙️ Admin Panel</h3>
        <p>Managing Users</p>
        <hr>
        <a href="index.php" class="logout-btn" style="background:#007bff;">📊 Board</a>
        <a href="global.php" class="logout-btn" style="background:#6f42c1;">🏭 Global</a>
        <a href="index.php?logout=1" class="logout-btn" style="background:#dc3545;">Logout</a>
    </div>

    <div class="main-content">
        <div class="container">
            <h1>👥 User Management / إدارة المستخدمين</h1>
            <p>Add, edit, or impersonate users. <small>Gestion des utilisateurs</small></p>

            <!-- Quick Links -->
            <div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
                <a href="admin_daily.php" class="btn btn-blue" style="background:#28a745;">📸 Daily Snapshot</a>
                <a href="admin_reports.php" class="btn btn-blue" style="background:#fd7e14;">📅 Monthly Matrix</a>
                <a href="admin_advanced.php" class="btn btn-blue" style="background:#6f42c1;">⚙️ Advanced</a>
                <a href="import_users.php" class="btn btn-blue" style="background:#17a2b8;">📥 Import CSV</a>
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
                                <td style="text-align:right; padding:10px;">
                                    <a href="?action=approve&id=<?= $pu['id'] ?>" class="btn btn-green"
                                        style="padding:5px 10px; font-size:12px;">✅ Approve</a>
                                    <a href="?action=reject&id=<?= $pu['id'] ?>" class="btn btn-red"
                                        style="padding:5px 10px; font-size:12px;"
                                        onclick="return confirm('Reject this user?')">❌ Reject</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>

            <?php
            // Fetch Dynamic Data for Dropdowns
            $d_stmt = $pdo->query("SELECT name FROM departments ORDER BY name");
            $dept_list = $d_stmt->fetchAll(PDO::FETCH_COLUMN);

            $l_stmt = $pdo->query("SELECT name FROM locations ORDER BY name");
            $loc_list = $l_stmt->fetchAll(PDO::FETCH_COLUMN);

            $r_stmt = $pdo->query("SELECT slug, name FROM system_roles ORDER BY name");
            $role_list = $r_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            // Fallback for roles if table empty (initial migration)
            if (empty($role_list)) {
                $role_list = ['manager' => 'Manager / Chef d\'équipe', 'admin' => 'Administrator'];
            }
            ?>

            <!-- Add User Form -->
            <div style="background:#f1f1f1; padding:20px; border-radius:8px; margin-bottom:20px;">
                <h3>+ Add New User / إضافة مستخدم</h3>
                <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap;">
                    <input type="text" name="cin" placeholder="CIN (Login ID)" required
                        style="width:120px; text-transform:uppercase;">
                    <input type="text" name="name" placeholder="Full Name / الاسم" required>
                    <input type="text" name="phone" placeholder="Phone" required style="width:120px;">

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
                        <option value="admin" <?php if ($filter_role == 'admin')
                            echo 'selected'; ?>>Admin</option>
                        <option value="manager" <?php if ($filter_role == 'manager')
                            echo 'selected'; ?>>Manager</option>
                    </select>
                    <button type="submit" class="btn btn-blue">Filter</button>
                    <a href="admin.php" class="btn" style="background:#6c757d;">Reset</a>
                </form>
            </div>

            <!-- List -->
            <h3>📋 User List (
                <?php echo count($users); ?>)
            </h3>
            <?php foreach ($users as $u):
                $roleClass = ($u['role'] === 'admin') ? 'admin' : '';
                $roleBadge = ($u['role'] === 'admin') ? 'role-admin' : '';
                ?>
                <div class="user-card <?php echo $roleClass; ?>">
                    <div>
                        <strong>
                            <?php echo htmlspecialchars($u['name']); ?>
                        </strong>
                        <span class="role-badge <?php echo $roleBadge; ?>">
                            <?php echo strtoupper($u['role']); ?>
                        </span>
                        <br>
                        <small>CIN:
                            <?php echo htmlspecialchars($u['cin']); ?> | Phone:
                            <?php echo htmlspecialchars($u['phone']); ?>
                        </small>
                        <?php if (!empty($u['location'])): ?>
                            <br><small style="color:#007bff;">📍
                                <?php echo htmlspecialchars($u['location']); ?> -
                                <?php echo htmlspecialchars($u['department']); ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="?action=login_as&cin=<?php echo urlencode($u['cin']); ?>" class="btn btn-blue"
                            onclick="return confirm('Login as <?php echo htmlspecialchars($u['name']); ?>?');">🕵️ Login
                            As</a>
                        <?php if ($u['cin'] !== 'admin'): ?>
                            <a href="?action=delete&id=<?php echo $u['id']; ?>" class="btn btn-red"
                                onclick="return confirm('Delete this user?');">🗑️ Delete</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (count($users) == 0): ?>
                <p style="text-align:center; color:#999;">No users found matching the filters.</p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
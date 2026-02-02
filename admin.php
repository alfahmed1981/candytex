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
}

// --- ADD USER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $cin = $_POST['cin'];
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $role = $_POST['role'];

    // Default password for admins (should be changed later)
    $password = null;
    if ($role === 'admin') {
        $password = password_hash('123456', PASSWORD_DEFAULT);
    }

    $stmt = $pdo->prepare("INSERT INTO users (cin, name, phone, role, password) VALUES (?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$cin, $name, $phone, $role, $password]);
        $msg = "User Added!";
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
    }
}

// --- FILTERING ---
$filter_loc = $_GET['filter_loc'] ?? '';
$filter_dept = $_GET['filter_dept'] ?? '';

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($filter_loc) {
    $sql .= " AND location = ?";
    $params[] = $filter_loc;
}
if ($filter_dept) {
    $sql .= " AND department = ?";
    $params[] = $filter_dept;
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
    <div class="sidebar">
        <h3>⚙️ Admin Panel</h3>
        <p>Managing Users</p>
        <hr>
        <a href="index.php" class="logout-btn" style="background:#007bff; margin-bottom:10px;"> Back to Board</a>
        <a href="global.php" class="logout-btn" style="background:#6f42c1; margin-bottom:10px;">Global View</a>
        <a href="index.php?logout=1" class="logout-btn">Logout</a>
    </div>

    <div class="main-content">
        <div class="container">
            <h1>👥 User Management / إدارة المستخدمين</h1>
            <p>Add, edit, or impersonate users. <small>Gestion des utilisateurs</small></p>

            <?php if (isset($msg))
                echo "<p style='color:green; background:#d4edda; padding:10px; border:1px solid #c3e6cb; border-radius:4px;'>$msg</p>"; ?>

            <!-- Add User Form -->
            <div style="background:#f1f1f1; padding:20px; border-radius:8px; margin-bottom:20px;">
                <h3>+ Add New User / إضافة مستخدم</h3>
                <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap;">
                    <input type="text" name="cin" placeholder="CIN (Login ID)" required>
                    <input type="text" name="name" placeholder="Full Name / الاسم" required>
                    <input type="text" name="phone" placeholder="Phone (Password)" required>
                    <select name="role">
                        <option value="manager">Manager / Chef d'équipe</option>
                        <option value="admin">Administrator</option>
                    </select>
                    <button type="submit" name="add_user" class="btn btn-green">Add User</button>
                </form>
            </div>

            <!-- Filters -->
            <div class="filter-bar">
                <strong>🔍 Filter Leaders:</strong>
                <form method="GET" style="display:flex; gap:10px; align-items:center;">
                    <select name="filter_loc">
                        <option value="">All Locations / كل المواقع</option>
                        <option value="Candy 1" <?php if ($filter_loc == 'Candy 1')
                            echo 'selected'; ?>>Candy 1</option>
                        <option value="Candy 2" <?php if ($filter_loc == 'Candy 2')
                            echo 'selected'; ?>>Candy 2</option>
                        <option value="Flora 1" <?php if ($filter_loc == 'Flora 1')
                            echo 'selected'; ?>>Flora 1</option>
                    </select>
                    <select name="filter_dept">
                        <option value="">All Departments / كل الأقسام</option>
                        <option value="Sewing" <?php if ($filter_dept == 'Sewing')
                            echo 'selected'; ?>>Sewing / الخياطة
                        </option>
                        <option value="Cutting" <?php if ($filter_dept == 'Cutting')
                            echo 'selected'; ?>>Cutting / القص
                        </option>
                        <option value="Maintenance" <?php if ($filter_dept == 'Maintenance')
                            echo 'selected'; ?>
                            >Maintenance / الصيانة</option>
                        <option value="Warehouse" <?php if ($filter_dept == 'Warehouse')
                            echo 'selected'; ?>>Warehouse /
                            المستودع</option>
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
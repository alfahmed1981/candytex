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
            $_SESSION['role'] = $target['role']; // Might be 'manager'

            // Optional: Set a flag to remember we are impersonating (to switch back easily)
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

// Fetch All Users
$users = $pdo->query("SELECT * FROM users ORDER BY role, name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Panel - SQD+C</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container {
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        th,
        td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #2c3e50;
            color: white;
        }

        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
            text-decoration: none;
            border-radius: 4px;
            color: white;
            border: none;
            cursor: pointer;
        }

        .btn-login {
            background: #28a745;
        }

        .btn-del {
            background: #dc3545;
        }

        .form-inline {
            display: flex;
            gap: 10px;
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }

        .form-inline input,
        .form-inline select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="profile">
            <h3>🛡️ Admin Panel</h3>
            <p>
                <?php echo $_SESSION['user_name']; ?>
            </p>
        </div>
        <hr>
        <a href="index.php" class="logout-btn" style="background:#007bff; margin-bottom:10px;">My Board</a>
        <a href="global.php" class="logout-btn" style="background:#6f42c1; margin-bottom:10px;">Global View</a>
        <a href="index.php?logout=1" class="logout-btn">Logout</a>
    </div>

    <div class="main-content">
        <div class="admin-container">
            <h2>👥 Team Management / إدارة الفريق</h2>

            <!-- Add User Form -->
            <form method="POST" class="form-inline">
                <input type="text" name="cin" placeholder="CIN (Login ID)" required>
                <input type="text" name="name" placeholder="Full Name / الاسم" required>
                <input type="text" name="phone" placeholder="Phone (Pass)" required>
                <select name="role">
                    <option value="manager">Manager (Standard)</option>
                    <option value="admin">Admin</option>
                </select>
                <button type="submit" name="add_user" class="add-btn" style="width:auto;">+ Add User</button>
            </form>

            <!-- User List -->
            <table>
                <thead>
                    <tr>
                        <th>CIN</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($u['cin']); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($u['name']); ?>
                            </td>
                            <td>
                                <span
                                    style="padding: 2px 5px; border-radius: 4px; background: <?php echo $u['role'] == 'admin' ? '#ffd700' : '#e9ecef'; ?>;">
                                    <?php echo $u['role']; ?>
                                </span>
                            </td>
                            <td>
                                <!-- IMPERSONATION BUTTON -->
                                <?php if ($u['cin'] !== $_SESSION['user_cin']): ?>
                                    <a href="?action=login_as&cin=<?php echo $u['cin']; ?>" class="btn-small btn-login">
                                        🔑 Open Board
                                    </a>
                                    <a href="?action=delete&id=<?php echo $u['id']; ?>" class="btn-small btn-del"
                                        onclick="return confirm('Are you sure?');">
                                        🗑️
                                    </a>
                                <?php else: ?>
                                    (You)
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>
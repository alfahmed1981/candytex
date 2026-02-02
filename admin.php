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
        .container { max-width: 1200px; margin: 20px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .user-card { background: #f9f9f9; padding: 15px; margin-bottom: 10px; border-left: 5px solid #007bff; display: flex; justify-content: space-between; align-items: center; border-radius: 4px; }
        .user-card.admin { border-left-color: #28a745; background: #e8f5e9; }
        .role-badge { background: #007bff; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; }
        .role-admin { background: #28a745; }
        
        input, select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; margin-right: 5px; }
        .btn { padding: 8px 15px; cursor: pointer; border: none; border-radius: 4px; color: white; text-decoration: none; font-size: 14px; }
        .btn-green { background: #28a745; }
        .btn-red { background: #dc3545; }
        .btn-blue { background: #007bff; }
        
        .filter-bar { background: #eee; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h3>⚙️ Admin Panel</h3>
        <p>Managing Users</p>
        <hr>
        <a href="index.php" class="logout-btn" style="background:#007bff; margin-bottom:10px;"> Back to Board</a>
    </div>

    <div class="main-content">
        <div class="container">
            <h1>👥 User Management</h1>
            <p>Add, edit, or impersonate users. <br><small>إدارة المستخدمين / Gestion des utilisateurs</small></p>

            <?php if (isset($msg)) echo "<p style='color:green; background:#d4edda; padding:10px; border:1px solid #c3e6cb;'>$msg</p>"; ?>

            <!-- Add User Form -->
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
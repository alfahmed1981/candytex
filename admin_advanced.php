<?php
session_start();
require 'db.php';

// Security Check: ONLY Admins
if (!isset($_SESSION['user_cin']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied. Admins Only.");
}

$msg = "";
$error = "";

// --- HANDLE FORM SUBMISSIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. LOCATIONS
    if (isset($_POST['add_location'])) {
        $name = trim($_POST['name']);
        if (!empty($name)) {
            try {
                $pdo->prepare("INSERT INTO locations (name) VALUES (?)")->execute([$name]);
                $msg = "Location added!";
            } catch (PDOException $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    if (isset($_POST['del_location'])) {
        $pdo->prepare("DELETE FROM locations WHERE id = ?")->execute([$_POST['id']]);
        $msg = "Location deleted!";
    }

    // 2. DEPARTMENTS
    if (isset($_POST['add_dept'])) {
        $name = trim($_POST['name']);
        if (!empty($name)) {
            try {
                $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$name]);
                $msg = "Department added!";
            } catch (PDOException $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    if (isset($_POST['del_dept'])) {
        $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([$_POST['id']]);
        $msg = "Department deleted!";
    }

    // 3. SHIFTS
    if (isset($_POST['add_shift'])) {
        $name = trim($_POST['name']);
        $code = trim($_POST['code']);
        if (!empty($name) && !empty($code)) {
            try {
                $pdo->prepare("INSERT INTO shifts (name, code) VALUES (?, ?)")->execute([$name, $code]);
                $msg = "Shift added!";
            } catch (PDOException $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    if (isset($_POST['del_shift'])) {
        $pdo->prepare("DELETE FROM shifts WHERE id = ?")->execute([$_POST['id']]);
        $msg = "Shift deleted!";
    }

    // 4. ROLES
    if (isset($_POST['add_role'])) {
        $name = trim($_POST['name']);
        $slug = trim($_POST['slug']);
        if (!empty($name) && !empty($slug)) {
            try {
                $pdo->prepare("INSERT INTO system_roles (name, slug) VALUES (?, ?)")->execute([$name, $slug]);
                $msg = "Role added!";
            } catch (PDOException $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    if (isset($_POST['del_role'])) {
        // Prevent deleting admin or manager to avoid lockout
        $stmt = $pdo->prepare("SELECT slug FROM system_roles WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $r = $stmt->fetch();
        if ($r && ($r['slug'] === 'admin' || $r['slug'] === 'manager')) {
            $error = "Cannot delete core system roles (admin/manager).";
        } else {
            $pdo->prepare("DELETE FROM system_roles WHERE id = ?")->execute([$_POST['id']]);
            $msg = "Role deleted!";
        }
    }
}

// --- FETCH DATA ---
$locations = $pdo->query("SELECT * FROM locations ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$shifts = $pdo->query("SELECT * FROM shifts ORDER BY code")->fetchAll();
$roles = $pdo->query("SELECT * FROM system_roles ORDER BY name")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Advanced Settings - SQD+C</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .card h3 {
            margin-top: 0;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .item-list {
            list-style: none;
            padding: 0;
            max-height: 300px;
            overflow-y: auto;
        }

        .item-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #f1f1f1;
        }

        .delete-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 16px;
        }

        .add-form {
            display: flex;
            gap: 5px;
            margin-top: 10px;
        }

        .add-form input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            flex-grow: 1;
        }

        .add-form button {
            width: auto;
            padding: 8px 12px;
            background: #28a745;
        }
    </style>
</head>

<body>
    <div class="top-nav">
        <div class="top-nav-header">
            <h3>⚙️ Advanced Settings</h3>
        </div>
        <div class="nav-links">
            <a href="admin.php">🔙 Admin Panel</a>
            <a href="index.php?logout=1" class="logout">Logout</a>
        </div>
    </div>

    <div class="main-content">
        <div class="container">
            <h1>⚙️ System Configuration / إعدادات النظام</h1>
            <p>Manage Locations, Departments, Shifts, and Roles.</p>

            <?php if ($msg)
                echo "<p class='alert alert-success'>$msg</p>"; ?>
            <?php if ($error)
                echo "<p class='alert alert-error'>$error</p>"; ?>

            <div class="grid-container">

                <!-- 1. LOCATIONS -->
                <div class="card">
                    <h3>📍 Locations / المواقع</h3>
                    <ul class="item-list">
                        <?php foreach ($locations as $l): ?>
                            <li>
                                <?= htmlspecialchars($l['name']) ?>
                                <form method="POST" onsubmit="return confirm('Delete?');">
                                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                    <button type="submit" name="del_location" class="delete-btn">🗑️</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST" class="add-form">
                        <input type="text" name="name" placeholder="New Location..." required>
                        <button type="submit" name="add_location">+</button>
                    </form>
                </div>

                <!-- 2. DEPARTMENTS -->
                <div class="card">
                    <h3>🏭 Departments / الأقسام</h3>
                    <ul class="item-list">
                        <?php foreach ($departments as $d): ?>
                            <li>
                                <?= htmlspecialchars($d['name']) ?>
                                <form method="POST" onsubmit="return confirm('Delete?');">
                                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    <button type="submit" name="del_dept" class="delete-btn">🗑️</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST" class="add-form">
                        <input type="text" name="name" placeholder="New Dept..." required>
                        <button type="submit" name="add_dept">+</button>
                    </form>
                </div>

                <!-- 3. SHIFTS -->
                <div class="card">
                    <h3>⏰ Shifts / الورديات</h3>
                    <ul class="item-list">
                        <?php foreach ($shifts as $s): ?>
                            <li>
                                <span><b><?= htmlspecialchars($s['code']) ?></b>: <?= htmlspecialchars($s['name']) ?></span>
                                <form method="POST" onsubmit="return confirm('Delete?');">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" name="del_shift" class="delete-btn">🗑️</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST" class="add-form" style="display:grid; gap:5px;">
                        <input type="text" name="code" placeholder="Code (e.g. A)" required style="width:100%">
                        <input type="text" name="name" placeholder="Name (e.g. Morning)..." required style="width:100%">
                        <button type="submit" name="add_shift">Add Shift</button>
                    </form>
                </div>

                <!-- 4. ROLES -->
                <div class="card">
                    <h3>👤 Roles / الأدوار</h3>
                    <p style="font-size:11px; color:#666;">Note: Admin/Manager logic is hardcoded.</p>
                    <ul class="item-list">
                        <?php foreach ($roles as $r): ?>
                            <li>
                                <span><?= htmlspecialchars($r['name']) ?>
                                    <small>(<?= htmlspecialchars($r['slug']) ?>)</small></span>
                                <?php if ($r['slug'] !== 'admin' && $r['slug'] !== 'manager'): ?>
                                    <form method="POST" onsubmit="return confirm('Delete?');">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button type="submit" name="del_role" class="delete-btn">🗑️</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST" class="add-form" style="display:grid; gap:5px;">
                        <input type="text" name="slug" placeholder="Slug (e.g. supervisor)" required style="width:100%">
                        <input type="text" name="name" placeholder="Name (e.g. Supervisor)..." required
                            style="width:100%">
                        <button type="submit" name="add_role">Add Role</button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</body>

</html>
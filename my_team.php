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
        $w_shift = $_POST['shift'];

        // Strict Validation: Latin Letters and Numbers ONLY (No spaces, no symbols)
        if (!preg_match('/^[a-zA-Z0-9]+$/', $w_cin)) {
            $error = "❌ Security Alert: CIN must contain ONLY Latin letters and numbers. No spaces or symbols allowed.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO workers (cin, name, shift, manager_cin) VALUES (?, ?, ?, ?)");
                $stmt->execute([$w_cin, $w_name, $w_shift, $user_cin]);
                $msg = "✅ Worker added successfully!";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = "⚠️ Error: This CIN already exists in the system.";
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
$stmt = $pdo->prepare("SELECT * FROM workers WHERE manager_cin = ? ORDER BY shift, name");
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
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .form-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-bottom: 20px;
        }

        .form-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
        }

        .form-group label {
            display: block;
            font-size: 14px;
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
        }

        th,
        td {
            padding: 10px;
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
            font-size: 12px;
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
    <div class="sidebar">
        <div class="profile">
            <h3>👥 HR Manager</h3>
            <p>
                <?php echo $_SESSION['user_name']; ?>
            </p>
        </div>
        <hr>
        <a href="index.php" class="logout-btn" style="background:#007bff; margin-bottom:10px;"> Back to Board</a>
        <a href="index.php?logout=1" class="logout-btn">Logout</a>
    </div>

    <div class="main-content">
        <div class="container">
            <h2>👷 My Team & Shift Management</h2>
            <p style="color:#666; font-size:14px;">Manage the workers under your supervision. Add new members and assign
                shifts.</p>

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
                <h4>+ Add New Worker</h4>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>CIN (Unique ID)</label>
                            <input type="text" name="cin" placeholder="Ex: AB12345" required
                                onkeyup="validateCIN(this)">
                            <small style="color:#888;">Latin letters & Numbers only.</small>
                        </div>
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="name" placeholder="Worker Name" required>
                        </div>
                        <div class="form-group">
                            <label>Shift</label>
                            <select name="shift">
                                <option value="A">Shift A (Matin)</option>
                                <option value="B">Shift B (Après-midi)</option>
                                <option value="C">Shift C (Nuit)</option>
                                <option value="Normal">Normal Day</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0.5;">
                            <label>&nbsp;</label>
                            <button type="submit" name="add_worker"
                                style="background:#28a745; cursor:pointer;">Add</button>
                        </div>
                    </div>
                </form>
            </div>

            <h3>📋 Current Team List</h3>
            <table>
                <thead>
                    <tr>
                        <th>CIN</th>
                        <th>Name</th>
                        <th>Shift</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($my_team) == 0): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:20px; color:#999;">No workers added yet.</td>
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
                            <td><strong>
                                    <?php echo htmlspecialchars($w['cin']); ?>
                                </strong></td>
                            <td>
                                <?php echo htmlspecialchars($w['name']); ?>
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
</body>

</html>
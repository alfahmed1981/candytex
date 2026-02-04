<?php
session_start();
require 'db.php';

// Security: User must be logged in
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];

// Fetch current user data to see what's missing
$stmt = $pdo->prepare("SELECT * FROM users WHERE cin = ?");
$stmt->execute([$user_cin]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// If everything is already filled, go to dashboard
if (!empty($user['department']) && !empty($user['location']) && !empty($user['birth_date'])) {
    header("Location: index.php");
    exit;
}

// Fetch Dynamic Options
try {
    $locs = $pdo->query("SELECT name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $depts = $pdo->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Fallback if tables don't exist yet
    $locs = [];
    $depts = [];
}

// Fallback Defaults (if DB is empty)
if (empty($locs))
    $locs = ['Candy 1', 'Candy 2', 'Flora 1'];
if (empty($depts))
    $depts = ['Sewing', 'Cutting', 'Finishing', 'Packing', 'Warehouse', 'Maintenance', 'Quality', 'HR', 'Logistics'];


// Start buffering for any potential header redirects or output
ob_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Profile / إكمال الملف الشخصي</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .container {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 450px;
            text-align: right;
            /* RTL focus */
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }

        select,
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 16px;
        }

        button {
            width: 100%;
            padding: 14px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }

        button:hover {
            background: #218838;
        }

        .alert {
            background: #fff3cd;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 14px;
        }
    </style>
</head>

<body>

    <div class="container">
        <h2>⚠️ Complete Profile<br><small>إكمال البيانات</small></h2>

        <div class="alert">
            Please complete your profile to continue.<br>
            المرجو إكمال بياناتك للمتابعة.
        </div>

        <form id="profileForm">
            <input type="hidden" name="action" value="update_profile">

            <!-- Department -->
            <div class="form-group">
                <label>Department / القسم *</label>
                <select name="department" required>
                    <option value="">-- Select / اختر --</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $user['department'] == $d ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Location -->
            <div class="form-group">
                <label>Location / الموقع *</label>
                <select name="location" required>
                    <option value="">-- Select / اختر --</option>
                    <?php foreach ($locs as $l): ?>
                        <option value="<?= htmlspecialchars($l) ?>" <?= $user['location'] == $l ? 'selected' : '' ?>>
                            <?= htmlspecialchars($l) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Birth Date -->
            <div class="form-group">
                <label>Birth Date / تاريخ الازدياد *</label>
                <input type="date" name="birth_date" required value="<?= $user['birth_date'] ?? '' ?>">
            </div>

            <button type="submit">Save & Continue / حفظ و متابعة</button>
        </form>
    </div>

    <script>
        document.getElementById('profileForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());

            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Saved / تم الحفظ',
                            text: 'Redirecting...',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = 'index.php';
                        });
                    } else {
                        Swal.fire('Error', response.message || 'Error updating profile', 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    Swal.fire('Error', 'Network Error', 'error');
                });
        });
    </script>

</body>

</html>
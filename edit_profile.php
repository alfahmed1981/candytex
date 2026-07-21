<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Security: User must be logged in
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE cin = ?");
$stmt->execute([$user_cin]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Fetch departments and locations from database for dynamic dropdowns
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$locations = $pdo->query("SELECT * FROM locations ORDER BY name")->fetchAll();

// Start buffering for any potential header redirects or output
ob_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile / تعديل الملف الشخصي</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            max-width: 500px;
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

        input[readonly] {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }

        button {
            width: 100%;
            padding: 14px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }

        button:hover {
            background: #5568d3;
        }

        .back-btn {
            background: #6c757d;
            margin-bottom: 10px;
        }

        .back-btn:hover {
            background: #5a6268;
        }

        .info-box {
            background: #e7f3ff;
            color: #004085;
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
        <h2>⚙️ Edit Profile<br><small>تعديل الملف الشخصي</small></h2>

        <div class="info-box">
            Update your personal information below.<br>
            تحديث بياناتك الشخصية أدناه.
        </div>

        <form id="profileForm">
            <input type="hidden" name="action" value="update_own_profile">

            <!-- CIN (Read-only) -->
            <div class="form-group">
                <label>CIN (Login ID) / رقم البطاقة</label>
                <input type="text" value="<?= htmlspecialchars($user['cin']) ?>" readonly>
                <small style="color:#999; font-size:12px;">This field cannot be changed / هذا الحقل لا يمكن
                    تغييره</small>
            </div>

            <!-- Name -->
            <div class="form-group">
                <label>Full Name / الاسم الكامل *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($user['name']) ?>"
                    style="text-transform:uppercase;">
            </div>

            <!-- Phone -->
            <div class="form-group">
                <label>Phone / الهاتف *</label>
                <input type="text" name="phone" required value="<?= htmlspecialchars($user['phone']) ?>">
            </div>

            <!-- Email -->
            <div class="form-group">
                <label>📧 Email / البريد الإلكتروني</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                    placeholder="user@example.com">
            </div>

            <!-- WhatsApp -->
            <div class="form-group">
                <label>📱 WhatsApp / هاتف واتساب</label>
                <input type="text" name="whatsapp" value="<?= htmlspecialchars($user['whatsapp'] ?? '') ?>"
                    placeholder="06XXXXXXXX">
            </div>

            <!-- Department -->
            <div class="form-group">
                <label>Department / القسم *</label>
                <select name="department" required>
                    <option value="">-- Select / اختر --</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['name']) ?>" <?= $user['department'] == $dept['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Location -->
            <div class="form-group">
                <label>Location / الموقع *</label>
                <select name="location" required>
                    <option value="">-- Select / اختر --</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= htmlspecialchars($loc['name']) ?>" <?= $user['location'] == $loc['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Birth Date -->
            <div class="form-group">
                <label>Birth Date / تاريخ الازدياد *</label>
                <input type="date" name="birth_date" required value="<?= $user['birth_date'] ?? '' ?>">
            </div>

            <hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">
            <div class="info-box" style="background:#fff3cd; color:#856404;">
                Leave password fields blank if you don't want to change it.<br>
                اترك حقول كلمة المرور فارغة إذا كنت لا ترغب في تغييرها.
            </div>

            <!-- New Password -->
            <div class="form-group">
                <label>New Password / كلمة المرور الجديدة</label>
                <input type="password" name="new_password" id="new_password" placeholder="Enter new password...">
            </div>

            <!-- Confirm Password -->
            <div class="form-group">
                <label>Confirm Password / تأكيد كلمة المرور</label>
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password...">
            </div>

            <button type="submit">💾 Save Changes / حفظ التغييرات</button>
            <button type="button" class="back-btn" onclick="window.location.href='index.php'">← Back to Dashboard /
                العودة</button>
        </form>
    </div>

    <script>
        document.getElementById('profileForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const newPass = document.getElementById('new_password').value;
            const confPass = document.getElementById('confirm_password').value;
            if (newPass !== '' && newPass !== confPass) {
                Swal.fire('Error', 'Passwords do not match / كلمات المرور غير متطابقة', 'error');
                return;
            }

            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());

            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...data, csrf_token: '<?php echo csrf_token(); ?>' })
            })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Saved / تم الحفظ',
                            text: 'Profile updated successfully! / تم تحديث الملف الشخصي بنجاح',
                            timer: 2000,
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
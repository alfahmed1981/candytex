<?php
session_start();
require 'db.php';

// Admin Only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$msg = '';
$preview_data = [];
$imported = 0;
$updated = 0;

// Process CSV Upload
if (isset($_POST['preview_csv']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    if (($handle = fopen($file, "r")) !== FALSE) {
        $row = 0;
        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
            if ($row === 0) {
                // Header row
                $headers = $data;
            } else {
                $preview_data[] = $data;
            }
            $row++;
            if ($row > 20)
                break; // Limit preview to 20 rows
        }
        fclose($handle);
        $_SESSION['csv_headers'] = $headers;
        $_SESSION['csv_file'] = $file;
    }
}

// Import CSV
if (isset($_POST['import_csv'])) {
    $file = $_POST['csv_path'];
    $col_name = intval($_POST['col_name']);
    $col_phone = intval($_POST['col_phone']);
    $col_cin = intval($_POST['col_cin']);
    $col_location = intval($_POST['col_location']);
    $col_department = intval($_POST['col_department']);
    $col_job_title = intval($_POST['col_job_title']);

    if (($handle = fopen($file, "r")) !== FALSE) {
        $row = 0;
        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
            if ($row === 0) {
                $row++;
                continue;
            } // Skip header

            $name = trim($data[$col_name] ?? '');
            $phone = preg_replace('/[^0-9+]/', '', $data[$col_phone] ?? '');
            $cin = strtoupper(trim(str_replace(' ', '', $data[$col_cin] ?? '')));
            $location = trim($data[$col_location] ?? '');
            $department = extractDepartment($data[$col_department] ?? '');
            $job_title = trim($data[$col_job_title] ?? '');

            // Clean location
            if (stripos($location, 'candy 1') !== false || stripos($location, 'candy1') !== false)
                $location = 'Candy 1';
            elseif (stripos($location, 'candy 2') !== false || stripos($location, 'candy2') !== false)
                $location = 'Candy 2';
            elseif (stripos($location, 'flora') !== false)
                $location = 'Flora 1';

            if (empty($cin) || empty($name)) {
                $row++;
                continue;
            }

            // Check if user exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE cin = ?");
            $stmt->execute([$cin]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update
                $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, location=?, department=?, job_title=? WHERE cin=?");
                $stmt->execute([$name, $phone, $location, $department, $job_title, $cin]);
                $updated++;
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO users (cin, name, phone, role, location, department, job_title) VALUES (?, ?, ?, 'manager', ?, ?, ?)");
                $stmt->execute([$cin, $name, $phone, $location, $department, $job_title]);
                $imported++;
            }
            $row++;
        }
        fclose($handle);
        $msg = "✅ تم الاستيراد: $imported جديد، $updated تحديث";
    }
}

function extractDepartment($text)
{
    $text = strtolower($text);
    if (strpos($text, 'sewing') !== false || strpos($text, 'الخياطة') !== false)
        return 'Sewing';
    if (strpos($text, 'cutting') !== false || strpos($text, 'القص') !== false)
        return 'Cutting';
    if (strpos($text, 'maintenance') !== false || strpos($text, 'الصيانة') !== false)
        return 'Maintenance';
    if (strpos($text, 'embroidery') !== false || strpos($text, 'التطريز') !== false)
        return 'Embroidery';
    if (strpos($text, 'warehouse mp') !== false || strpos($text, 'raw') !== false || strpos($text, 'المواد') !== false)
        return 'Warehouse MP';
    if (strpos($text, 'warehouse pf') !== false || strpos($text, 'finished') !== false || strpos($text, 'المنتجات') !== false)
        return 'Warehouse PF';
    if (strpos($text, 'printing') !== false || strpos($text, 'الطباعة') !== false)
        return 'Printing';
    if (strpos($text, 'admin') !== false || strpos($text, 'الإدارة') !== false)
        return 'Administration';
    return '';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استيراد CSV - SQD+C</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .import-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }

        .upload-box {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            margin-bottom: 20px;
        }

        .upload-box input[type="file"] {
            padding: 15px;
            border: 2px dashed #007bff;
            border-radius: 10px;
            width: 100%;
            margin: 15px 0;
            cursor: pointer;
        }

        .preview-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            font-size: 12px;
        }

        .preview-table th,
        .preview-table td {
            padding: 10px;
            border: 1px solid #eee;
            text-align: right;
        }

        .preview-table th {
            background: #2c3e50;
            color: white;
        }

        .preview-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .mapping-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-top: 20px;
        }

        .mapping-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .mapping-item label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }

        .mapping-item select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-info {
            background: #cce5ff;
            color: #004085;
        }

        .steps {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-bottom: 30px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #999;
        }

        .step.active {
            color: #007bff;
            font-weight: 600;
        }

        .step.done {
            color: #28a745;
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #eee;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .step.active .step-number {
            background: #007bff;
            color: white;
        }

        .step.done .step-number {
            background: #28a745;
            color: white;
        }
    </style>
</head>

<body>
    <!-- Mobile Top Navigation -->
    <div class="top-nav">
        <div class="top-nav-header">
            <h3>📥 استيراد CSV</h3>
        </div>
        <div class="nav-links">
            <a href="index.php">📊 لوحة</a>
            <a href="admin.php">👥 مستخدمين</a>
            <a href="admin_advanced.php">⚙️ متقدم</a>
            <a href="index.php?logout=1" class="logout">خروج</a>
        </div>
    </div>

    <!-- Desktop Sidebar -->
    <div class="sidebar">
        <h3>📥 استيراد CSV</h3>
        <p>استيراد البيانات</p>
        <hr>
        <a href="admin.php" class="logout-btn" style="background:#17a2b8;">👥 مستخدمين</a>
        <a href="admin_advanced.php" class="logout-btn" style="background:#6f42c1;">⚙️ متقدم</a>
        <a href="index.php" class="logout-btn" style="background:#007bff;">📊 لوحة</a>
    </div>

    <div class="main-content">
        <div class="import-container">
            <h1>📥 استيراد المستخدمين من ملف CSV</h1>

            <?php if ($msg): ?>
                <div class="alert alert-success"><?php echo $msg; ?></div>
            <?php endif; ?>

            <!-- Steps -->
            <div class="steps">
                <div class="step <?php echo empty($preview_data) ? 'active' : 'done'; ?>">
                    <span class="step-number">1</span>
                    <span>رفع الملف</span>
                </div>
                <div class="step <?php echo !empty($preview_data) ? 'active' : ''; ?>">
                    <span class="step-number">2</span>
                    <span>مطابقة الأعمدة</span>
                </div>
                <div class="step">
                    <span class="step-number">3</span>
                    <span>استيراد</span>
                </div>
            </div>

            <?php if (empty($preview_data)): ?>
                <!-- Step 1: Upload -->
                <div class="upload-box">
                    <h2>📁 اختر ملف CSV</h2>
                    <p style="color:#666;">قم برفع ملف CSV يحتوي على بيانات المستخدمين</p>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="file" name="csv_file" accept=".csv" required>
                        <br><br>
                        <button type="submit" name="preview_csv" class="btn btn-primary">📤 معاينة الملف</button>
                    </form>

                    <div class="alert alert-info" style="margin-top:20px; text-align:right;">
                        <strong>ℹ️ تنسيق الملف المتوقع:</strong><br>
                        يجب أن يحتوي على أعمدة: الاسم، الهاتف، CIN، الموقع، القسم
                    </div>
                </div>

            <?php else: ?>
                <!-- Step 2: Map Columns & Preview -->
                <div class="mapping-section">
                    <h2>🔗 مطابقة الأعمدة</h2>
                    <p style="color:#666; margin-bottom:20px;">حدد العمود المناسب لكل حقل:</p>

                    <form method="POST">
                        <input type="hidden" name="csv_path" value="<?php echo htmlspecialchars($_SESSION['csv_file']); ?>">

                        <div class="mapping-grid">
                            <div class="mapping-item">
                                <label>📛 الاسم *</label>
                                <select name="col_name" required>
                                    <?php foreach ($_SESSION['csv_headers'] as $i => $h): ?>
                                        <option value="<?php echo $i; ?>" <?php echo (stripos($h, 'name') !== false || stripos($h, 'اسم') !== false) ? 'selected' : ''; ?>>
                                            <?php echo ($i + 1) . ': ' . mb_substr($h, 0, 40); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mapping-item">
                                <label>📱 الهاتف *</label>
                                <select name="col_phone" required>
                                    <?php foreach ($_SESSION['csv_headers'] as $i => $h): ?>
                                        <option value="<?php echo $i; ?>" <?php echo (stripos($h, 'phone') !== false || stripos($h, 'هاتف') !== false) ? 'selected' : ''; ?>>
                                            <?php echo ($i + 1) . ': ' . mb_substr($h, 0, 40); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mapping-item">
                                <label>🪪 CIN *</label>
                                <select name="col_cin" required>
                                    <?php foreach ($_SESSION['csv_headers'] as $i => $h): ?>
                                        <option value="<?php echo $i; ?>" <?php echo (stripos($h, 'cin') !== false || stripos($h, 'cnie') !== false || stripos($h, 'بطاقة') !== false) ? 'selected' : ''; ?>>
                                            <?php echo ($i + 1) . ': ' . mb_substr($h, 0, 40); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mapping-item">
                                <label>🏭 الموقع</label>
                                <select name="col_location">
                                    <option value="-1">-- تجاهل --</option>
                                    <?php foreach ($_SESSION['csv_headers'] as $i => $h): ?>
                                        <option value="<?php echo $i; ?>" <?php echo (stripos($h, 'site') !== false || stripos($h, 'موقع') !== false) ? 'selected' : ''; ?>>
                                            <?php echo ($i + 1) . ': ' . mb_substr($h, 0, 40); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mapping-item">
                                <label>🗂️ القسم</label>
                                <select name="col_department">
                                    <option value="-1">-- تجاهل --</option>
                                    <?php foreach ($_SESSION['csv_headers'] as $i => $h): ?>
                                        <option value="<?php echo $i; ?>" <?php echo (stripos($h, 'department') !== false || stripos($h, 'قسم') !== false) ? 'selected' : ''; ?>>
                                            <?php echo ($i + 1) . ': ' . mb_substr($h, 0, 40); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mapping-item">
                                <label>💼 المسمى الوظيفي</label>
                                <select name="col_job_title">
                                    <option value="-1">-- تجاهل --</option>
                                    <?php foreach ($_SESSION['csv_headers'] as $i => $h): ?>
                                        <option value="<?php echo $i; ?>" <?php echo (stripos($h, 'section 1') !== false || $i === 1) ? 'selected' : ''; ?>>
                                            <?php echo ($i + 1) . ': ' . mb_substr($h, 0, 40); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top:25px; display:flex; gap:15px;">
                            <button type="submit" name="import_csv" class="btn btn-success">✅ استيراد البيانات</button>
                            <a href="import_users.php" class="btn btn-secondary">🔄 إعادة الرفع</a>
                        </div>
                    </form>
                </div>

                <!-- Preview Table -->
                <div style="margin-top:25px; overflow-x:auto;">
                    <h3>👁️ معاينة أول 20 سطر:</h3>
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <?php foreach ($_SESSION['csv_headers'] as $i => $h): ?>
                                    <th><?php echo ($i + 1) . ': ' . mb_substr($h, 0, 25); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data as $row): ?>
                                <tr>
                                    <?php foreach ($row as $cell): ?>
                                        <td><?php echo htmlspecialchars(mb_substr($cell, 0, 50)); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
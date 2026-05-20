<?php
session_start();
require 'db.php';
require 'includes/auth.php';

require_login();
$user_role = $_SESSION['role'];
$user_cin = $_SESSION['user_cin'];
$user_name = $_SESSION['user_name'] ?? '';

$is_admin = is_admin();
$is_hr = is_hr();
$is_leader = is_leader();

if (!$is_admin && !$is_hr && !$is_leader) {
    header("Location: index.php");
    exit;
}

// --- Self-healing: create NCR table ---
$pdo->exec("CREATE TABLE IF NOT EXISTS `ncr_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ncr_number` VARCHAR(30) NOT NULL UNIQUE,
    `category` VARCHAR(50) DEFAULT 'Product',
    `severity` VARCHAR(20) DEFAULT 'Minor',
    `source` VARCHAR(50) DEFAULT 'Production',
    `location` VARCHAR(100) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `description_en` TEXT DEFAULT NULL,
    `description_ar` TEXT DEFAULT NULL,
    `immediate_action` TEXT DEFAULT NULL,
    `disposition` VARCHAR(50) DEFAULT 'Pending',
    `reported_by` VARCHAR(20) DEFAULT NULL,
    `assigned_to` VARCHAR(100) DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'Open',
    `due_date` DATE DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// --- Self-healing: create CAR table ---
$pdo->exec("CREATE TABLE IF NOT EXISTS `car_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `car_number` VARCHAR(30) NOT NULL UNIQUE,
    `ncr_id` INT NOT NULL,
    `root_cause` TEXT DEFAULT NULL,
    `corrective_action` TEXT DEFAULT NULL,
    `preventive_action` TEXT DEFAULT NULL,
    `responsible` VARCHAR(100) DEFAULT NULL,
    `deadline` DATE DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'Open',
    `effectiveness_ok` TINYINT(1) DEFAULT NULL,
    `verified_by` VARCHAR(20) DEFAULT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `created_by` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// --- Ensure disposition column size is enough for custom user input ---
try {
    $pdo->exec("ALTER TABLE `ncr_reports` MODIFY COLUMN `disposition` VARCHAR(255) DEFAULT 'Pending'");
} catch (PDOException $e) {
    // Silently continue if ALTER fails
}

// --- Helper: Generate next NCR/CAR number ---
function next_number($pdo, $prefix, $table, $column)
{
    $year = date('Y');
    $pattern = "$prefix-$year-%";
    $stmt = $pdo->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $row = $stmt->fetch();
    if ($row) {
        $parts = explode('-', $row[$column]);
        $seq = intval(end($parts)) + 1;
    } else {
        $seq = 1;
    }
    return "$prefix-$year-" . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

// --- Load lookup data ---
$locations = $pdo->query("SELECT name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$departments = $pdo->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$msg = '';
$error = '';

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // CREATE NCR
    // --- EN ↔ AR Description Lookup Map ---
    $desc_map = [
        'Fabric Defect (Holes/Knots)' => 'عيوب في القماش (ثقوب / عقد)',
        'Color Shading / Mismatch' => 'اختلاف درجات اللون (Nuance)',
        'Wrong Fabric / GSM Issue' => 'قماش خاطئ / مشكلة في الوزن',
        'Damaged / Wrong Accessories' => 'إكسسوارات تالفة أو خاطئة',
        'Dirty / Stained Material' => 'مواد أولية متسخة / مبقعة',
        'Wrong Cutting Dimension' => 'أبعاد القص غير صحيحة',
        'Pattern Misalignment' => 'عدم تطابق الباترون',
        'Numbering / Bundling Error' => 'خطأ في الترقيم أو التحزيم',
        'Fraying Edges' => 'تنسيل حواف القماش',
        'Missed Notches' => 'غياب علامات التقابل (Crans)',
        'Broken / Skipped Stitches' => 'غرز مقطوعة / قفز الغرز',
        'Open Seam / Seam Failure' => 'خياطة مفتوحة / فشل الدرزة',
        'Asymmetry / Uneven Parts' => 'عدم تماثل الأجزاء',
        'Puckering / Tension Issue' => 'تكرمش القماش / مشكلة شد الخيط',
        'Needle Holes / Marks' => 'ثقوب الإبرة / آثار الأسنان',
        'Oil Spots (Machine Oil)' => 'بقع زيت الماكينة',
        'Wrong Label / Tag Placement' => 'تركيب الملصق في مكان خاطئ',
        'Out of Tolerance (+/-)' => 'خارج نطاق القياس المسموح',
        'Size Mismatch' => 'خطأ في المقاس',
        'Ironing Burn / Shine' => 'حرق المكواة / لمعة غير مرغوبة',
        'Loose Threads (Uncut)' => 'خيوط سائبة (عدم التشطيب)',
        'Dirty / Stained Product' => 'منتج متسخ',
        'Folding / Packing Error' => 'خطأ في الطي أو التغليف',
        'Missing Documentation' => 'غياب الوثائق / أمر التصنيع',
        'Safety Violation / Hazard' => 'مخالفة إجراءات السلامة',
        'Machine Breakdown' => 'عطل في الماكينة',
        'Process Non-Conformity' => 'عدم الالتزام بطريقة العمل',
    ];

    if (isset($_POST['create_ncr'])) {
        $ncr_num = next_number($pdo, 'NCR', 'ncr_reports', 'ncr_number');

        // Smart description: dropdown or custom
        $desc_en_raw = trim($_POST['description_en'] ?? '');
        if ($desc_en_raw === '__OTHER__') {
            $desc_en = trim($_POST['description_custom_en'] ?? '');
            $desc_ar = trim($_POST['description_custom_ar'] ?? '');
        } else {
            $desc_en = $desc_en_raw;
            $desc_ar = $desc_map[$desc_en_raw] ?? trim($_POST['description_ar'] ?? '');
        }

        $disposition_raw = $_POST['disposition'] ?? 'Pending';
        $disposition = ($disposition_raw === '__OTHER__') ? trim($_POST['disposition_custom'] ?? 'Other') : $disposition_raw;
        if (mb_strlen($disposition) > 255) {
            $disposition = mb_substr($disposition, 0, 250) . '...';
        }

        $stmt = $pdo->prepare("INSERT INTO ncr_reports 
            (ncr_number, category, severity, source, location, department, description_en, description_ar, 
             immediate_action, disposition, reported_by, assigned_to, due_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open')");
        $stmt->execute([
            $ncr_num,
            $_POST['category'] ?? 'Product',
            $_POST['severity'] ?? 'Minor',
            $_POST['source'] ?? 'Production',
            $_POST['location'] ?? '',
            $_POST['department'] ?? '',
            $desc_en,
            $desc_ar,
            trim($_POST['immediate_action'] ?? ''),
            $disposition,
            $user_cin,
            trim($_POST['assigned_to'] ?? ''),
            $_POST['due_date'] ?: null
        ]);
        $msg = "✅ تم إنشاء تقرير عدم المطابقة: $ncr_num";
        audit_log($pdo, 'ncr_create', "Created NCR: $ncr_num");
    }

    // UPDATE NCR STATUS (Admin only)
    if (isset($_POST['update_ncr_status']) && $is_admin) {
        $id = intval($_POST['ncr_id']);
        $new_status = $_POST['new_status'];
        $closed = ($new_status === 'Closed') ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare("UPDATE ncr_reports SET status = ?, closed_at = COALESCE(?, closed_at) WHERE id = ?");
        $stmt->execute([$new_status, $closed, $id]);
        $msg = "✅ تم تحديث حالة NCR";
        audit_log($pdo, 'ncr_update', "NCR #$id status → $new_status");
    }

    // DELETE NCR (Admin only)
    if (isset($_POST['delete_ncr']) && $is_admin) {
        $id = intval($_POST['ncr_id']);
        // Get NCR number for logging
        $stmt = $pdo->prepare("SELECT ncr_number FROM ncr_reports WHERE id = ?");
        $stmt->execute([$id]);
        $ncr = $stmt->fetch();
        // Delete associated CARs first
        $pdo->prepare("DELETE FROM car_reports WHERE ncr_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ncr_reports WHERE id = ?")->execute([$id]);
        $msg = "✅ تم حذف NCR: " . ($ncr['ncr_number'] ?? $id);
        audit_log($pdo, 'ncr_delete', "Deleted NCR: " . ($ncr['ncr_number'] ?? $id));
    }

    // CREATE CAR
    if (isset($_POST['create_car'])) {
        $ncr_id = intval($_POST['ncr_id']);
        $car_num = next_number($pdo, 'CAR', 'car_reports', 'car_number');

        // Smart fields: dropdown or custom
        $root_cause_raw = trim($_POST['root_cause'] ?? '');
        $root_cause = ($root_cause_raw === '__OTHER__') ? trim($_POST['root_cause_custom'] ?? '') : $root_cause_raw;

        $corrective_raw = trim($_POST['corrective_action'] ?? '');
        if ($corrective_raw === '__OTHER__') {
            $corrective = trim($_POST['corrective_action_custom'] ?? '');
        } else {
            $corrective = !empty($corrective_raw) ? $corrective_raw : trim($_POST['corrective_action_custom'] ?? '');
        }

        $preventive_raw = trim($_POST['preventive_action'] ?? '');
        if ($preventive_raw === '__OTHER__') {
            $preventive = trim($_POST['preventive_action_custom'] ?? '');
        } else {
            $preventive = !empty($preventive_raw) ? $preventive_raw : trim($_POST['preventive_action_custom'] ?? '');
        }

        $stmt = $pdo->prepare("INSERT INTO car_reports 
            (car_number, ncr_id, root_cause, corrective_action, preventive_action, 
             responsible, deadline, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Open', ?)");
        $stmt->execute([
            $car_num,
            $ncr_id,
            $root_cause,
            $corrective,
            $preventive,
            trim($_POST['car_responsible'] ?? ''),
            $_POST['car_deadline'] ?: null,
            $user_cin
        ]);
        // Update NCR status to 'CAR Issued'
        $pdo->prepare("UPDATE ncr_reports SET status = 'CAR Issued' WHERE id = ? AND status IN ('Open','Under Review')")
            ->execute([$ncr_id]);
        $msg = "✅ تم إنشاء إجراء تصحيحي: $car_num";
        audit_log($pdo, 'car_create', "Created CAR: $car_num for NCR #$ncr_id");
    }

    // UPDATE CAR STATUS (Admin only)
    if (isset($_POST['update_car_status']) && $is_admin) {
        $car_id = intval($_POST['car_id']);
        $car_status = $_POST['car_status'];
        $eff = isset($_POST['effectiveness_ok']) ? intval($_POST['effectiveness_ok']) : null;
        $verified_by = ($car_status === 'Closed') ? $user_cin : null;
        $verified_at = ($car_status === 'Closed') ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare("UPDATE car_reports SET status = ?, effectiveness_ok = ?, verified_by = COALESCE(?, verified_by), verified_at = COALESCE(?, verified_at) WHERE id = ?");
        $stmt->execute([$car_status, $eff, $verified_by, $verified_at, $car_id]);
        $msg = "✅ تم تحديث حالة CAR";
        audit_log($pdo, 'car_update', "CAR #$car_id status → $car_status");
    }
}

// --- Fetch NCRs: role-based access ---
if ($is_admin || $is_hr) {
    $filter_reporter = trim($_GET['reporter'] ?? '');
    $sql = "SELECT n.*, u.name as reporter_name 
            FROM ncr_reports n LEFT JOIN users u ON n.reported_by = u.cin WHERE 1=1";
    $params = [];

    if ($filter_reporter) {
        $sql .= " AND n.reported_by = ?";
        $params[] = $filter_reporter;
    }

    if ($is_hr) {
        $loc = get_user_factory($pdo, $user_cin);
        $sql .= " AND n.location = ?";
        $params[] = $loc;
    }

    $sql .= " ORDER BY n.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ncrs = $stmt->fetchAll();

    // Fetch all reporters
    $reporters_sql = "SELECT DISTINCT n.reported_by, u.name 
        FROM ncr_reports n LEFT JOIN users u ON n.reported_by = u.cin 
        WHERE n.reported_by IS NOT NULL";
    if ($is_hr) {
        $reporters_sql .= " AND n.location = " . $pdo->quote($loc ?? '');
    }
    $reporters_sql .= " ORDER BY u.name";
    $reporters = $pdo->query($reporters_sql)->fetchAll();
} else {
    // Non-admin: only their own NCRs
    $stmt = $pdo->prepare("SELECT n.*, u.name as reporter_name 
        FROM ncr_reports n LEFT JOIN users u ON n.reported_by = u.cin 
        WHERE n.reported_by = ? ORDER BY n.created_at DESC");
    $stmt->execute([$user_cin]);
    $ncrs = $stmt->fetchAll();
    $reporters = [];
}

// --- Fetch all CARs ---
$cars_all = $pdo->query("SELECT * FROM car_reports ORDER BY created_at DESC")->fetchAll();
$cars_by_ncr = [];
foreach ($cars_all as $car) {
    $cars_by_ncr[$car['ncr_id']][] = $car;
}

// --- Statistics ---
$stats = [
    'total' => count($ncrs),
    'Open' => 0,
    'Under Review' => 0,
    'CAR Issued' => 0,
    'Closed' => 0,
    'Critical' => 0,
    'Major' => 0,
    'Minor' => 0
];
foreach ($ncrs as $ncr) {
    $s = $ncr['status'] ?? 'Open';
    if (isset($stats[$s]))
        $stats[$s]++;
    $sev = $ncr['severity'] ?? 'Minor';
    if (isset($stats[$sev]))
        $stats[$sev]++;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISO 9001 — NCR / CAR | إدارة عدم المطابقة</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #f5f6fa;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 1500px;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            background: linear-gradient(135deg, #0b3c5d, #1a6b8a);
            color: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 1.8em;
        }

        .page-header p {
            margin: 5px 0 0;
            opacity: 0.9;
        }

        .page-header .nav-links a {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            margin-left: 10px;
            transition: background 0.3s;
            display: inline-block;
            margin-bottom: 5px;
        }

        .page-header .nav-links a:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 18px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card .number {
            font-size: 2em;
            font-weight: bold;
        }

        .stat-card .label {
            color: #666;
            font-size: 0.85em;
            margin-top: 5px;
        }

        .stat-card.open {
            border-top: 4px solid #dc3545;
        }

        .stat-card.open .number {
            color: #dc3545;
        }

        .stat-card.review {
            border-top: 4px solid #ffc107;
        }

        .stat-card.review .number {
            color: #e6a800;
        }

        .stat-card.car-issued {
            border-top: 4px solid #17a2b8;
        }

        .stat-card.car-issued .number {
            color: #17a2b8;
        }

        .stat-card.closed {
            border-top: 4px solid #28a745;
        }

        .stat-card.closed .number {
            color: #28a745;
        }

        .stat-card.total {
            border-top: 4px solid #0b3c5d;
        }

        .stat-card.total .number {
            color: #0b3c5d;
        }

        /* Severity bars */
        .severity-grid {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .sev-card {
            flex: 1;
            min-width: 120px;
            padding: 15px;
            border-radius: 10px;
            color: white;
            text-align: center;
            font-weight: bold;
        }

        .sev-card.critical {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }

        .sev-card.major {
            background: linear-gradient(135deg, #fd7e14, #e06b0a);
        }

        .sev-card.minor {
            background: linear-gradient(135deg, #ffc107, #d4a106);
            color: #333;
        }

        .sev-card .count {
            font-size: 1.8em;
            display: block;
        }

        .sev-card .sev-label {
            font-size: 0.85em;
            opacity: 0.9;
        }

        /* Filters */
        .filters-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .filters-bar select,
        .filters-bar input[type="date"] {
            padding: 9px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95em;
            color: #333;
            background: white;
        }

        .filters-bar input[type="date"] {
            min-width: 135px;
        }

        .date-filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .date-filter-group label {
            font-size: 0.9em;
            color: #555;
            white-space: nowrap;
        }

        .btn-reset {
            padding: 8px 16px !important;
            background: #6c757d !important;
            color: white !important;
            border: none !important;
            border-radius: 8px !important;
            cursor: pointer;
            font-size: 0.9em !important;
            width: auto !important;
        }

        .btn-reset:hover {
            background: #5a6268 !important;
        }

        /* Add NCR button */
        .btn-add {
            padding: 12px 24px !important;
            background: linear-gradient(135deg, #0b3c5d, #1a6b8a) !important;
            color: white !important;
            border: none !important;
            border-radius: 10px !important;
            font-size: 1em !important;
            font-weight: 600 !important;
            cursor: pointer;
            width: auto !important;
            transition: transform 0.1s;
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(11, 60, 93, 0.3);
        }

        /* Table */
        .ncr-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }

        .ncr-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .ncr-table th {
            background: #f8f9fa;
            padding: 14px 10px;
            text-align: right;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
            font-size: 0.9em;
        }

        .ncr-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
            font-size: 0.9em;
        }

        .ncr-table tr:hover {
            background: #f8f9fa;
        }

        /* Status badges */
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 500;
            display: inline-block;
        }

        .badge.open {
            background: #fce4ec;
            color: #c62828;
        }

        .badge.review {
            background: #fff3e0;
            color: #ef6c00;
        }

        .badge.car-issued {
            background: #e0f7fa;
            color: #006064;
        }

        .badge.closed {
            background: #e8f5e9;
            color: #2e7d32;
        }

        /* Severity badges */
        .sev-badge {
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .sev-badge.critical {
            background: #fce4ec;
            color: #c62828;
        }

        .sev-badge.major {
            background: #fff3e0;
            color: #e65100;
        }

        .sev-badge.minor {
            background: #fff8e1;
            color: #f57f17;
        }

        /* Action buttons */
        .action-form {
            display: flex;
            gap: 5px;
            align-items: center;
            flex-wrap: wrap;
        }

        .action-form select {
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.85em;
        }

        .action-form button {
            padding: 5px 12px !important;
            border: none !important;
            border-radius: 6px !important;
            cursor: pointer;
            font-size: 0.85em !important;
            width: auto !important;
        }

        .btn-save {
            background: #0b3c5d !important;
            color: white !important;
        }

        .btn-save:hover {
            background: #094a6e !important;
        }

        .btn-car {
            background: #17a2b8 !important;
            color: white !important;
        }

        .btn-car:hover {
            background: #138496 !important;
        }

        .btn-del {
            background: #dc3545 !important;
            color: white !important;
        }

        .btn-del:hover {
            background: #c82333 !important;
        }

        /* CAR section */
        .car-row {
            background: #f0f9ff !important;
        }

        .car-row td {
            padding: 10px 15px !important;
        }

        .car-box {
            background: white;
            border: 1px solid #b8daff;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
        }

        .car-box h4 {
            margin: 0 0 8px;
            color: #0b3c5d;
            font-size: 0.95em;
        }

        .car-box p {
            margin: 4px 0;
            font-size: 0.85em;
            color: #555;
        }

        .car-box .car-label {
            font-weight: 600;
            color: #333;
        }

        /* Alert */
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: white;
            border-radius: 16px;
            padding: 28px;
            width: 95%;
            max-width: 650px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .modal h2 {
            margin: 0 0 20px;
            color: #0b3c5d;
            font-size: 1.3em;
        }

        .modal .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .modal .form-row.single {
            grid-template-columns: 1fr;
        }

        .modal .form-group {
            display: flex;
            flex-direction: column;
        }

        .modal .form-group label {
            font-weight: 600;
            color: #444;
            margin-bottom: 5px;
            font-size: 0.85em;
        }

        .modal .form-group label small {
            font-weight: normal;
            color: #888;
        }

        .modal .form-group input,
        .modal .form-group select,
        .modal .form-group textarea {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            color: #333;
            background: white;
            width: 100%;
            box-sizing: border-box;
        }

        .modal .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }

        .modal .form-group input:focus,
        .modal .form-group select:focus,
        .modal .form-group textarea:focus {
            outline: none;
            border-color: #0b3c5d;
            box-shadow: 0 0 0 3px rgba(11, 60, 93, 0.15);
        }

        .modal-btns {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .modal-btns button {
            padding: 10px 24px !important;
            border: none !important;
            border-radius: 8px !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            cursor: pointer;
            width: auto !important;
        }

        .btn-submit {
            background: #0b3c5d !important;
            color: white !important;
        }

        .btn-submit:hover {
            background: #094a6e !important;
        }

        .btn-cancel {
            background: #e9ecef !important;
            color: #333 !important;
        }

        .btn-cancel:hover {
            background: #ddd !important;
        }

        /* Toggle CAR */
        .toggle-car {
            cursor: pointer;
            color: #17a2b8;
            font-size: 0.85em;
            text-decoration: underline;
        }

        /* Count badge */
        .count-badge {
            background: #0b3c5d;
            color: white;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 0.75em;
            margin-right: 4px;
        }

        /* Guide Section */
        .guide-section {
            background: white;
            border-radius: 14px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid #e3e8ef;
        }

        .guide-toggle {
            width: 100%;
            padding: 16px 24px !important;
            background: linear-gradient(135deg, #eef2f7, #f8f9fb) !important;
            border: none !important;
            border-radius: 0 !important;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.1em !important;
            font-weight: 700 !important;
            color: #0b3c5d !important;
        }

        .guide-toggle:hover {
            background: #e8edf3 !important;
        }

        .guide-toggle .arrow {
            transition: transform 0.3s;
            font-size: 1.2em;
        }

        .guide-toggle .arrow.open {
            transform: rotate(180deg);
        }

        .guide-content {
            display: none;
            padding: 0 28px 28px;
            animation: fadeIn 0.3s;
        }

        .guide-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .guide-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .guide-card {
            background: #f8f9fb;
            border-radius: 12px;
            padding: 20px;
            border-right: 4px solid #0b3c5d;
        }

        .guide-card h3 {
            color: #0b3c5d;
            margin: 0 0 12px;
            font-size: 1.05em;
        }

        .guide-card h3 small {
            font-weight: normal;
            color: #888;
            font-size: 0.8em;
        }

        .guide-card p,
        .guide-card li {
            color: #444;
            font-size: 0.9em;
            line-height: 1.7;
            margin: 4px 0;
        }

        .guide-card ul {
            padding-right: 18px;
            margin: 8px 0;
        }

        .guide-card .term {
            display: inline-block;
            background: #e8edf5;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 600;
            color: #0b3c5d;
            font-size: 0.85em;
        }

        .guide-workflow {
            display: flex;
            align-items: center;
            gap: 0;
            flex-wrap: wrap;
            margin: 12px 0;
        }

        .guide-workflow .step {
            background: white;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.85em;
            font-weight: 600;
            text-align: center;
            border: 1px solid #ddd;
        }

        .guide-workflow .arrow-right {
            font-size: 1.2em;
            color: #0b3c5d;
            padding: 0 4px;
        }

        .guide-card.highlight {
            background: #fff8e1;
            border-right-color: #ffc107;
        }

        .btn-guide-nav {
            padding: 10px 20px !important;
            background: rgba(255, 255, 255, 0.25) !important;
            border: 1px solid rgba(255, 255, 255, 0.4) !important;
            color: white !important;
            border-radius: 8px !important;
            cursor: pointer;
            font-size: 0.95em !important;
            width: auto !important;
            transition: background 0.3s;
        }

        .btn-guide-nav:hover {
            background: rgba(255, 255, 255, 0.35) !important;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .ncr-table {
                overflow-x: auto;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .modal .form-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                text-align: center;
            }

            .guide-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ====== ISO A4 PRINT STYLES ====== */
        #print-area {
            display: none;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }

            body {
                background: #fff;
                margin: 0;
                padding: 0;
            }

            /* Hide everything in the body except the print area */
            body > *:not(#print-area):not(script) {
                display: none !important;
            }

            #print-area {
                display: block !important;
                position: relative;
                width: 100%;
                margin: 0;
                padding: 0;
                font-size: 9pt;
                color: #000;
                direction: ltr;
                text-align: left;
            }

            .print-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                border-bottom: 2px solid #0b3c5d;
                padding-bottom: 6px;
                margin-bottom: 8px;
            }

            .print-header .company {
                font-size: 14pt;
                font-weight: 700;
                color: #0b3c5d;
            }

            .print-header .company small {
                font-size: 7.5pt;
                font-weight: 400;
                color: #555;
                display: block;
            }

            .print-header .doc-meta {
                text-align: right;
                font-size: 7.5pt;
                color: #333;
                line-height: 1.4;
            }

            .print-header .doc-meta .doc-num {
                font-size: 12pt;
                font-weight: 700;
                color: #c62828;
            }

            .print-title {
                text-align: center;
                font-size: 13pt;
                font-weight: 700;
                color: #0b3c5d;
                margin: 5px 0 8px;
                padding: 5px;
                border: 2px solid #0b3c5d;
                background: #f5f9ff;
            }

            .print-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 6px;
                page-break-inside: avoid;
            }

            .print-table th,
            .print-table td {
                border: 1px solid #333;
                padding: 3px 8px;
                font-size: 8.5pt;
            }

            .print-table th {
                background: #e8edf2;
                font-weight: 600;
                width: 35%;
                text-align: left;
            }

            .print-table td {
                text-align: left;
            }

            .print-table .section-header {
                background: #0b3c5d;
                color: #fff;
                text-align: center;
                font-weight: 700;
                font-size: 9pt;
            }

            .print-signatures {
                display: flex;
                justify-content: space-between;
                margin-top: 12px;
                page-break-inside: avoid;
            }

            .print-sig-box {
                border: 1px solid #555;
                padding: 8px;
                width: 45%;
                min-height: 55px;
            }

            .print-sig-box h4 {
                margin: 0 0 3px;
                font-size: 8.5pt;
                color: #0b3c5d;
            }

            .print-sig-box .sig-line {
                border-bottom: 1px dotted #999;
                margin-top: 20px;
                height: 1px;
            }

            .print-footer {
                text-align: center;
                font-size: 7pt;
                color: #888;
                border-top: 1px solid #ccc;
                padding-top: 4px;
                margin-top: 8px;
            }

            .btn-print {
                display: none !important;
            }
        }

        .btn-print {
            background: none;
            border: 1px solid #0b3c5d;
            color: #0b3c5d;
            cursor: pointer;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 0.85em;
            transition: all 0.2s;
        }

        .btn-print:hover {
            background: #0b3c5d;
            color: #fff;
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1>🏭 إدارة عدم المطابقة والإجراءات التصحيحية</h1>
                <p>ISO 9001 — NCR / CAR Management</p>
            </div>
            <div class="nav-links">
                <button type="button" class="btn-guide-nav" onclick="toggleGuide()">📖 دليل الاستخدام</button>
            </div>
        </div>

        <!-- ============ USER GUIDE ============ -->
        <div class="guide-section" id="guide-section">
            <button type="button" class="guide-toggle" onclick="toggleGuide()">
                <span>📖 دليل استخدام لوحة إدارة عدم المطابقة — NCR / CAR Guide</span>
                <span class="arrow" id="guide-arrow">▼</span>
            </button>
            <div class="guide-content" id="guide-content">

                <div class="guide-grid">
                    <!-- What is NCR? -->
                    <div class="guide-card">
                        <h3>🚫 ما هو تقرير عدم المطابقة؟ <small>/ What is NCR?</small></h3>
                        <p><span class="term">NCR</span> = <strong>Non-Conformity Report</strong> — تقرير عدم المطابقة
                        </p>
                        <p>هو تقرير يُسجَّل عند اكتشاف أي <strong>خلل أو خطأ أو مشكلة</strong> في المنتج أو العملية أو
                            المواد الخام.</p>
                        <p>ببساطة: <em>"وجدنا مشكلة ← نسجلها رسمياً"</em></p>
                        <p style="margin-top:10px; font-weight:600;">📌 أمثلة:</p>
                        <ul>
                            <li>قماش وصل من المورد به عيب</li>
                            <li>قطعة خياطة غير مطابقة للمقاسات</li>
                            <li>آلة تنتج قطعاً معيبة</li>
                            <li>ملاحظة من الزبون على جودة المنتج</li>
                        </ul>
                    </div>

                    <!-- What is CAR? -->
                    <div class="guide-card">
                        <h3>🔁 ما هو الإجراء التصحيحي؟ <small>/ What is CAR?</small></h3>
                        <p><span class="term">CAR</span> = <strong>Corrective Action Report</strong> — تقرير الإجراء
                            التصحيحي</p>
                        <p>هو الخطوات التي نتخذها <strong>لمعالجة المشكلة ومنع تكرارها</strong>.</p>
                        <p>ببساطة: <em>"عرفنا المشكلة ← نبحث عن سببها ← نحلها ← نتأكد إنها ما ترجع"</em></p>
                        <p style="margin-top:10px; font-weight:600;">📌 يتضمن CAR:</p>
                        <ul>
                            <li><strong>السبب الجذري</strong> — لماذا حدثت المشكلة أصلاً؟</li>
                            <li><strong>الإجراء التصحيحي</strong> — ماذا سنفعل لحلها؟</li>
                            <li><strong>الإجراء الوقائي</strong> — كيف نمنع تكرارها؟</li>
                        </ul>
                    </div>

                    <!-- Workflow -->
                    <div class="guide-card highlight">
                        <h3>📋 سير العمل <small>/ Workflow</small></h3>
                        <p>كل تقرير عدم مطابقة يمر بالمراحل التالية:</p>
                        <div class="guide-workflow">
                            <div class="step" style="background:#fce4ec; color:#c62828;">🔴
                                مفتوحة<br><small>Open</small></div>
                            <span class="arrow-right">←</span>
                            <div class="step" style="background:#fff3e0; color:#ef6c00;">🟡 قيد المراجعة<br><small>Under
                                    Review</small></div>
                            <span class="arrow-right">←</span>
                            <div class="step" style="background:#e0f7fa; color:#006064;">🔵 CAR صادر<br><small>CAR
                                    Issued</small></div>
                            <span class="arrow-right">←</span>
                            <div class="step" style="background:#e8f5e9; color:#2e7d32;">✅
                                مغلقة<br><small>Closed</small></div>
                        </div>
                        <ul style="margin-top:12px;">
                            <li><strong>مفتوحة:</strong> تم اكتشاف المشكلة وتسجيلها</li>
                            <li><strong>قيد المراجعة:</strong> يتم فحص المشكلة من طرف المسؤول</li>
                            <li><strong>CAR صادر:</strong> تم إنشاء إجراء تصحيحي لحلها</li>
                            <li><strong>مغلقة:</strong> تم حل المشكلة والتحقق من فعالية الحل</li>
                        </ul>
                    </div>

                    <!-- Severity Levels -->
                    <div class="guide-card">
                        <h3>⚡ مستويات الشدة <small>/ Severity Levels</small></h3>
                        <p>كل مشكلة يتم تصنيفها حسب خطورتها:</p>
                        <ul>
                            <li>🚨 <span class="term" style="background:#fce4ec; color:#c62828;">Critical —
                                    حرجة</span><br>
                                مشكلة خطيرة جداً تؤثر على سلامة المنتج أو الزبون. <em>مثال: منتج قد يسبب ضرراً</em></li>
                            <li style="margin-top:8px;">⚠️ <span class="term"
                                    style="background:#fff3e0; color:#e65100;">Major — رئيسية</span><br>
                                مشكلة كبيرة تؤثر على جودة المنتج. <em>مثال: عيب واضح في الخياطة</em></li>
                            <li style="margin-top:8px;">📌 <span class="term"
                                    style="background:#fff8e1; color:#f57f17;">Minor — ثانوية</span><br>
                                مشكلة صغيرة لا تؤثر على الوظيفة. <em>مثال: اختلاف بسيط في اللون</em></li>
                        </ul>
                    </div>

                    <!-- Categories -->
                    <div class="guide-card">
                        <h3>📂 فئات عدم المطابقة <small>/ Categories</small></h3>
                        <ul>
                            <li><span class="term">Product — منتج</span>: مشكلة في المنتج النهائي</li>
                            <li><span class="term">Process — عملية</span>: مشكلة في طريقة العمل أو الإنتاج</li>
                            <li><span class="term">Material — مادة</span>: مشكلة في المواد الخام أو اللوازم</li>
                            <li><span class="term">Supplier — مورد</span>: مشكلة مصدرها المورد</li>
                            <li><span class="term">Other — أخرى</span>: أي مشكلة أخرى</li>
                        </ul>
                    </div>

                    <!-- Sources -->
                    <div class="guide-card">
                        <h3>🔍 مصادر الاكتشاف <small>/ Detection Sources</small></h3>
                        <p>من أين تم اكتشاف المشكلة:</p>
                        <ul>
                            <li><span class="term">Production — الإنتاج</span>: أثناء عملية التصنيع</li>
                            <li><span class="term">Internal Audit — تدقيق داخلي</span>: أثناء التفتيش الداخلي</li>
                            <li><span class="term">Incoming — استلام</span>: عند استلام المواد من المورد</li>
                            <li><span class="term">Customer — زبون</span>: شكوى أو ملاحظة من الزبون</li>
                            <li><span class="term">Supplier — مورد</span>: إشعار من المورد نفسه</li>
                        </ul>
                    </div>

                    <!-- Immediate Action -->
                    <div class="guide-card">
                        <h3>🚑 الإجراء الفوري <small>/ Immediate Action</small></h3>
                        <p>ردة الفعل السريعة فور اكتشاف المشكلة (لاحتوائها):</p>
                        <ul>
                            <li><span class="term">Quarantine — حجز/عزل</span>: عزل المنتج المعيب لمنع استخدامه</li>
                            <li><span class="term">100% Sorting — فرز شامل</span>: فحص الشحنة بالكامل لفصل المعيب</li>
                            <li><span class="term">Machine Adjustment — تعديل الآلة</span>: إيقاف الآلة وتصحيح إعداداتها</li>
                        </ul>
                        <div style="margin-top:10px; padding:8px; background:#e3f2fd; border-radius:6px; font-size:0.85em; color:#1565c0;">
                            <strong>💡 الفرق:</strong> الإجراء الفوري هو التدخل الميداني العاجل لاحتواء المشكلة، بينما "القرار المتخذ" أدناه هو المصير الإداري النهائي للمنتج.
                        </div>
                    </div>

                    <!-- Disposition -->
                    <div class="guide-card">
                        <h3>⚖️ القرار المتخذ <small>/ Disposition</small></h3>
                        <p>المصير النهائي للمنتج غير المطابق:</p>
                        <ul>
                            <li><span class="term">Pending — معلق</span>: لم يُتخذ قرار بعد</li>
                            <li><span class="term">Rework — إعادة تشغيل</span>: إصلاح المنتج وإعادته للإنتاج</li>
                            <li><span class="term">Use As-Is — استعمال كما هو</span>: قبول المنتج رغم العيب (بموافقة
                                خاصة)</li>
                            <li><span class="term">Scrap — إتلاف</span>: التخلص من المنتج نهائياً</li>
                            <li><span class="term">Return to Supplier — إرجاع للمورد</span>: إعادة المواد المعيبة للمورد
                            </li>
                        </ul>
                    </div>

                    <!-- How to Use -->
                    <div class="guide-card highlight">
                        <h3>🚀 كيف أستخدم هذه اللوحة؟ <small>/ How to Use</small></h3>
                        <ol style="padding-right:18px; margin:8px 0; color:#444; font-size:0.9em; line-height:1.8;">
                            <li>اضغط على زر <strong>"➕ تسجيل عدم مطابقة جديدة"</strong></li>
                            <li>املأ النموذج: اختر الفئة والشدة والمصدر، ثم اكتب وصف المشكلة</li>
                            <li>حدد المسؤول والموعد النهائي</li>
                            <li>عند مراجعة المشكلة، غيّر الحالة إلى <strong>"قيد المراجعة"</strong></li>
                            <li>اضغط 🔁 لإنشاء <strong>إجراء تصحيحي (CAR)</strong></li>
                            <li>بعد تنفيذ الحل والتحقق منه، غيّر الحالة إلى <strong>"مغلقة"</strong></li>
                        </ol>
                        <p style="margin-top:10px; background:#e8f5e9; padding:10px; border-radius:8px; color:#2e7d32;">
                            💡 <strong>نصيحة:</strong> استخدم الفلاتر أعلى الجدول لتصفية التقارير حسب الحالة أو الشدة أو
                            التاريخ.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($msg): ?>
            <div class="alert alert-success">
                <?= $msg ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="number">
                    <?= $stats['total'] ?>
                </div>
                <div class="label">📋 إجمالي NCR<br>Total</div>
            </div>
            <div class="stat-card open">
                <div class="number">
                    <?= $stats['Open'] ?>
                </div>
                <div class="label">🔴 مفتوحة<br>Open</div>
            </div>
            <div class="stat-card review">
                <div class="number">
                    <?= $stats['Under Review'] ?>
                </div>
                <div class="label">🟡 قيد المراجعة<br>Under Review</div>
            </div>
            <div class="stat-card car-issued">
                <div class="number">
                    <?= $stats['CAR Issued'] ?>
                </div>
                <div class="label">🔵 CAR صادر<br>CAR Issued</div>
            </div>
            <div class="stat-card closed">
                <div class="number">
                    <?= $stats['Closed'] ?>
                </div>
                <div class="label">✅ مغلقة<br>Closed</div>
            </div>
        </div>

        <!-- Severity breakdown -->
        <div class="severity-grid">
            <div class="sev-card critical">
                <span class="count">
                    <?= $stats['Critical'] ?>
                </span>
                <span class="sev-label">🚨 حرجة / Critical</span>
            </div>
            <div class="sev-card major">
                <span class="count">
                    <?= $stats['Major'] ?>
                </span>
                <span class="sev-label">⚠️ رئيسية / Major</span>
            </div>
            <div class="sev-card minor">
                <span class="count">
                    <?= $stats['Minor'] ?>
                </span>
                <span class="sev-label">📌 ثانوية / Minor</span>
            </div>
        </div>

        <!-- Filters & Add -->
        <div class="filters-bar">
            <label>🔍 تصفية:</label>
            <?php if ($is_admin): ?>
                <select id="f-reporter" onchange="filterByReporter(this.value)"
                    style="border:2px solid #0b3c5d; font-weight:600;">
                    <option value="">👥 كل رؤساء الفرق</option>
                    <?php foreach ($reporters as $rep): ?>
                        <option value="<?= htmlspecialchars($rep['reported_by']) ?>" <?= ($filter_reporter === $rep['reported_by']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rep['name'] ?? $rep['reported_by']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <select id="f-status" onchange="filterTable()">
                <option value="">كل الحالات</option>
                <option value="Open">مفتوحة (Open)</option>
                <option value="Under Review">قيد المراجعة</option>
                <option value="CAR Issued">CAR صادر</option>
                <option value="Closed">مغلقة (Closed)</option>
            </select>
            <select id="f-severity" onchange="filterTable()">
                <option value="">كل المستويات</option>
                <option value="Critical">حرجة (Critical)</option>
                <option value="Major">رئيسية (Major)</option>
                <option value="Minor">ثانوية (Minor)</option>
            </select>
            <select id="f-category" onchange="filterTable()">
                <option value="">كل الفئات</option>
                <option value="Product">منتج (Product)</option>
                <option value="Process">عملية (Process)</option>
                <option value="Material">مادة (Material)</option>
                <option value="Supplier">مورد (Supplier)</option>
                <option value="Other">أخرى (Other)</option>
            </select>
            <div class="date-filter-group">
                <label>📅 من:</label>
                <input type="date" id="f-from" onchange="filterTable()">
                <label>إلى:</label>
                <input type="date" id="f-to" onchange="filterTable()">
            </div>
            <button type="button" class="btn-reset" onclick="resetFilters()">🔄 إعادة تعيين</button>
            <span id="filter-count" style="font-size:0.85em; color:#0b3c5d; font-weight:600;"></span>
            <div style="flex-grow:1;"></div>
            <button type="button" class="btn-add" onclick="openModal('ncr-modal')">➕ تسجيل عدم مطابقة جديدة</button>
        </div>

        <!-- NCR Table -->
        <div class="ncr-table">
            <table id="ncr-table">
                <thead>
                    <tr>
                        <th>الرقم</th>
                        <?php if ($is_admin): ?>
                            <th>المُبلِّغ</th><?php endif; ?>
                        <th>الفئة</th>
                        <th>الشدة</th>
                        <th>المصدر</th>
                        <th>الموقع</th>
                        <th>الوصف</th>
                        <th>القرار</th>
                        <th>المسؤول</th>
                        <th>الموعد</th>
                        <th>التاريخ</th>
                        <th>الحالة</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ncrs as $ncr): ?>
                        <?php
                        $statusClass = match ($ncr['status']) {
                            'Open' => 'open',
                            'Under Review' => 'review',
                            'CAR Issued' => 'car-issued',
                            'Closed' => 'closed',
                            default => 'open'
                        };
                        $sevClass = strtolower($ncr['severity'] ?? 'minor');
                        $car_count = count($cars_by_ncr[$ncr['id']] ?? []);
                        ?>
                        <tr data-status="<?= $ncr['status'] ?>" data-severity="<?= $ncr['severity'] ?>"
                            data-category="<?= $ncr['category'] ?>"
                            data-date="<?= $ncr['created_at'] ? date('Y-m-d', strtotime($ncr['created_at'])) : '' ?>"
                            data-reporter="<?= htmlspecialchars($ncr['reported_by'] ?? '') ?>">
                            <td>
                                <strong style="color:#0b3c5d;">
                                    <?= htmlspecialchars($ncr['ncr_number']) ?>
                                </strong>
                                <?php if ($car_count > 0): ?>
                                    <br><span class="toggle-car" onclick="toggleCar(<?= $ncr['id'] ?>)">
                                        <span class="count-badge">
                                            <?= $car_count ?>
                                        </span>CAR
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php if ($is_admin): ?>
                                <td>
                                    <span style="font-size:0.85em; color:#555;">
                                        <?= htmlspecialchars($ncr['reporter_name'] ?? $ncr['reported_by'] ?? '—') ?>
                                    </span>
                                </td>
                            <?php endif; ?>
                            <td>
                                <?= htmlspecialchars($ncr['category']) ?>
                            </td>
                            <td><span class="sev-badge <?= $sevClass ?>">
                                    <?= htmlspecialchars($ncr['severity']) ?>
                                </span></td>
                            <td style="font-size:0.85em;">
                                <?= htmlspecialchars($ncr['source']) ?>
                            </td>
                            <td style="font-size:0.85em;">
                                <?= htmlspecialchars($ncr['location'] ?? '-') ?>
                            </td>
                            <td style="max-width:180px; font-size:0.85em;">
                                <?= htmlspecialchars(mb_strimwidth($ncr['description_en'] ?: $ncr['description_ar'], 0, 80, '...')) ?>
                            </td>
                            <td style="font-size:0.85em;">
                                <?= htmlspecialchars($ncr['disposition'] ?? '-') ?>
                            </td>
                            <td style="font-size:0.85em;">
                                <div style="font-weight:600;">
                                    <?= htmlspecialchars($ncr['assigned_to'] ?: '-') ?>
                                </div>
                                <div style="color:#888; font-size:0.9em;">👤
                                    <?= htmlspecialchars($ncr['reporter_name'] ?? $ncr['reported_by']) ?>
                                </div>
                            </td>
                            <td style="font-size:0.85em;">
                                <?= $ncr['due_date'] ? date('d/m', strtotime($ncr['due_date'])) : '-' ?>
                            </td>
                            <td style="font-size:0.8em; color:#666;">
                                <?= $ncr['created_at'] ? date('d/m/y H:i', strtotime($ncr['created_at'])) : '-' ?>
                            </td>
                            <td><span class="badge <?= $statusClass ?>">
                                    <?= $ncr['status'] ?>
                                </span></td>
                            <td>
                                <form method="POST" class="action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="ncr_id" value="<?= $ncr['id'] ?>">
                                    <?php if ($ncr['status'] !== 'Closed'): ?>
                                        <?php if ($is_admin): ?>
                                            <select name="new_status">
                                                <?php foreach (['Open', 'Under Review', 'CAR Issued', 'Closed'] as $s): ?>
                                                    <option value="<?= $s ?>" <?= $ncr['status'] === $s ? 'selected' : '' ?>>
                                                        <?= $s ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="update_ncr_status" class="btn-save" title="حفظ">💾</button>
                                        <?php endif; ?>
                                        <button type="button" class="btn-car" title="إنشاء CAR"
                                            onclick="openCarModal(<?= $ncr['id'] ?>, '<?= htmlspecialchars($ncr['ncr_number']) ?>')">🔁</button>
                                    <?php else: ?>
                                        <span style="color:#28a745; font-weight:600;">✅</span>
                                    <?php endif; ?>
                                    <?php if ($is_admin): ?>
                                        <button type="submit" name="delete_ncr" class="btn-del" title="حذف"
                                            onclick="return confirm('هل تريد حذف هذا التقرير نهائياً؟')">🗑️</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-print" title="طباعة NCR"
                                        onclick="printNCR(<?= $ncr['id'] ?>)">🖨️</button>
                                </form>
                            </td>
                        </tr>
                        <!-- CAR rows (hidden by default) -->
                        <?php if (!empty($cars_by_ncr[$ncr['id']])): ?>
                            <?php foreach ($cars_by_ncr[$ncr['id']] as $car): ?>
                                <tr class="car-row" id="car-row-<?= $ncr['id'] ?>" style="display:none;">
                                    <td colspan="12">
                                        <div class="car-box">
                                            <h4>🔁
                                                <?= htmlspecialchars($car['car_number']) ?> — إجراء تصحيحي / Corrective Action
                                            </h4>
                                            <p><span class="car-label">السبب الجذري:</span>
                                                <?= htmlspecialchars($car['root_cause'] ?: '-') ?>
                                            </p>
                                            <p><span class="car-label">الإجراء التصحيحي:</span>
                                                <?= htmlspecialchars($car['corrective_action'] ?: '-') ?>
                                            </p>
                                            <p><span class="car-label">الإجراء الوقائي:</span>
                                                <?= htmlspecialchars($car['preventive_action'] ?: '-') ?>
                                            </p>
                                            <p><span class="car-label">المسؤول:</span>
                                                <?= htmlspecialchars($car['responsible'] ?: '-') ?>
                                                | <span class="car-label">الموعد:</span>
                                                <?= $car['deadline'] ? date('d/m/Y', strtotime($car['deadline'])) : '-' ?>
                                                | <span class="car-label">الحالة:</span>
                                                <span
                                                    class="badge <?= match ($car['status']) { 'Open' => 'open', 'In Progress' => 'review', 'Verification' => 'car-issued', 'Closed' => 'closed', default => 'open'} ?>">
                                                    <?= $car['status'] ?>
                                                </span>
                                            </p>
                                            <?php if ($car['status'] !== 'Closed'): ?>
                                                <?php if ($is_admin): ?>
                                                    <form method="POST" class="action-form" style="margin-top:8px;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="car_id" value="<?= $car['id'] ?>">
                                                        <select name="car_status">
                                                            <?php foreach (['Open', 'In Progress', 'Verification', 'Closed'] as $cs): ?>
                                                                <option value="<?= $cs ?>" <?= $car['status'] === $cs ? 'selected' : '' ?>>
                                                                    <?= $cs ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <label style="font-size:0.85em; display:flex; align-items:center; gap:4px;">
                                                            <input type="checkbox" name="effectiveness_ok" value="1"
                                                                <?= $car['effectiveness_ok'] ? 'checked' : '' ?>>
                                                            فعّال ✅
                                                        </label>
                                                        <button type="submit" name="update_car_status" class="btn-save">💾 حفظ</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p style="color:#28a745; font-weight:600;">
                                                    ✅ مغلق
                                                    <?= $car['effectiveness_ok'] ? '— فعّال' : '' ?>
                                                    <?= $car['verified_by'] ? "— تحقق: {$car['verified_by']}" : '' ?>
                                                </p>
                                            <?php endif; ?>
                                            <button type="button" class="btn-print" style="margin-top:6px;" title="طباعة CAR"
                                                onclick="printCAR('<?= htmlspecialchars($car['car_number']) ?>', <?= $ncr['id'] ?>)">🖨️
                                                طباعة CAR</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if (empty($ncrs)): ?>
                        <tr>
                            <td colspan="12" style="text-align:center; padding:40px; color:#888;">
                                📭 لا توجد تقارير عدم مطابقة حالياً<br>
                                <small>No NCR reports yet — Click the button above to create one</small>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============ NCR MODAL ============ -->
    <div class="modal-overlay" id="ncr-modal">
        <div class="modal">
            <h2>➕ تسجيل عدم مطابقة جديدة / New NCR</h2>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>📂 الفئة <small>/ Category</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ نوع المشكلة: هل
                            هي في المنتج أم العملية أم المادة؟</small>
                        <select name="category">
                            <option value="Product">📦 منتج / Product — مشكلة في المنتج النهائي</option>
                            <option value="Process">⚙️ عملية / Process — خلل في طريقة العمل</option>
                            <option value="Material">🧵 مادة / Material — عيب في المواد الخام</option>
                            <option value="Supplier">🚚 مورد / Supplier — مشكلة من المورد</option>
                            <option value="Other">📝 أخرى / Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>⚡ الشدة <small>/ Severity</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ مدى خطورة
                            المشكلة المكتشفة</small>
                        <select name="severity">
                            <option value="Minor">📌 ثانوية / Minor — عيب صغير لا يؤثر على الوظيفة</option>
                            <option value="Major">⚠️ رئيسية / Major — عيب واضح يؤثر على الجودة</option>
                            <option value="Critical">🚨 حرجة / Critical — خطر على المنتج أو السلامة</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>🔍 المصدر <small>/ Source</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ أين تم اكتشاف
                            المشكلة؟</small>
                        <select name="source">
                            <option value="Production">🏭 الإنتاج / Production — أثناء التصنيع</option>
                            <option value="Internal Audit">🔎 تدقيق داخلي / Internal Audit — أثناء التفتيش</option>
                            <option value="Incoming">📥 استلام / Incoming — عند وصول المواد</option>
                            <option value="Customer">👤 زبون / Customer — شكوى زبون</option>
                            <option value="Supplier">🚚 مورد / Supplier — إبلاغ من المورد</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>⚖️ القرار <small>/ Disposition</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ ماذا سنفعل
                            بالمنتج غير المطابق؟</small>
                        <select name="disposition" onchange="toggleNcrDispositionCustom(this)">
                            <option value="Pending">⏳ معلق / Pending — لم يُتخذ قرار بعد</option>
                            <option value="Rework">🔧 إعادة تشغيل / Rework — إصلاح المنتج</option>
                            <option value="Use As-Is">✅ استعمال كما هو — قبول رغم العيب</option>
                            <option value="Scrap">🗑️ إتلاف / Scrap — التخلص النهائي</option>
                            <option value="Return to Supplier">↩️ إرجاع للمورد — إعادة المواد</option>
                            <option value="Expedite Material">🚀 تسريع المواد / Expedite Material</option>
                            <option value="Mark Boundaries">📍 تحديد الحدود / Mark Boundaries</option>
                            <option value="Return to Home">🏠 إعادة للمكان / Return to Home</option>
                            <option value="Reassign Operators">👥 إعادة توزيع العمال / Reassign Operators</option>
                            <option value="__OTHER__">✏️ أخرى — كتابة يدوية / Other — Custom</option>
                        </select>
                        <textarea id="ncr-disposition-custom" name="disposition_custom" rows="2"
                            placeholder="اكتب القرار المتخذ... / Write custom disposition..."
                            style="display:none; margin-top:8px;"></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>📍 الموقع <small>/ Location</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ أين وقعت المشكلة
                            في المصنع؟</small>
                        <select name="location">
                            <option value="">-- 📍 اختر الموقع --</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= htmlspecialchars($loc) ?>">
                                    <?= htmlspecialchars($loc) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>🏢 القسم <small>/ Department</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ القسم المعني
                            بالمشكلة</small>
                        <select name="department">
                            <option value="">-- 🏢 اختر القسم --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept) ?>">
                                    <?= htmlspecialchars($dept) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row single">
                    <div class="form-group">
                        <label>📋 وصف عدم المطابقة <small>/ Description (EN ↔ AR auto-sync)</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ اختر نوع المشكلة
                            من القائمة، أو اكتب وصفاً يدوياً في الأسفل</small>
                        <select name="description_en" id="ncr-desc-select" onchange="syncNcrDesc(this)">
                            <option value="">-- اختر نوع المشكلة / Select Issue --</option>
                            <optgroup label="🧵 المواد الأولية / Raw Materials & Fabric">
                                <option value="Fabric Defect (Holes/Knots)">Fabric Defect (Holes/Knots) | عيوب في القماش
                                    (ثقوب / عقد)</option>
                                <option value="Color Shading / Mismatch">Color Shading / Mismatch | اختلاف درجات اللون
                                    (Nuance)</option>
                                <option value="Wrong Fabric / GSM Issue">Wrong Fabric / GSM Issue | قماش خاطئ / مشكلة في
                                    الوزن</option>
                                <option value="Damaged / Wrong Accessories">Damaged / Wrong Accessories | إكسسوارات
                                    تالفة أو خاطئة</option>
                                <option value="Dirty / Stained Material">Dirty / Stained Material | مواد أولية متسخة /
                                    مبقعة</option>
                            </optgroup>
                            <optgroup label="✂️ القص / Cutting Section">
                                <option value="Wrong Cutting Dimension">Wrong Cutting Dimension | أبعاد القص غير صحيحة
                                </option>
                                <option value="Pattern Misalignment">Pattern Misalignment | عدم تطابق الباترون</option>
                                <option value="Numbering / Bundling Error">Numbering / Bundling Error | خطأ في الترقيم
                                    أو التحزيم</option>
                                <option value="Fraying Edges">Fraying Edges | تنسيل حواف القماش</option>
                                <option value="Missed Notches">Missed Notches | غياب علامات التقابل (Crans)</option>
                            </optgroup>
                            <optgroup label="🧷 الخياطة والتجميع / Sewing & Assembly">
                                <option value="Broken / Skipped Stitches">Broken / Skipped Stitches | غرز مقطوعة / قفز
                                    الغرز</option>
                                <option value="Open Seam / Seam Failure">Open Seam / Seam Failure | خياطة مفتوحة / فشل
                                    الدرزة</option>
                                <option value="Asymmetry / Uneven Parts">Asymmetry / Uneven Parts | عدم تماثل الأجزاء
                                </option>
                                <option value="Puckering / Tension Issue">Puckering / Tension Issue | تكرمش القماش /
                                    مشكلة شد الخيط</option>
                                <option value="Needle Holes / Marks">Needle Holes / Marks | ثقوب الإبرة / آثار الأسنان
                                </option>
                                <option value="Oil Spots (Machine Oil)">Oil Spots (Machine Oil) | بقع زيت الماكينة
                                </option>
                                <option value="Wrong Label / Tag Placement">Wrong Label / Tag Placement | تركيب الملصق
                                    في مكان خاطئ</option>
                            </optgroup>
                            <optgroup label="📏 القياسات والإنهاء / Measurement & Finishing">
                                <option value="Out of Tolerance (+/-)">Out of Tolerance (+/-) | خارج نطاق القياس المسموح
                                </option>
                                <option value="Size Mismatch">Size Mismatch | خطأ في المقاس</option>
                                <option value="Ironing Burn / Shine">Ironing Burn / Shine | حرق المكواة / لمعة غير
                                    مرغوبة</option>
                                <option value="Loose Threads (Uncut)">Loose Threads (Uncut) | خيوط سائبة (عدم التشطيب)
                                </option>
                                <option value="Dirty / Stained Product">Dirty / Stained Product | منتج متسخ</option>
                                <option value="Folding / Packing Error">Folding / Packing Error | خطأ في الطي أو التغليف
                                </option>
                            </optgroup>
                            <optgroup label="⚙️ النظام والإدارة / System & ISO">
                                <option value="Missing Documentation">Missing Documentation | غياب الوثائق / أمر التصنيع
                                </option>
                                <option value="Safety Violation / Hazard">Safety Violation / Hazard | مخالفة إجراءات
                                    السلامة</option>
                                <option value="Machine Breakdown">Machine Breakdown | عطل في الماكينة</option>
                                <option value="Process Non-Conformity">Process Non-Conformity | عدم الالتزام بطريقة
                                    العمل</option>
                            </optgroup>
                            <optgroup label="📝 أخرى / Other">
                                <option value="__OTHER__">✏️ أخرى — كتابة يدوية / Other — Custom</option>
                            </optgroup>
                        </select>
                        <input type="hidden" name="description_ar" id="ncr-desc-ar-hidden">
                        <textarea id="ncr-desc-custom-en" name="description_custom_en" rows="2"
                            placeholder="Describe the non-conformity..."
                            style="display:none; margin-top:8px;"></textarea>
                    </div>
                </div>
                <div class="form-row single" id="ncr-desc-ar-custom-row" style="display:none;">
                    <div class="form-group">
                        <label>وصف يدوي - عربي <small>/ Custom Description AR</small></label>
                        <textarea id="ncr-desc-custom-ar" name="description_custom_ar" rows="2"
                            placeholder="صف عدم المطابقة..." dir="rtl"></textarea>
                    </div>
                </div>
                <div class="form-row single">
                    <div class="form-group">
                        <label>🚑 الإجراء الفوري <small>/ Immediate Action</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ ما هو أول إجراء
                            تم اتخاذه فوراً بعد اكتشاف المشكلة؟</small>
                        <select name="immediate_action" id="ncr-action-select">
                            <option value="">-- 🚑 اختر الإجراء الفوري / Select Action --</option>
                            <option value="Rework / Repair | إعادة العمل / إصلاح">🔧 إعادة العمل / إصلاح — Rework /
                                Repair</option>
                            <option value="Scrap / Reject | إتلاف / رفض نهائي">🗑️ إتلاف / رفض نهائي — Scrap / Reject
                            </option>
                            <option value="100% Sorting / Inspection | فرز شامل 100%">🔍 فرز شامل 100% — 100% Sorting
                            </option>
                            <option value="Quarantine / Isolate | حجز / عزل الكمية">🔒 حجز / عزل الكمية — Quarantine
                            </option>
                            <option value="Concession / Special Release | قبول استثنائي">✅ قبول استثنائي — Concession
                            </option>
                            <option value="Return to Supplier | إرجاع للمورد">↩️ إرجاع للمورد — Return to Supplier
                            </option>
                            <option value="Machine Adjustment | تعديل/ضبط الماكينة">⚙️ تعديل / ضبط الماكينة — Machine
                                Adjustment</option>
                            <option value="Clean / Spot Removal | تنظيف / إزالة البقع">🧹 تنظيف / إزالة البقع — Spot
                                Removal</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>👤 المسؤول <small>/ Assigned To</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ من سيتولى متابعة
                            حل هذه المشكلة؟</small>
                        <select name="assigned_to">
                            <option value="">-- اختر المسؤول / Select --</option>
                            <optgroup label="🏭 الإنتاج / Production">
                                <option value="Line Supervisor | رئيس الفريق">Line Supervisor | رئيس الفريق</option>
                                <option value="Floor Manager | رئيس الورشة">Floor Manager | رئيس الورشة</option>
                                <option value="Production Manager | مدير الإنتاج">Production Manager | مدير الإنتاج
                                </option>
                            </optgroup>
                            <optgroup label="🔍 الجودة والتقنية / Quality & Technical">
                                <option value="Quality Controller (QC) | مراقب الجودة">Quality Controller (QC) | مراقب
                                    الجودة</option>
                                <option value="Quality Manager | مدير الجودة">Quality Manager | مدير الجودة</option>
                                <option value="Technical Manager | المدير التقني">Technical Manager | المدير التقني
                                </option>
                                <option value="Method Agent | مسؤول الطرائق">Method Agent | مسؤول الطرائق</option>
                            </optgroup>
                            <optgroup label="🔧 الصيانة / Maintenance">
                                <option value="Mechanic | ميكانيكي">Mechanic | ميكانيكي</option>
                                <option value="Maintenance Manager | مدير الصيانة">Maintenance Manager | مدير الصيانة
                                </option>
                            </optgroup>
                            <optgroup label="📦 الإمداد / Supply Chain">
                                <option value="Warehouse Manager | أمين المخزن">Warehouse Manager | أمين المخزن</option>
                                <option value="Purchasing Manager | مدير المشتريات">Purchasing Manager | مدير المشتريات
                                </option>
                            </optgroup>
                            <optgroup label="💼 الإدارة / Admin & HR">
                                <option value="HR Manager | مدير الموارد البشرية">HR Manager | مدير الموارد البشرية
                                </option>
                                <option value="HSE Officer | مسؤول السلامة">HSE Officer | مسؤول السلامة</option>
                                <option value="Factory Director | مدير المصنع">Factory Director | مدير المصنع</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>📅 الموعد النهائي <small>/ Due Date</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ آخر موعد لحل
                            المشكلة</small>
                        <input type="date" name="due_date">
                    </div>
                </div>
                <div class="modal-btns">
                    <button type="button" class="btn-cancel" onclick="closeModal('ncr-modal')">إلغاء</button>
                    <button type="button" class="btn-submit" onclick="confirmNCRSubmit(this)">✅ تسجيل NCR</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============ CAR MODAL ============ -->
    <div class="modal-overlay" id="car-modal">
        <div class="modal">
            <h2>🔁 إجراء تصحيحي جديد / New CAR</h2>
            <p style="color:#666; margin-bottom:15px;">مرتبط بـ: <strong id="car-ncr-ref"></strong></p>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="ncr_id" id="car-ncr-id">
                <div class="form-row single">
                    <div class="form-group">
                        <label>🔎 تحليل السبب الجذري <small>/ Root Cause Analysis</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ لماذا حدثت
                            المشكلة أصلاً؟ اختر السبب الجذري الذي أدى لها</small>
                        <select name="root_cause" id="car-root-cause" onchange="syncCarCause(this)">
                            <option value="">-- 🔎 اختر السبب / Select Cause --</option>
                            <optgroup label="👤 العامل / Man">
                                <option value="Operator Lack of Skill / Training">Operator Lack of Skill / Training |
                                    نقص في مهارة / تدريب العامل</option>
                                <option value="Negligence / Attention Error">Negligence / Attention Error | إهمال / سهو
                                    / عدم انتباه</option>
                            </optgroup>
                            <optgroup label="⚙️ الآلة / Machine">
                                <option value="Machine Breakdown / Wear">Machine Breakdown / Wear | عطل ميكانيكي / تآكل
                                    قطع الغيار</option>
                                <option value="Wrong Machine Settings">Wrong Machine Settings | خطأ في ضبط إعدادات
                                    الماكينة</option>
                            </optgroup>
                            <optgroup label="🧶 المواد / Material">
                                <option value="Defective Raw Material">Defective Raw Material | مواد أولية معيبة (من
                                    المصدر)</option>
                                <option value="Wrong Material Supplied">Wrong Material Supplied | تزويد بمواد خاطئة
                                    (خيط/قماش)</option>
                            </optgroup>
                            <optgroup label="📋 الطريقة / Method">
                                <option value="Unclear Tech Pack / Instructions">Unclear Tech Pack / Instructions |
                                    تعليمات / ورقة تقنية غير واضحة</option>
                                <option value="Bad Pattern / Marker Layout">Bad Pattern / Marker Layout | خطأ في
                                    الباترون / الماركر</option>
                            </optgroup>
                            <optgroup label="💡 البيئة / Environment">
                                <option value="Lighting / Environment Issue">Lighting / Environment Issue | سوء الإضاءة
                                    / بيئة العمل</option>
                            </optgroup>
                            <optgroup label="📝 أخرى / Other">
                                <option value="__OTHER__">✏️ أخرى — كتابة يدوية / Other</option>
                            </optgroup>
                        </select>
                        <textarea id="car-root-custom" name="root_cause_custom" rows="2"
                            placeholder="صف السبب الجذري... / Describe the root cause..."
                            style="display:none; margin-top:8px;"></textarea>
                    </div>
                </div>
                <div class="form-row single">
                    <div class="form-group">
                        <label>🛠️ الإجراء التصحيحي <small>/ Corrective Action</small> <span id="car-ca-hint"
                                style="font-size:0.75em; color:#2e7d32; display:none;">💡 مقترح</span></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ ماذا سنفعل لحل
                            المشكلة الحالية؟</small>
                        <select name="corrective_action" id="car-corrective" onchange="toggleCarCustomField(this, 'car-corrective-custom')">
                            <option value="">-- 🛠️ اختر الإجراء / Select Action --</option>
                            <option value="Operator Retraining / Briefing | إعادة تدريب / توجيه العامل">🎓 إعادة تدريب /
                                توجيه العامل — Retraining</option>
                            <option value="Machine Repair / Parts Replacement | إصلاح الماكينة / استبدال قطع الغيار">🔧
                                إصلاح الماكينة / استبدال قطع — Machine Repair</option>
                            <option value="Calibration / Setting Adjustment | معايرة / ضبط الإعدادات">⚙️ معايرة / ضبط
                                الإعدادات — Calibration</option>
                            <option value="Update Tech Pack / Pattern | تعديل الملف التقني / الباترون">📝 تعديل الملف
                                التقني — Update Tech Pack</option>
                            <option value="Supplier Complaint Issued | إصدار شكوى رسمية للمورد">📨 إصدار شكوى للمورد —
                                Supplier Complaint</option>
                            <option value="Material Exchange | استبدال المواد المعيبة">🔄 استبدال المواد المعيبة —
                                Material Exchange</option>
                            <option value="Process Audit Conducted | إجراء تدقيق فوري للعملية">🔍 إجراء تدقيق فوري —
                                Process Audit</option>
                            <option value="__OTHER__">✏️ أخرى — كتابة يدوية / Other — Custom</option>
                        </select>
                        <textarea id="car-corrective-custom" name="corrective_action_custom" rows="2"
                            placeholder="صف الإجراء التصحيحي..." style="display:none; margin-top:8px;"></textarea>
                    </div>
                </div>
                <div class="form-row single">
                    <div class="form-group">
                        <label>🛡️ الإجراء الوقائي <small>/ Preventive Action</small> <span id="car-pa-hint"
                                style="font-size:0.75em; color:#2e7d32; display:none;">💡 مقترح</span></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ كيف نمنع تكرار
                            هذه المشكلة مستقبلاً؟</small>
                        <select name="preventive_action" id="car-preventive" onchange="toggleCarCustomField(this, 'car-preventive-custom')">
                            <option value="">-- 🛡️ اختر الإجراء / Select Action --</option>
                            <option value="Update SOP / Work Instructions | تحديث إجراءات العمل القياسية">📝 تحديث
                                إجراءات العمل (SOP) — Update SOP</option>
                            <option value="Add QC Checkpoint / Gate | إضافة نقطة تفتيش جودة">✅ إضافة نقطة تفتيش جودة —
                                QC Checkpoint</option>
                            <option value="Implement Poka-Yoke (Error Proofing) | تركيب نظام منع الخطأ">🛡️ نظام منع
                                الخطأ (Poka-Yoke)</option>
                            <option value="Update Maintenance Schedule | تعديل جدول الصيانة الوقائية">🔧 تعديل جدول
                                الصيانة الوقائية — Maintenance</option>
                            <option value="Change Supplier / Vendor | تغيير المورد">🚚 تغيير المورد — Change Supplier
                            </option>
                            <option value="Modify Training Matrix | تعديل مصفوفة التدريب">🎓 تعديل مصفوفة التدريب
                                (Polyvalence)</option>
                            <option value="Install Better Lighting / Tools | تحسين الإضاءة / أدوات">💡 تحسين الإضاءة /
                                الأدوات — Lighting/Tools</option>
                            <option value="__OTHER__">✏️ أخرى — كتابة يدوية / Other — Custom</option>
                        </select>
                        <textarea id="car-preventive-custom" name="preventive_action_custom" rows="2"
                            placeholder="كيف نمنع تكرار المشكلة؟" style="display:none; margin-top:8px;"></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>👤 المسؤول <small>/ Responsible</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ من سينفذ الإجراء
                            التصحيحي ويتابعه؟</small>
                        <select name="car_responsible" id="car-responsible-sel">
                            <option value="">-- 👤 اختر المسؤول / Select --</option>
                            <optgroup label="🏭 الإنتاج / Production">
                                <option value="Production Manager | مدير الإنتاج">Production Manager | مدير الإنتاج
                                </option>
                                <option value="Floor Manager | رئيس الورشة">Floor Manager | رئيس الورشة</option>
                                <option value="Line Supervisor | رئيس الفريق">Line Supervisor | رئيس الفريق</option>
                            </optgroup>
                            <optgroup label="🔍 الجودة والتقنية / Quality & Technical">
                                <option value="Quality Manager | مدير الجودة">Quality Manager | مدير الجودة</option>
                                <option value="Quality Controller (QC) | مراقب الجودة">Quality Controller (QC) | مراقب
                                    الجودة</option>
                                <option value="Technical Manager | المدير التقني">Technical Manager | المدير التقني
                                </option>
                                <option value="Method Agent | مسؤول الطرائق">Method Agent | مسؤول الطرائق</option>
                            </optgroup>
                            <optgroup label="🔧 الصيانة / Maintenance">
                                <option value="Maintenance Manager | مدير الصيانة">Maintenance Manager | مدير الصيانة
                                </option>
                                <option value="Mechanic | ميكانيكي">Mechanic | ميكانيكي</option>
                            </optgroup>
                            <optgroup label="📦 الإمداد / Supply Chain">
                                <option value="Purchasing Manager | مدير المشتريات">Purchasing Manager | مدير المشتريات
                                </option>
                                <option value="Warehouse Manager | أمين المخزن">Warehouse Manager | أمين المخزن</option>
                            </optgroup>
                            <optgroup label="💼 الإدارة / Admin & HR">
                                <option value="HR Manager | مدير الموارد البشرية">HR Manager | مدير الموارد البشرية
                                </option>
                                <option value="HSE Officer | مسؤول السلامة">HSE Officer | مسؤول السلامة</option>
                                <option value="Factory Director | مدير المصنع">Factory Director | مدير المصنع</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>📅 الموعد النهائي <small>/ Deadline</small></label>
                        <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ آخر موعد لتنفيذ
                            الإجراء التصحيحي</small>
                        <input type="date" name="car_deadline">
                    </div>
                </div>
                <div class="modal-btns">
                    <button type="button" class="btn-cancel" onclick="closeModal('car-modal')">إلغاء</button>
                    <button type="button" class="btn-submit" onclick="confirmCARSubmit(this)">✅ إنشاء CAR</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- Guide Toggle ---
        function toggleGuide() {
            const content = document.getElementById('guide-content');
            const arrow = document.getElementById('guide-arrow');
            content.classList.toggle('active');
            arrow.classList.toggle('open');
        }

        // --- NCR Description Auto-Sync ---
        const ncrDescMap = {
            'Fabric Defect (Holes/Knots)': 'عيوب في القماش (ثقوب / عقد)',
            'Color Shading / Mismatch': 'اختلاف درجات اللون (Nuance)',
            'Wrong Fabric / GSM Issue': 'قماش خاطئ / مشكلة في الوزن',
            'Damaged / Wrong Accessories': 'إكسسوارات تالفة أو خاطئة',
            'Dirty / Stained Material': 'مواد أولية متسخة / مبقعة',
            'Wrong Cutting Dimension': 'أبعاد القص غير صحيحة',
            'Pattern Misalignment': 'عدم تطابق الباترون',
            'Numbering / Bundling Error': 'خطأ في الترقيم أو التحزيم',
            'Fraying Edges': 'تنسيل حواف القماش',
            'Missed Notches': 'غياب علامات التقابل (Crans)',
            'Broken / Skipped Stitches': 'غرز مقطوعة / قفز الغرز',
            'Open Seam / Seam Failure': 'خياطة مفتوحة / فشل الدرزة',
            'Asymmetry / Uneven Parts': 'عدم تماثل الأجزاء',
            'Puckering / Tension Issue': 'تكرمش القماش / مشكلة شد الخيط',
            'Needle Holes / Marks': 'ثقوب الإبرة / آثار الأسنان',
            'Oil Spots (Machine Oil)': 'بقع زيت الماكينة',
            'Wrong Label / Tag Placement': 'تركيب الملصق في مكان خاطئ',
            'Out of Tolerance (+/-)': 'خارج نطاق القياس المسموح',
            'Size Mismatch': 'خطأ في المقاس',
            'Ironing Burn / Shine': 'حرق المكواة / لمعة غير مرغوبة',
            'Loose Threads (Uncut)': 'خيوط سائبة (عدم التشطيب)',
            'Dirty / Stained Product': 'منتج متسخ',
            'Folding / Packing Error': 'خطأ في الطي أو التغليف',
            'Missing Documentation': 'غياب الوثائق / أمر التصنيع',
            'Safety Violation / Hazard': 'مخالفة إجراءات السلامة',
            'Machine Breakdown': 'عطل في الماكينة',
            'Process Non-Conformity': 'عدم الالتزام بطريقة العمل'
        };

        function syncNcrDesc(sel) {
            const val = sel.value;
            const arHidden = document.getElementById('ncr-desc-ar-hidden');
            const customEn = document.getElementById('ncr-desc-custom-en');
            const customAr = document.getElementById('ncr-desc-custom-ar');
            const customArRow = document.getElementById('ncr-desc-ar-custom-row');

            if (val === '__OTHER__') {
                customEn.style.display = 'block';
                customArRow.style.display = 'block';
                arHidden.value = '';
            } else {
                customEn.style.display = 'none';
                customArRow.style.display = 'none';
                customEn.value = '';
                if (customAr) customAr.value = '';
                arHidden.value = ncrDescMap[val] || '';
            }
        }

        // --- CAR Root Cause → Smart Suggestion (Conditional Logic) ---
        const carCauseMap = {
            'Operator Lack of Skill / Training': {
                ca: 'Operator Retraining / Briefing | إعادة تدريب / توجيه العامل',
                pa: 'Modify Training Matrix | تعديل مصفوفة التدريب',
                resp: 'Production Manager | مدير الإنتاج'
            },
            'Negligence / Attention Error': {
                ca: 'Operator Retraining / Briefing | إعادة تدريب / توجيه العامل',
                pa: 'Add QC Checkpoint / Gate | إضافة نقطة تفتيش جودة',
                resp: 'Production Manager | مدير الإنتاج'
            },
            'Machine Breakdown / Wear': {
                ca: 'Machine Repair / Parts Replacement | إصلاح الماكينة / استبدال قطع الغيار',
                pa: 'Update Maintenance Schedule | تعديل جدول الصيانة الوقائية',
                resp: 'Maintenance Manager | مدير الصيانة'
            },
            'Wrong Machine Settings': {
                ca: 'Calibration / Setting Adjustment | معايرة / ضبط الإعدادات',
                pa: 'Update SOP / Work Instructions | تحديث إجراءات العمل القياسية',
                resp: 'Maintenance Manager | مدير الصيانة'
            },
            'Defective Raw Material': {
                ca: 'Supplier Complaint Issued | إصدار شكوى رسمية للمورد',
                pa: 'Change Supplier / Vendor | تغيير المورد',
                resp: 'Purchasing Manager | مدير المشتريات'
            },
            'Wrong Material Supplied': {
                ca: 'Material Exchange | استبدال المواد المعيبة',
                pa: 'Add QC Checkpoint / Gate | إضافة نقطة تفتيش جودة',
                resp: 'Warehouse Manager | أمين المخزن'
            },
            'Unclear Tech Pack / Instructions': {
                ca: 'Update Tech Pack / Pattern | تعديل الملف التقني / الباترون',
                pa: 'Update SOP / Work Instructions | تحديث إجراءات العمل القياسية',
                resp: 'Technical Manager | المدير التقني'
            },
            'Bad Pattern / Marker Layout': {
                ca: 'Update Tech Pack / Pattern | تعديل الملف التقني / الباترون',
                pa: 'Implement Poka-Yoke (Error Proofing) | تركيب نظام منع الخطأ',
                resp: 'Technical Manager | المدير التقني'
            },
            'Lighting / Environment Issue': {
                ca: 'Process Audit Conducted | إجراء تدقيق فوري للعملية',
                pa: 'Install Better Lighting / Tools | تحسين الإضاءة / أدوات',
                resp: 'HSE Officer | مسؤول السلامة'
            }
        };

        function syncCarCause(sel) {
            const val = sel.value;
            const rootCustom = document.getElementById('car-root-custom');
            const caSel = document.getElementById('car-corrective');
            const paSel = document.getElementById('car-preventive');
            const respSel = document.getElementById('car-responsible-sel');
            const caHint = document.getElementById('car-ca-hint');
            const paHint = document.getElementById('car-pa-hint');

            // Handle __OTHER__ for root cause
            if (val === '__OTHER__') {
                rootCustom.style.display = 'block';
                caHint.style.display = 'none';
                paHint.style.display = 'none';
                return;
            }
            rootCustom.style.display = 'none';
            rootCustom.value = '';

            // Smart suggestion: auto-select corrective, preventive & responsible
            const suggestion = carCauseMap[val];
            if (suggestion) {
                // Set corrective action
                for (let opt of caSel.options) {
                    if (opt.value === suggestion.ca) {
                        caSel.value = suggestion.ca;
                        break;
                    }
                }
                caHint.style.display = 'inline';

                // Set preventive action
                for (let opt of paSel.options) {
                    if (opt.value === suggestion.pa) {
                        paSel.value = suggestion.pa;
                        break;
                    }
                }
                paHint.style.display = 'inline';

                // Set responsible
                if (suggestion.resp && respSel) {
                    for (let opt of respSel.options) {
                        if (opt.value === suggestion.resp) {
                            respSel.value = suggestion.resp;
                            break;
                        }
                    }
                }
            } else {
                caHint.style.display = 'none';
                paHint.style.display = 'none';
            }
        }

        function toggleNcrDispositionCustom(sel) {
            const customField = document.getElementById('ncr-disposition-custom');
            if (sel.value === '__OTHER__') {
                customField.style.display = 'block';
            } else {
                customField.style.display = 'none';
                customField.value = '';
            }
        }

        function toggleCarCustomField(sel, targetId) {
            const customField = document.getElementById(targetId);
            if (sel.value === '__OTHER__') {
                customField.style.display = 'block';
            } else {
                customField.style.display = 'none';
                customField.value = '';
            }
        }

        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        function openCarModal(ncrId, ncrNum) {
            document.getElementById('car-ncr-id').value = ncrId;
            document.getElementById('car-ncr-ref').textContent = ncrNum;
            openModal('car-modal');
        }

        // Close modal on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function (e) {
                if (e.target === this) this.classList.remove('active');
            });
        });

        // --- Toggle CAR rows ---
        function toggleCar(ncrId) {
            const rows = document.querySelectorAll(`#car-row-${ncrId}`);
            rows.forEach(r => {
                r.style.display = r.style.display === 'none' ? '' : 'none';
            });
        }

        // --- Reporter filter (admin only, server-side) ---
        function filterByReporter(cin) {
            const url = new URL(window.location.href);
            if (cin) {
                url.searchParams.set('reporter', cin);
            } else {
                url.searchParams.delete('reporter');
            }
            window.location.href = url.toString();
        }

        // --- Client-side Filters ---
        function filterTable() {
            const status = document.getElementById('f-status').value;
            const severity = document.getElementById('f-severity').value;
            const category = document.getElementById('f-category').value;
            const dateFrom = document.getElementById('f-from').value;
            const dateTo = document.getElementById('f-to').value;
            const rows = document.querySelectorAll('#ncr-table tbody tr:not(.car-row)');
            let visible = 0;

            rows.forEach(row => {
                if (!row.dataset.status) return;
                let show = true;
                if (status && row.dataset.status !== status) show = false;
                if (severity && row.dataset.severity !== severity) show = false;
                if (category && row.dataset.category !== category) show = false;
                const d = row.dataset.date || '';
                if (dateFrom && d && d < dateFrom) show = false;
                if (dateTo && d && d > dateTo) show = false;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            const counter = document.getElementById('filter-count');
            const total = rows.length;
            if (status || severity || category || dateFrom || dateTo) {
                counter.textContent = `📊 ${visible} / ${total}`;
            } else {
                counter.textContent = '';
            }
        }

        function resetFilters() {
            document.getElementById('f-status').value = '';
            document.getElementById('f-severity').value = '';
            document.getElementById('f-category').value = '';
            document.getElementById('f-from').value = '';
            document.getElementById('f-to').value = '';
            // Reset reporter filter too (admin only)
            const repSel = document.getElementById('f-reporter');
            if (repSel && repSel.value) {
                repSel.value = '';
                filterByReporter('');
                return; // page will reload
            }
            filterTable();
        }
    </script>

    <!-- ============ PRINT TEMPLATE ============ -->
    <div id="print-area"></div>

    <script>
        // --- NCR/CAR Data for Print ---
        const ncrData = <?= json_encode(array_map(function ($n) {
            return [
                'id' => $n['id'],
                'ncr_number' => $n['ncr_number'],
                'category' => $n['category'],
                'severity' => $n['severity'],
                'source' => $n['source'],
                'location' => $n['location'] ?? '-',
                'department' => $n['department'] ?? '-',
                'description_en' => $n['description_en'] ?? '',
                'description_ar' => $n['description_ar'] ?? '',
                'immediate_action' => $n['immediate_action'] ?? '-',
                'disposition' => $n['disposition'] ?? '-',
                'assigned_to' => $n['assigned_to'] ?? '-',
                'reported_by' => $n['reported_by'] ?? '-',
                'reporter_name' => $n['reporter_name'] ?? $n['reported_by'] ?? '-',
                'due_date' => $n['due_date'] ?? '-',
                'created_at' => $n['created_at'] ?? '-',
                'status' => $n['status'],
            ];
        }, $ncrs), JSON_UNESCAPED_UNICODE) ?>;

        const carData = <?= json_encode(array_map(function ($c) {
            return [
                'car_number' => $c['car_number'],
                'ncr_id' => $c['ncr_id'],
                'root_cause' => $c['root_cause'] ?? '-',
                'corrective_action' => $c['corrective_action'] ?? '-',
                'preventive_action' => $c['preventive_action'] ?? '-',
                'responsible' => $c['responsible'] ?? '-',
                'deadline' => $c['deadline'] ?? '-',
                'status' => $c['status'],
                'effectiveness_ok' => $c['effectiveness_ok'] ?? 0,
                'verified_by' => $c['verified_by'] ?? '-',
                'verified_at' => $c['verified_at'] ?? '-',
                'created_at' => $c['created_at'] ?? '-',
            ];
        }, $cars_all), JSON_UNESCAPED_UNICODE) ?>;

        function fmtDate(d) {
            if (!d || d === '-') return '-';
            const dt = new Date(d);
            return dt.toLocaleDateString('en-GB');
        }

        function printNCR(ncrId) {
            const ncr = ncrData.find(n => n.id == ncrId);
            if (!ncr) return alert('NCR not found');

            // Find associated CARs
            const cars = carData.filter(c => c.ncr_id == ncrId);
            let carSection = '';
            if (cars.length > 0) {
                cars.forEach(car => {
                    carSection += `
                    <tr><td class="section-header" colspan="2">CORRECTIVE ACTION REPORT — ${car.car_number}</td></tr>
                    <tr><th>Root Cause / السبب الجذري</th><td>${car.root_cause}</td></tr>
                    <tr><th>Corrective Action / إجراء تصحيحي</th><td>${car.corrective_action}</td></tr>
                    <tr><th>Preventive Action / إجراء وقائي</th><td>${car.preventive_action}</td></tr>
                    <tr><th>Responsible</th><td>${car.responsible}</td></tr>
                    <tr><th>Deadline</th><td>${fmtDate(car.deadline)}</td></tr>
                    <tr><th>CAR Status</th><td>${car.status}${car.effectiveness_ok ? ' — Effective ✅' : ''}</td></tr>`;
                });
            }

            const html = `
            <div class="print-header">
                <div class="company">
                    CANDYTEX<br>
                    <small>Textile Manufacturing — Casablanca, Morocco</small>
                    <small>ISO 9001:2015 Quality Management System</small>
                </div>
                <div class="doc-meta">
                    <div class="doc-num">${ncr.ncr_number}</div>
                    <div>Document: QMS-NCR-001</div>
                    <div>Factory: CANDYTEX S.A.R.L - ${ncr.location}</div>
                    <div>Department: ${ncr.department}</div>
                    <div>Revision: 01</div>
                    <div>Date: ${fmtDate(ncr.created_at)}</div>
                    <div>Page 1 of 1</div>
                </div>
            </div>

            <div class="print-title">
                NON-CONFORMITY REPORT (NCR)<br>
                <span style="font-size:10pt;">تقرير عدم المطابقة</span>
            </div>

            <table class="print-table">
                <tr><td class="section-header" colspan="2">NCR DETAILS / تفاصيل عدم المطابقة</td></tr>
                <tr><th>NCR Number / الرقم</th><td><strong>${ncr.ncr_number}</strong></td></tr>
                <tr><th>Date Raised / التاريخ</th><td>${fmtDate(ncr.created_at)}</td></tr>
                <tr><th>Category / الفئة</th><td>${ncr.category}</td></tr>
                <tr><th>Severity / الشدة</th><td><strong>${ncr.severity}</strong></td></tr>
                <tr><th>Source / المصدر</th><td>${ncr.source}</td></tr>
                <tr><th>Location / الموقع</th><td>${ncr.location}</td></tr>
                <tr><th>Department / القسم</th><td>${ncr.department}</td></tr>

                <tr><td class="section-header" colspan="2">DESCRIPTION / الوصف</td></tr>
                <tr><th>Description (EN)</th><td>${ncr.description_en || '-'}</td></tr>
                <tr><th>Description (AR) / الوصف بالعربية</th><td style="direction:rtl; text-align:right;">${ncr.description_ar || '-'}</td></tr>
                <tr><th>Immediate Action / الإجراء الفوري</th><td>${ncr.immediate_action}</td></tr>

                <tr><td class="section-header" colspan="2">DISPOSITION & ASSIGNMENT / القرار والمسؤولية</td></tr>
                <tr><th>Disposition / القرار</th><td>${ncr.disposition}</td></tr>
                <tr><th>Reported By / المُبلِّغ</th><td>${ncr.reporter_name}</td></tr>
                <tr><th>Assigned To / المسؤول</th><td>${ncr.assigned_to}</td></tr>
                <tr><th>Due Date / الموعد النهائي</th><td>${fmtDate(ncr.due_date)}</td></tr>
                <tr><th>Status / الحالة</th><td><strong>${ncr.status}</strong></td></tr>

                ${carSection}
            </table>

            <div class="print-signatures">
                <div class="print-sig-box">
                    <h4>Raised By / المُبلِّغ</h4>
                    <div>Name: ${ncr.reporter_name}</div>
                    <div>Date: ${fmtDate(ncr.created_at)}</div>
                    <div class="sig-line"></div>
                    <div style="font-size:8pt; color:#888; margin-top:4px;">Signature / التوقيع</div>
                </div>
                <div class="print-sig-box">
                    <h4>Reviewed By / المراجع</h4>
                    <div>Name: ________________________</div>
                    <div>Date: ________________________</div>
                    <div class="sig-line"></div>
                    <div style="font-size:8pt; color:#888; margin-top:4px;">Signature / التوقيع</div>
                </div>
            </div>

            <div class="print-footer">
                CANDYTEX Quality Management System — ISO 9001:2015 | Document QMS-NCR-001 Rev.01 | Printed: ${new Date().toLocaleDateString('en-GB')}
            </div>`;

            document.getElementById('print-area').innerHTML = html;
            setTimeout(() => window.print(), 200);
        }

        function printCAR(carNum, ncrId) {
            const car = carData.find(c => c.car_number === carNum);
            const ncr = ncrData.find(n => n.id == ncrId);
            if (!car || !ncr) return alert('Data not found');

            const html = `
            <div class="print-header">
                <div class="company">
                    CANDYTEX<br>
                    <small>Textile Manufacturing — Casablanca, Morocco</small>
                    <small>ISO 9001:2015 Quality Management System</small>
                </div>
                <div class="doc-meta">
                    <div class="doc-num">${car.car_number}</div>
                    <div>Document: QMS-CAR-001</div>
                    <div>Factory: CANDYTEX S.A.R.L - ${ncr.location}</div>
                    <div>Department: ${ncr.department}</div>
                    <div>Revision: 01</div>
                    <div>Date: ${fmtDate(car.created_at)}</div>
                    <div>Page 1 of 1</div>
                </div>
            </div>

            <div class="print-title">
                CORRECTIVE ACTION REPORT (CAR)<br>
                <span style="font-size:12pt;">تقرير الإجراء التصحيحي</span>
            </div>

            <table class="print-table">
                <tr><td class="section-header" colspan="2">REFERENCE NCR / مرجع عدم المطابقة</td></tr>
                <tr><th>NCR Number</th><td>${ncr.ncr_number}</td></tr>
                <tr><th>NCR Description</th><td>${ncr.description_en || ncr.description_ar || '-'}</td></tr>
                <tr><th>Category / Severity</th><td>${ncr.category} / ${ncr.severity}</td></tr>
                <tr><th>NCR Date</th><td>${fmtDate(ncr.created_at)}</td></tr>

                <tr><td class="section-header" colspan="2">ROOT CAUSE ANALYSIS / تحليل السبب الجذري</td></tr>
                <tr><th>Root Cause</th><td>${car.root_cause}</td></tr>

                <tr><td class="section-header" colspan="2">CORRECTIVE ACTION / الإجراء التصحيحي</td></tr>
                <tr><th>Corrective Action</th><td>${car.corrective_action}</td></tr>

                <tr><td class="section-header" colspan="2">PREVENTIVE ACTION / الإجراء الوقائي</td></tr>
                <tr><th>Preventive Action</th><td>${car.preventive_action}</td></tr>

                <tr><td class="section-header" colspan="2">RESPONSIBILITY & TIMELINE / المسؤولية والجدول</td></tr>
                <tr><th>Responsible / المسؤول</th><td>${car.responsible}</td></tr>
                <tr><th>Deadline / الموعد النهائي</th><td>${fmtDate(car.deadline)}</td></tr>
                <tr><th>CAR Status / الحالة</th><td><strong>${car.status}</strong></td></tr>

                <tr><td class="section-header" colspan="2">VERIFICATION / التحقق</td></tr>
                <tr><th>Effectiveness Verified</th><td>${car.effectiveness_ok ? 'Yes ✅ — Effective' : 'Pending'}</td></tr>
                <tr><th>Verified By</th><td>${car.verified_by !== '-' ? car.verified_by : '________________________'}</td></tr>
                <tr><th>Verification Date</th><td>${car.verified_at !== '-' ? fmtDate(car.verified_at) : '________________________'}</td></tr>
            </table>

            <div class="print-signatures">
                <div class="print-sig-box">
                    <h4>Initiated By / المُبادر</h4>
                    <div>Name: ${ncr.reporter_name}</div>
                    <div>Date: ${fmtDate(car.created_at)}</div>
                    <div class="sig-line"></div>
                    <div style="font-size:8pt; color:#888; margin-top:4px;">Signature / التوقيع</div>
                </div>
                <div class="print-sig-box">
                    <h4>Approved By / معتمد من</h4>
                    <div>Name: ________________________</div>
                    <div>Date: ________________________</div>
                    <div class="sig-line"></div>
                    <div style="font-size:8pt; color:#888; margin-top:4px;">Signature / التوقيع</div>
                </div>
            </div>

            <div class="print-footer">
                CANDYTEX Quality Management System — ISO 9001:2015 | Document QMS-CAR-001 Rev.01 | Printed: ${new Date().toLocaleDateString('en-GB')}
            </div>`;

            document.getElementById('print-area').innerHTML = html;
            setTimeout(() => window.print(), 200);
        }

        // ═══════ Confirmation before NCR Create ═══════
        function confirmNCRSubmit(btn) {
            const form = btn.closest('form');
            const cat = form.querySelector('[name="category"]');
            const sev = form.querySelector('[name="severity"]');
            const src = form.querySelector('[name="source"]');
            const desc = form.querySelector('[name="description_en"]');
            const disp = form.querySelector('[name="disposition"]');
            const loc = form.querySelector('[name="location"]');
            const dept = form.querySelector('[name="department"]');
            const assigned = form.querySelector('[name="assigned_to"]');

            const catText = cat ? cat.options[cat.selectedIndex]?.text || '-' : '-';
            const sevText = sev ? sev.options[sev.selectedIndex]?.text || '-' : '-';
            const srcText = src ? src.options[src.selectedIndex]?.text || '-' : '-';
            const descText = desc ? (desc.value === '__OTHER__' ? form.querySelector('[name="description_custom_en"]')?.value || '-' : desc.options[desc.selectedIndex]?.text || '-') : '-';
            const dispText = disp ? (disp.value === '__OTHER__' ? form.querySelector('[name="disposition_custom"]')?.value || '-' : disp.options[disp.selectedIndex]?.text || '-') : '-';
            const locText = loc ? (loc.options[loc.selectedIndex]?.text || '-') : '-';
            const deptText = dept ? (dept.options[dept.selectedIndex]?.text || '-') : '-';
            const assignedText = assigned ? (assigned.options[assigned.selectedIndex]?.text || '-') : '-';

            // Close the selection modal first to avoid overlay issues
            closeModal('ncr-modal');

            Swal.fire({
                title: 'تأكيد البيانات قبل الإرسال',
                html: `
                    <div style="text-align:right; direction:rtl; font-size:0.95em; line-height:2;">
                        <p>⚠️ <strong style="color:#c0392b;">لا يمكن التعديل بعد الإرسال</strong> — يرجى التأكد من المعلومات:</p>
                        <hr>
                        <p>📂 <strong>الفئة:</strong> ${catText}</p>
                        <p>⚡ <strong>الشدة:</strong> ${sevText}</p>
                        <p>📡 <strong>المصدر:</strong> ${srcText}</p>
                        <p>📋 <strong>الوصف:</strong> ${descText}</p>
                        <p>⚖️ <strong>القرار:</strong> ${dispText}</p>
                        <p>📍 <strong>الموقع:</strong> ${locText}</p>
                        <p>🏢 <strong>القسم:</strong> ${deptText}</p>
                        <p>👤 <strong>المسؤول:</strong> ${assignedText}</p>
                    </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '✅ تأكيد الإرسال',
                cancelButtonText: '✏️ مراجعة',
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                width: '550px',
                customClass: { popup: 'swal-rtl' }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Add the hidden submit button name so PHP sees create_ncr
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'create_ncr';
                    hidden.value = '1';
                    form.appendChild(hidden);
                    form.submit();
                } else {
                    // If user cancels/reviews, re-open the selection modal
                    openModal('ncr-modal');
                }
            });
        }
        // ═══════ Confirmation before CAR Create ═══════
        function confirmCARSubmit(btn) {
            const form = btn.closest('form');
            const getSelText = (name) => {
                const el = form.querySelector(`[name="${name}"]`);
                if (!el) return '-';
                if (el.tagName === 'SELECT') {
                    if (el.value === '__OTHER__') {
                        const customEl = form.querySelector(`[name="${name}_custom"]`);
                        return customEl?.value || 'أخرى / Other';
                    }
                    return el.options[el.selectedIndex]?.text || '-';
                }
                return el.value || '-';
            };

            const rootCause = getSelText('root_cause');
            const corrective = getSelText('corrective_action');
            const preventive = getSelText('preventive_action');
            const responsible = getSelText('car_responsible');
            const deadline = form.querySelector('[name="car_deadline"]')?.value || 'غير محدد';
            const ncrRef = document.getElementById('car-ncr-ref')?.textContent || '-';

            // Close the selection modal first
            closeModal('car-modal');

            Swal.fire({
                title: 'تأكيد بيانات الإجراء التصحيحي قبل الإرسال',
                html: `
                    <div style="text-align:right; direction:rtl; font-size:0.95em; line-height:2;">
                        <p>⚠️ <strong style="color:#c0392b;">لا يمكن التعديل بعد الإرسال</strong> — يرجى التأكد من المعلومات:</p>
                        <hr>
                        <p>🔗 <strong>مرتبط بـ:</strong> ${ncrRef}</p>
                        <p>🔎 <strong>السبب الجذري:</strong> ${rootCause}</p>
                        <p>🛠️ <strong>الإجراء التصحيحي:</strong> ${corrective}</p>
                        <p>🛡️ <strong>الإجراء الوقائي:</strong> ${preventive}</p>
                        <p>👤 <strong>المسؤول:</strong> ${responsible}</p>
                        <p>📅 <strong>الموعد:</strong> ${deadline}</p>
                    </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '✅ تأكيد الإرسال',
                cancelButtonText: '✏️ مراجعة',
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                width: '550px',
                customClass: { popup: 'swal-rtl' }
            }).then((result) => {
                if (result.isConfirmed) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'create_car';
                    hidden.value = '1';
                    form.appendChild(hidden);
                    form.submit();
                } else {
                    // Re-open if reviewed
                    openModal('car-modal');
                }
            });
        }
    </script>
</body>

</html>
<?php
session_start();
require 'db.php';
require 'includes/auth.php';
require_once 'includes/smtp_send.php';

if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_role = $_SESSION['role'];
$user_cin = $_SESSION['user_cin'];
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = $user_role === 'admin';

// ═══════════════════════════════════════════════════
// SELF-HEALING SCHEMA
// ═══════════════════════════════════════════════════
$pdo->exec("CREATE TABLE IF NOT EXISTS `risk_register` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `risk_number` VARCHAR(30) NOT NULL UNIQUE,
    `category` VARCHAR(50) DEFAULT 'Process',
    `source` VARCHAR(100) DEFAULT 'Process Observation',
    `location` VARCHAR(100) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `description_en` TEXT DEFAULT NULL,
    `description_ar` TEXT DEFAULT NULL,
    `existing_controls` TEXT DEFAULT NULL,
    `likelihood` TINYINT DEFAULT 1,
    `severity` TINYINT DEFAULT 1,
    `risk_score` TINYINT DEFAULT 1,
    `risk_level` VARCHAR(20) DEFAULT 'Low',
    `mitigation_action` TEXT DEFAULT NULL,
    `responsible` VARCHAR(100) DEFAULT NULL,
    `deadline` DATE DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'Identified',
    `created_by` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `risk_reviews` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `risk_id` INT NOT NULL,
    `review_date` DATE DEFAULT NULL,
    `reviewed_by` VARCHAR(100) DEFAULT NULL,
    `new_likelihood` TINYINT DEFAULT NULL,
    `new_severity` TINYINT DEFAULT NULL,
    `new_risk_score` TINYINT DEFAULT NULL,
    `new_risk_level` VARCHAR(20) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Self-healing: add related_ncr column if missing
try {
    $pdo->query("SELECT related_ncr FROM risk_register LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE risk_register ADD COLUMN related_ncr VARCHAR(30) DEFAULT NULL AFTER status");
}

// ═══════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════
function risk_next_number($pdo)
{
    $year = date('Y');
    $pattern = "RISK-$year-%";
    $stmt = $pdo->prepare("SELECT risk_number FROM risk_register WHERE risk_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $row = $stmt->fetch();
    if ($row) {
        $parts = explode('-', $row['risk_number']);
        $seq = intval(end($parts)) + 1;
    } else {
        $seq = 1;
    }
    return "RISK-$year-" . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

function calc_risk_level($score)
{
    if ($score >= 16)
        return 'Critical';
    if ($score >= 10)
        return 'High';
    if ($score >= 5)
        return 'Medium';
    return 'Low';
}

// --- Load lookup data ---
$locations = $pdo->query("SELECT name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$departments = $pdo->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$msg = '';
$error = '';

// ═══════════════════════════════════════════════════
// POST HANDLERS
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // --- Risk Description EN→AR Map ---
    $risk_desc_map = [
        'Production Line Stoppage' => 'توقف خط الإنتاج',
        'Inconsistent Stitching Quality' => 'عدم انتظام جودة الغرز',
        'Wrong Cut / Pattern Error' => 'خطأ في القص / الباترون',
        'Overproduction / Wrong Quantity' => 'إنتاج زائد / كمية خاطئة',
        'Sewing Machine Malfunction' => 'عطل ماكينة الخياطة',
        'Cutting Machine Failure' => 'عطل ماكينة القص',
        'Iron / Press Malfunction' => 'عطل المكواة / المكبس',
        'Needle Breakage Frequency' => 'تكرار كسر الإبر',
        'Fabric Defect from Supplier' => 'عيب قماش من المورد',
        'Thread Color Variation' => 'تباين لون الخيط',
        'Accessory Shortage' => 'نقص إكسسوارات',
        'Wrong Material Delivery' => 'توريد مواد خاطئة',
        'Operator Skill Gap' => 'نقص مهارات العامل',
        'High Staff Turnover' => 'ارتفاع دوران العمالة',
        'Safety Violation Risk' => 'خطر مخالفة السلامة',
        'Insufficient Training' => 'عدم كفاية التدريب',
        'Customer Return / Rejection' => 'إرجاع / رفض العميل',
        'Measurement Out of Tolerance' => 'قياسات خارج الحدود',
        'Appearance Defect' => 'عيوب المظهر',
        'Label / Packing Error' => 'خطأ في التغليف / البطاقات',
        'Delivery Delay' => 'تأخير التسليم',
        'Supplier Quality Decline' => 'تراجع جودة المورد',
        'Raw Material Price Increase' => 'ارتفاع أسعار المواد',
        'Transportation Damage' => 'تلف أثناء النقل',
    ];

    // CREATE RISK
    if (isset($_POST['create_risk'])) {
        $risk_num = risk_next_number($pdo);
        $desc_en = trim($_POST['description_en'] ?? '');
        $desc_ar = trim($_POST['description_ar'] ?? '');

        // Handle __OTHER__ custom input
        if ($desc_en === '__OTHER__') {
            $desc_en = trim($_POST['description_en_custom'] ?? '');
            $desc_ar = trim($_POST['description_ar_custom'] ?? '');
        } else {
            $desc_ar = $risk_desc_map[$desc_en] ?? $desc_ar;
        }
        $controls_val = trim($_POST['existing_controls'] ?? '');
        if ($controls_val === '__OTHER__')
            $controls_val = trim($_POST['existing_controls_custom'] ?? '');
        $mitigation_val = trim($_POST['mitigation_action'] ?? '');
        if ($mitigation_val === '__OTHER__')
            $mitigation_val = trim($_POST['mitigation_action_custom'] ?? '');

        $likelihood = intval($_POST['likelihood'] ?? 1);
        $severity_val = intval($_POST['severity_risk'] ?? 1);
        $risk_score = $likelihood * $severity_val;
        $risk_level = calc_risk_level($risk_score);

        $stmt = $pdo->prepare("INSERT INTO risk_register
            (risk_number, category, source, location, department,
             description_en, description_ar, existing_controls,
             likelihood, severity, risk_score, risk_level,
             mitigation_action, responsible, deadline, status, related_ncr, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Identified', ?, ?)");
        $responsible_val = trim($_POST['responsible'] ?? '');
        $related_ncr_val = trim($_POST['related_ncr'] ?? '') ?: null;
        $stmt->execute([
            $risk_num,
            trim($_POST['category'] ?? 'Process'),
            trim($_POST['source'] ?? ''),
            trim($_POST['location'] ?? ''),
            trim($_POST['department'] ?? ''),
            $desc_en,
            $desc_ar,
            $controls_val,
            $likelihood,
            $severity_val,
            $risk_score,
            $risk_level,
            $mitigation_val,
            $responsible_val,
            $_POST['deadline'] ?: null,
            $related_ncr_val,
            $user_cin
        ]);
        $msg = "✅ تم تسجيل الخطر: $risk_num (المستوى: $risk_level)";
        audit_log($pdo, 'risk_create', "Created Risk: $risk_num [$risk_level]");

        // Email alert for Critical risks
        if ($risk_level === 'Critical') {
            $email_subject = "🚨 [CRITICAL] New Risk: $risk_num";
            $email_body = "CRITICAL RISK ALERT\n"
                . "===================\n\n"
                . "Risk Number: $risk_num\n"
                . "Category: " . trim($_POST['category'] ?? '') . "\n"
                . "Description: $desc_en\n"
                . "الوصف: $desc_ar\n"
                . "Score: $risk_score ($risk_level)\n"
                . "Responsible: $responsible_val\n"
                . "Deadline: " . ($_POST['deadline'] ?: 'N/A') . "\n\n"
                . "Registered by: $user_name ($user_cin)\n"
                . "Date: " . date('Y-m-d H:i') . "\n\n"
                . "Please take immediate action.\n"
                . "يرجى اتخاذ إجراء فوري.";
            send_email_to_admins($pdo, $email_subject, $email_body);
            $msg .= " 📧 تم إرسال تنبيه للإدارة";
        }

        // WhatsApp notification data
        if ($responsible_val) {
            $wa_user = $pdo->prepare("SELECT phone, whatsapp FROM users WHERE name = ? OR cin = ? LIMIT 1");
            $wa_user->execute([$responsible_val, $responsible_val]);
            $wa_data = $wa_user->fetch();
            $wa_phone = $wa_data['whatsapp'] ?? $wa_data['phone'] ?? '';
            if ($wa_phone) {
                $clean_phone = preg_replace('/[^0-9]/', '', $wa_phone);
                if (substr($clean_phone, 0, 1) === '0')
                    $clean_phone = '212' . substr($clean_phone, 1);
                $wa_msg_text = urlencode("السلام عليكم 👋\n\nتم إسناد خطر جديد إليك:\n\n📋 رقم: $risk_num\n⚠️ المستوى: $risk_level\n📝 $desc_en\n📅 الموعد: " . ($_POST['deadline'] ?: 'غير محدد') . "\n\nرابط المنصة: https://candytex.ma/dash/iso_risk.php\n\nشكراً 🙏");
                $wa_link = "https://wa.me/$clean_phone?text=$wa_msg_text";
            }
        }
    }

    // UPDATE RISK STATUS
    if (isset($_POST['update_risk_status'])) {
        $rid = intval($_POST['risk_id']);
        $new_status = trim($_POST['new_status']);
        $pdo->prepare("UPDATE risk_register SET status = ? WHERE id = ?")->execute([$new_status, $rid]);
        $msg = "✅ تم تحديث الحالة";
        audit_log($pdo, 'risk_update', "Updated Risk #$rid status → $new_status");
    }

    // ADD REVIEW
    if (isset($_POST['add_review'])) {
        $rid = intval($_POST['risk_id']);
        $new_l = intval($_POST['new_likelihood'] ?? 1);
        $new_s = intval($_POST['new_severity'] ?? 1);
        $new_score = $new_l * $new_s;
        $new_level = calc_risk_level($new_score);

        $pdo->prepare("INSERT INTO risk_reviews
            (risk_id, review_date, reviewed_by, new_likelihood, new_severity, new_risk_score, new_risk_level, notes)
            VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?)")->execute([
                    $rid,
                    trim($_POST['reviewed_by'] ?? $user_name),
                    $new_l,
                    $new_s,
                    $new_score,
                    $new_level,
                    trim($_POST['review_notes'] ?? '')
                ]);

        // Update the risk itself
        $pdo->prepare("UPDATE risk_register SET likelihood=?, severity=?, risk_score=?, risk_level=? WHERE id=?")
            ->execute([$new_l, $new_s, $new_score, $new_level, $rid]);
        $msg = "✅ تمت المراجعة — المستوى الجديد: $new_level";
        audit_log($pdo, 'risk_review', "Reviewed Risk #$rid → $new_level (score: $new_score)");
    }

    // DELETE RISK
    if (isset($_POST['delete_risk'])) {
        $rid = intval($_POST['risk_id']);
        $pdo->prepare("DELETE FROM risk_reviews WHERE risk_id = ?")->execute([$rid]);
        $pdo->prepare("DELETE FROM risk_register WHERE id = ?")->execute([$rid]);
        $msg = "🗑️ تم حذف الخطر";
        audit_log($pdo, 'risk_delete', "Deleted Risk #$rid");
    }
}

// ═══════════════════════════════════════════════════
// DATA FETCH
// ═══════════════════════════════════════════════════
$risks = $pdo->query("SELECT r.*, u.name as reporter_name
    FROM risk_register r
    LEFT JOIN users u ON r.created_by = u.cin
    ORDER BY r.risk_score DESC, r.created_at DESC")->fetchAll();

$reviews_all = $pdo->query("SELECT * FROM risk_reviews ORDER BY created_at DESC")->fetchAll();

// Stats
$total = count($risks);
$critical = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Critical'));
$high = count(array_filter($risks, fn($r) => $r['risk_level'] === 'High'));
$medium = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Medium'));
$low = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Low'));
$open_risks = count(array_filter($risks, fn($r) => !in_array($r['status'], ['Closed', 'Mitigated'])));
$reporters = array_values(array_unique(array_filter(array_column($risks, 'reporter_name'))));
sort($reporters);

// Risk matrix data (5x5)
$matrix = [];
for ($l = 1; $l <= 5; $l++)
    for ($s = 1; $s <= 5; $s++)
        $matrix["$l-$s"] = 0;
foreach ($risks as $r) {
    if ($r['status'] !== 'Closed') {
        $key = $r['likelihood'] . '-' . $r['severity'];
        $matrix[$key] = ($matrix[$key] ?? 0) + 1;
    }
}

// Deadline warnings (risks due within 3 days)
$today = date('Y-m-d');
$warn_date = date('Y-m-d', strtotime('+3 days'));
$deadline_warnings = array_filter($risks, function ($r) use ($today, $warn_date) {
    return $r['deadline'] && $r['deadline'] <= $warn_date && $r['deadline'] >= $today
        && !in_array($r['status'], ['Closed', 'Mitigated']);
});

// NCR list for linking dropdown
try {
    $ncr_list = $pdo->query("SELECT ncr_number, description_en FROM ncr_reports ORDER BY id DESC")->fetchAll();
} catch (Exception $e) {
    $ncr_list = [];
}

// Monthly trend data for charts (last 6 months)
$trend_data = [];
for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-$i months"));
    $month_end = date('Y-m-t', strtotime("-$i months"));
    $month_label = date('M Y', strtotime("-$i months"));
    $new_count = 0;
    $closed_count = 0;
    foreach ($risks as $r) {
        $created = substr($r['created_at'], 0, 10);
        if ($created >= $month_start && $created <= $month_end)
            $new_count++;
        if ($r['status'] === 'Closed' && $r['updated_at']) {
            $closed = substr($r['updated_at'], 0, 10);
            if ($closed >= $month_start && $closed <= $month_end)
                $closed_count++;
        }
    }
    $trend_data[] = ['label' => $month_label, 'new' => $new_count, 'closed' => $closed_count];
}

// Category distribution for charts
$cat_dist = [];
foreach ($risks as $r) {
    $cat = $r['category'] ?? 'Other';
    $cat_dist[$cat] = ($cat_dist[$cat] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل المخاطر — Risk Register | CANDYTEX ISO 9001</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .risk-cards {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px
        }

        .risk-card {
            flex: 1;
            min-width: 140px;
            padding: 18px;
            border-radius: 12px;
            text-align: center;
            color: #fff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .15)
        }

        .risk-card h2 {
            margin: 0;
            font-size: 2em
        }

        .risk-card p {
            margin: 4px 0 0;
            font-size: .85em;
            opacity: .9
        }

        .rc-critical {
            background: linear-gradient(135deg, #c0392b, #e74c3c)
        }

        .rc-high {
            background: linear-gradient(135deg, #d35400, #e67e22)
        }

        .rc-medium {
            background: linear-gradient(135deg, #f39c12, #f1c40f);
            color: #333
        }

        .rc-low {
            background: linear-gradient(135deg, #27ae60, #2ecc71)
        }

        .rc-total {
            background: linear-gradient(135deg, #2c3e50, #34495e)
        }

        .rc-open {
            background: linear-gradient(135deg, #8e44ad, #9b59b6)
        }

        .matrix-wrap {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .08)
        }

        .matrix-title {
            font-size: 1.1em;
            font-weight: 700;
            margin-bottom: 12px
        }

        .risk-matrix {
            display: grid;
            grid-template-columns: auto repeat(5, 1fr);
            gap: 2px;
            max-width: 500px
        }

        .rm-cell {
            width: 100%;
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .85em;
            border-radius: 4px;
            color: #fff;
            min-width: 50px
        }

        .rm-header {
            background: #f8f9fa;
            color: #333;
            font-weight: 600;
            font-size: .75em
        }

        .rm-low {
            background: #2ecc71
        }

        .rm-med {
            background: #f1c40f;
            color: #333
        }

        .rm-high {
            background: #e67e22
        }

        .rm-crit {
            background: #e74c3c
        }

        .rm-count {
            font-size: 1.1em;
            font-weight: 800
        }

        .iso-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .08)
        }

        .iso-table th {
            background: linear-gradient(135deg, #1a237e, #283593);
            color: #fff;
            padding: 10px 8px;
            font-size: .8em;
            text-align: center
        }

        .iso-table td {
            padding: 8px;
            text-align: center;
            font-size: .82em;
            border-bottom: 1px solid #eee;
            vertical-align: middle
        }

        .iso-table tr:hover {
            background: #f0f4ff
        }

        .badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: .75em;
            font-weight: 700;
            color: #fff;
            display: inline-block
        }

        .b-critical {
            background: #e74c3c
        }

        .b-high {
            background: #e67e22
        }

        .b-medium {
            background: #f1c40f;
            color: #333
        }

        .b-low {
            background: #2ecc71
        }

        .b-identified {
            background: #3498db
        }

        .b-assessing {
            background: #9b59b6
        }

        .b-mitigated {
            background: #27ae60
        }

        .b-monitoring {
            background: #f39c12;
            color: #333
        }

        .b-closed {
            background: #95a5a6
        }

        .score-box {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 8px;
            font-weight: 800;
            font-size: .9em;
            color: #fff;
            min-width: 35px
        }

        .btn-add {
            background: linear-gradient(135deg, #1a237e, #3949ab);
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: .95em
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(26, 35, 126, .3)
        }

        .btn-save {
            background: #28a745;
            color: #fff;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: .8em
        }

        .btn-del {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.1em
        }

        .btn-review {
            background: #3498db;
            color: #fff;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: .8em
        }

        .btn-print {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.1em;
            padding: 2px 6px
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 1000;
            align-items: center;
            justify-content: center
        }

        .modal-overlay.active {
            display: flex
        }

        .modal-box {
            background: #fff;
            border-radius: 16px;
            padding: 30px;
            max-width: 700px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .3)
        }

        .modal-box h2 {
            margin: 0 0 20px;
            color: #1a237e
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap
        }

        .form-group {
            flex: 1;
            min-width: 200px
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            font-size: .85em;
            color: #333
        }

        .form-group small {
            color: #888;
            font-weight: 400
        }

        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: .9em
        }

        .form-group select:focus,
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #1a237e;
            outline: none
        }

        .modal-btns {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px
        }

        .modal-btns button {
            padding: 10px 22px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: .9em
        }

        .modal-btns .btn-submit {
            background: linear-gradient(135deg, #1a237e, #3949ab);
            color: #fff
        }

        .modal-btns .btn-cancel {
            background: #e0e0e0;
            color: #333
        }

        .filter-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 15px;
            padding: 12px 15px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06)
        }

        .filter-bar select {
            padding: 6px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: .85em
        }

        .filter-bar button {
            padding: 6px 14px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: .85em
        }

        .score-preview {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
            text-align: center;
            font-size: 1em;
            font-weight: 700;
            border: 2px dashed #ddd
        }

        .review-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-top: 6px;
            border-left: 4px solid #3498db;
            font-size: .8em
        }

        @media print {
            body * {
                visibility: hidden
            }

            #print-area,
            #print-area * {
                visibility: visible
            }

            #print-area {
                position: absolute;
                top: 0;
                left: 0;
                width: 210mm;
                padding: 15mm;
                font-family: 'Segoe UI', sans-serif;
                font-size: 10pt;
                color: #000
            }

            .print-header {
                display: flex;
                justify-content: space-between;
                border-bottom: 3px solid #1a237e;
                padding-bottom: 10px;
                margin-bottom: 15px
            }

            .print-header .company {
                font-size: 18pt;
                font-weight: 700;
                color: #1a237e
            }

            .print-header .company small {
                display: block;
                font-size: 9pt;
                color: #555;
                font-weight: 400
            }

            .print-header .doc-meta {
                text-align: right;
                font-size: 9pt;
                color: #555
            }

            .print-header .doc-meta .doc-num {
                font-size: 14pt;
                font-weight: 700;
                color: #1a237e
            }

            .print-title {
                text-align: center;
                font-size: 14pt;
                font-weight: 700;
                color: #1a237e;
                margin: 10px 0;
                padding: 8px;
                border: 2px solid #1a237e;
                border-radius: 4px
            }

            .print-table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0
            }

            .print-table th,
            .print-table td {
                border: 1px solid #ccc;
                padding: 6px 8px;
                font-size: 9pt;
                text-align: left
            }

            .print-table th {
                background: #f0f0f0;
                width: 35%
            }

            .print-table .section-header {
                background: #1a237e;
                color: #fff;
                font-weight: 700;
                text-align: center
            }

            .print-signatures {
                display: flex;
                justify-content: space-between;
                margin-top: 30px
            }

            .print-sig-box {
                width: 45%;
                border: 1px solid #ccc;
                border-radius: 4px;
                padding: 12px
            }

            .print-sig-box h4 {
                margin: 0 0 8px;
                color: #1a237e
            }

            .print-sig-box .sig-line {
                border-bottom: 1px solid #333;
                height: 40px;
                margin-top: 15px
            }

            .print-footer {
                text-align: center;
                font-size: 8pt;
                color: #888;
                border-top: 1px solid #ccc;
                padding-top: 8px;
                margin-top: 20px
            }
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>
    <div class="main-content">
        <div class="header">
            <h2>📋 سجل المخاطر <span style="font-size:.55em;color:#666">Risk Register — ISO 9001:2015 §6.1</span></h2>
        </div>
        <?php if ($msg): ?>
            <div
                style="background:#d4edda;color:#155724;padding:12px 18px;border-radius:8px;margin-bottom:15px;font-weight:600">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($wa_link)): ?>
            <div
                style="background:#d1ecf1;color:#0c5460;padding:12px 18px;border-radius:8px;margin-bottom:15px;display:flex;align-items:center;gap:15px">
                <span>📢 هل تريد إخطار المسؤول عبر واتساب؟</span>
                <a href="<?= $wa_link ?>" target="_blank"
                    style="background:#25D366;color:white;padding:8px 18px;border-radius:8px;text-decoration:none;font-weight:600">📱
                    إرسال واتساب</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($deadline_warnings)): ?>
            <div
                style="background:#fff3cd;color:#856404;padding:12px 18px;border-radius:8px;margin-bottom:15px;border-left:4px solid #ffc107">
                ⚠️ <strong><?= count($deadline_warnings) ?> مخاطر تقترب مواعيدها!</strong>
                <?php foreach ($deadline_warnings as $dw): ?>
                    <span
                        style="display:inline-block;background:#ffeeba;padding:3px 10px;border-radius:5px;margin:3px;font-size:.85em">
                        <strong><?= $dw['risk_number'] ?></strong> — <?= date('d/m', strtotime($dw['deadline'])) ?>
                        <span class="badge b-<?= strtolower($dw['risk_level']) ?>"
                            style="font-size:.7em"><?= $dw['risk_level'] ?></span>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Dashboard Cards -->
        <div class="risk-cards">
            <div class="risk-card rc-total">
                <h2><?= $total ?></h2>
                <p>إجمالي المخاطر</p>
            </div>
            <div class="risk-card rc-open">
                <h2><?= $open_risks ?></h2>
                <p>مخاطر مفتوحة</p>
            </div>
            <div class="risk-card rc-critical">
                <h2><?= $critical ?></h2>
                <p>حرج Critical</p>
            </div>
            <div class="risk-card rc-high">
                <h2><?= $high ?></h2>
                <p>مرتفع High</p>
            </div>
            <div class="risk-card rc-medium">
                <h2><?= $medium ?></h2>
                <p>متوسط Medium</p>
            </div>
            <div class="risk-card rc-low">
                <h2><?= $low ?></h2>
                <p>منخفض Low</p>
            </div>
        </div>

        <!-- 5×5 Risk Matrix -->
        <div class="matrix-wrap">
            <div class="matrix-title">📊 مصفوفة المخاطر 5×5 <small style="font-weight:400;color:#888">Risk Assessment
                    Matrix</small></div>
            <div style="display:flex;gap:30px;flex-wrap:wrap;align-items:flex-start">
                <div>
                    <div style="font-size:.8em;color:#555;margin-bottom:4px;text-align:center">← الشدة (Severity) →
                    </div>
                    <div class="risk-matrix">
                        <div class="rm-cell" style="background:transparent"></div>
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <div class="rm-cell rm-header"><?= $s ?></div>
                        <?php endfor; ?>
                        <?php for ($l = 5; $l >= 1; $l--): ?>
                            <div class="rm-cell rm-header"><?= $l ?></div>
                            <?php for ($s = 1; $s <= 5; $s++):
                                $sc = $l * $s;
                                $cls = $sc >= 16 ? 'rm-crit' : ($sc >= 10 ? 'rm-high' : ($sc >= 5 ? 'rm-med' : 'rm-low'));
                                $cnt = $matrix["$l-$s"] ?? 0;
                                ?>
                                <div class="rm-cell <?= $cls ?>"><?= $cnt > 0 ? "<span class='rm-count'>$cnt</span>" : $sc ?>
                                </div>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </div>
                    <div style="font-size:.75em;color:#555;margin-top:2px">↑ الاحتمالية (Likelihood)</div>
                </div>
                <div style="font-size:.82em;color:#555">
                    <p><span class="badge b-critical">16–25</span> حرج — إجراء فوري</p>
                    <p><span class="badge b-high">10–15</span> مرتفع — خطة عاجلة</p>
                    <p><span class="badge b-medium">5–9</span> متوسط — مراقبة</p>
                    <p><span class="badge b-low">1–4</span> منخفض — مقبول</p>
                </div>
            </div>
        </div>

        <!-- 📊 Analytics Dashboard -->
        <div
            style="background:white;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:20px;margin-bottom:25px">
            <div style="cursor:pointer;display:flex;justify-content:space-between;align-items:center"
                onclick="document.getElementById('analytics-body').style.display = document.getElementById('analytics-body').style.display==='none'?'block':'none'; this.querySelector('span').textContent = document.getElementById('analytics-body').style.display==='none'?'▶':'▼'">
                <h3 style="margin:0">📊 لوحة التحليلات <small style="font-weight:400;color:#888">Analytics
                        Dashboard</small></h3>
                <span style="font-size:1.2em">▶</span>
            </div>
            <div id="analytics-body" style="display:none;margin-top:20px">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px">
                    <div style="background:#f8f9fa;border-radius:10px;padding:15px">
                        <h4 style="margin:0 0 10px;text-align:center">🍩 توزيع المستويات</h4>
                        <canvas id="chart-level" height="220"></canvas>
                    </div>
                    <div style="background:#f8f9fa;border-radius:10px;padding:15px">
                        <h4 style="margin:0 0 10px;text-align:center">📊 المخاطر حسب الفئة</h4>
                        <canvas id="chart-category" height="220"></canvas>
                    </div>
                    <div style="background:#f8f9fa;border-radius:10px;padding:15px">
                        <h4 style="margin:0 0 10px;text-align:center">📈 الاتجاه الشهري</h4>
                        <canvas id="chart-trend" height="220"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div
            style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px">
            <button class="btn-add" onclick="openModal('risk-modal')">➕ تسجيل خطر جديد</button>
            <button class="btn-add" style="background:#17a2b8" onclick="openModal('report-modal')">📈 تقرير
                شهري</button>
            <span id="filter-count" style="font-weight:600;color:#555"></span>
        </div>

        <!-- Filters -->
        <div class="filter-bar">
            <select id="f-level" onchange="filterRisks()">
                <option value="">كل المستويات</option>
                <option value="Critical">Critical</option>
                <option value="High">High</option>
                <option value="Medium">Medium</option>
                <option value="Low">Low</option>
            </select>
            <select id="f-category" onchange="filterRisks()">
                <option value="">كل الفئات</option>
                <option>Process</option>
                <option>Machine</option>
                <option>Material</option>
                <option>Human</option>
                <option>Quality</option>
                <option>Supply Chain</option>
                <option>Customer</option>
                <option>Compliance</option>
                <option>Environment</option>
            </select>
            <select id="f-status" onchange="filterRisks()">
                <option value="">كل الحالات</option>
                <option>Identified</option>
                <option>Under Assessment</option>
                <option>Mitigated</option>
                <option>Monitoring</option>
                <option>Closed</option>
            </select>
            <select id="f-reporter" onchange="filterRisks()">
                <option value="">كل المُسجّلين</option>
                <?php foreach ($reporters as $rp): ?>
                    <option value="<?= htmlspecialchars($rp) ?>"><?= htmlspecialchars($rp) ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="resetFilters()" style="background:#dc3545;color:#fff">🔄 إعادة</button>
        </div>

        <!-- Risk Register Table -->
        <div style="overflow-x:auto">
            <table class="iso-table" id="risk-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الرقم</th>
                        <th>الفئة</th>
                        <th>الوصف</th>
                        <th>L</th>
                        <th>S</th>
                        <th>Score</th>
                        <th>المستوى</th>
                        <th>المسؤول</th>
                        <th>أضافه</th>
                        <th>الموعد</th>
                        <th>الحالة</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($risks)): ?>
                        <tr>
                            <td colspan="13" style="padding:30px;color:#888;font-size:1.1em">لا توجد مخاطر مسجلة — اضغط ➕
                                لإضافة أول خطر</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($risks as $i => $r):
                            $lvl_cls = match ($r['risk_level']) { 'Critical' => 'b-critical', 'High' => 'b-high', 'Medium' => 'b-medium', default => 'b-low'};
                            $st_cls = match ($r['status']) { 'Identified' => 'b-identified', 'Under Assessment' => 'b-assessing', 'Mitigated' => 'b-mitigated', 'Monitoring' => 'b-monitoring', 'Closed' => 'b-closed', default => 'b-identified'};
                            $sc_bg = $r['risk_score'] >= 16 ? '#e74c3c' : ($r['risk_score'] >= 10 ? '#e67e22' : ($r['risk_score'] >= 5 ? '#f1c40f' : '#2ecc71'));
                            $sc_color = $r['risk_score'] >= 5 && $r['risk_score'] < 10 ? '#333' : '#fff';
                            $risk_reviews = array_filter($reviews_all, fn($rv) => $rv['risk_id'] == $r['id']);
                            ?>
                            <tr data-level="<?= $r['risk_level'] ?>" data-category="<?= htmlspecialchars($r['category']) ?>"
                                data-status="<?= $r['status'] ?>"
                                data-reporter="<?= htmlspecialchars($r['reporter_name'] ?? '') ?>">
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($r['risk_number']) ?></strong>
                                    <?php if (!empty($r['related_ncr'])): ?>
                                        <br><a href="iso_ncr.php"
                                            style="font-size:.7em;background:#1a237e;color:#fff;padding:2px 6px;border-radius:4px;text-decoration:none"><?= $r['related_ncr'] ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['category']) ?></td>
                                <td style="text-align:left;max-width:200px">
                                    <?= htmlspecialchars($r['description_en'] ?: '-') ?>
                                    <?php if ($r['description_ar']): ?>
                                        <br><small
                                            style="color:#888;direction:rtl"><?= htmlspecialchars($r['description_ar']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $r['likelihood'] ?></td>
                                <td><?= $r['severity'] ?></td>
                                <td><span class="score-box"
                                        style="background:<?= $sc_bg ?>;color:<?= $sc_color ?>"><?= $r['risk_score'] ?></span>
                                </td>
                                <td><span class="badge <?= $lvl_cls ?>"><?= $r['risk_level'] ?></span></td>
                                <td style="font-size:.75em"><?= htmlspecialchars($r['responsible'] ?: '-') ?></td>
                                <td style="font-size:.75em">
                                    <?= htmlspecialchars($r['reporter_name'] ?: ($r['created_by'] ?: '-')) ?>
                                </td>
                                <td><?= $r['deadline'] ? date('d/m/Y', strtotime($r['deadline'])) : '-' ?></td>
                                <td><span class="badge <?= $st_cls ?>"><?= $r['status'] ?></span></td>
                                <td>
                                    <form method="POST" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="risk_id" value="<?= $r['id'] ?>">
                                        <?php if ($r['status'] !== 'Closed'): ?>
                                            <select name="new_status" style="font-size:.75em;padding:3px">
                                                <?php foreach (['Identified', 'Under Assessment', 'Mitigated', 'Monitoring', 'Closed'] as $st): ?>
                                                    <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= $st ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="update_risk_status" class="btn-save" title="حفظ">💾</button>
                                            <button type="button" class="btn-review" title="مراجعة"
                                                onclick="openReviewModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['risk_number']) ?>', <?= $r['likelihood'] ?>, <?= $r['severity'] ?>)">🔄</button>
                                        <?php else: ?>
                                            <span style="color:#28a745;font-weight:600">✅</span>
                                        <?php endif; ?>
                                        <button type="button" class="btn-print" title="طباعة"
                                            onclick="printRisk(<?= $r['id'] ?>)">🖨️</button>
                                        <button type="submit" name="delete_risk" class="btn-del" title="حذف"
                                            onclick="return confirm('هل تريد حذف هذا الخطر نهائياً؟')">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                            <?php if (!empty($risk_reviews)): ?>
                                <tr class="review-row" data-level="<?= $r['risk_level'] ?>"
                                    data-category="<?= htmlspecialchars($r['category']) ?>" data-status="<?= $r['status'] ?>">
                                    <td colspan="13" style="text-align:left;padding:8px 15px;background:#fafbfc">
                                        <strong style="font-size:.8em">📝 سجل المراجعات:</strong>
                                        <?php foreach ($risk_reviews as $rv): ?>
                                            <div class="review-box">
                                                <strong><?= date('d/m/Y', strtotime($rv['review_date'] ?? $rv['created_at'])) ?></strong>
                                                — L:<?= $rv['new_likelihood'] ?> × S:<?= $rv['new_severity'] ?> =
                                                <strong><?= $rv['new_risk_score'] ?></strong> (<?= $rv['new_risk_level'] ?>)
                                                <?php if ($rv['reviewed_by']): ?> —
                                                    <?= htmlspecialchars($rv['reviewed_by']) ?>                 <?php endif; ?>
                                                <?php if ($rv['notes']): ?><br><em><?= htmlspecialchars($rv['notes']) ?></em><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ═══════ CREATE RISK MODAL ═══════ -->
        <div class="modal-overlay" id="risk-modal">
            <div class="modal-box">
                <h2>📋 تسجيل خطر جديد <small style="font-weight:400">New Risk Entry</small></h2>
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>📂 الفئة <small>/ Category</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ نوع الخطر:
                                هل يتعلق بالعمليات أم المعدات أم المواد؟</small>
                            <select name="category">
                                <option value="Process">🏭 Process / عمليات — خطر في طريقة العمل</option>
                                <option value="Machine">⚙️ Machine / ماكينات — خطر عطل معدات</option>
                                <option value="Material">🧵 Material / مواد — خطر في المواد الخام</option>
                                <option value="Human">👷 Human / بشرية — خطر بشري / مهارات</option>
                                <option value="Quality">🔍 Quality / جودة — خطر على الجودة</option>
                                <option value="Supply Chain">📦 Supply Chain / إمداد — خطر في سلسلة التوريد</option>
                                <option value="Customer">🤝 Customer / عملاء — خطر فقدان العميل</option>
                                <option value="Compliance">📜 Compliance / امتثال — خطر عدم الامتثال</option>
                                <option value="Environment">🌿 Environment / بيئة وسلامة — خطر بيئي</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>📡 المصدر <small>/ Source</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ كيف تم
                                اكتشاف الخطر؟</small>
                            <select name="source">
                                <option>🏭 Process Observation — ملاحظة أثناء العمل</option>
                                <option>🔎 Internal Audit — تدقيق داخلي</option>
                                <option>📊 NCR Analysis — تحليل تقارير عدم المطابقة</option>
                                <option>👤 Customer Complaint — شكوى زبون</option>
                                <option>💼 Management Review — مراجعة إدارية</option>
                                <option>🌐 External Audit Finding — نتائج تدقيق خارجي</option>
                                <option>🚨 Incident Report — تقرير حادث</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>📍 الموقع <small>/ Location</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ أين يوجد
                                الخطر في المصنع؟</small>
                            <select name="location">
                                <option value="">-- 📍 اختر الموقع --</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= htmlspecialchars($loc) ?>"><?= htmlspecialchars($loc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>🏢 القسم <small>/ Department</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ القسم المعني
                                بالخطر</small>
                            <select name="department">
                                <option value="">-- 🏢 اختر القسم --</option>
                                <?php foreach ($departments as $dep): ?>
                                    <option value="<?= htmlspecialchars($dep) ?>"><?= htmlspecialchars($dep) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="min-width:100%">
                            <label>📋 وصف الخطر <small>/ Risk Description</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ اختر نوع
                                الخطر من القائمة، أو اكتب وصفاً يدوياً</small>
                            <select name="description_en" id="risk-desc-sel" onchange="syncRiskDesc(this)">
                                <option value="">-- 📋 اختر الخطر --</option>
                                <optgroup label="🏭 Process / عمليات">
                                    <option>Production Line Stoppage</option>
                                    <option>Inconsistent Stitching Quality</option>
                                    <option>Wrong Cut / Pattern Error</option>
                                    <option>Overproduction / Wrong Quantity</option>
                                </optgroup>
                                <optgroup label="⚙️ Machine / ماكينات">
                                    <option>Sewing Machine Malfunction</option>
                                    <option>Cutting Machine Failure</option>
                                    <option>Iron / Press Malfunction</option>
                                    <option>Needle Breakage Frequency</option>
                                </optgroup>
                                <optgroup label="🧵 Material / مواد">
                                    <option>Fabric Defect from Supplier</option>
                                    <option>Thread Color Variation</option>
                                    <option>Accessory Shortage</option>
                                    <option>Wrong Material Delivery</option>
                                </optgroup>
                                <optgroup label="👷 Human / بشرية">
                                    <option>Operator Skill Gap</option>
                                    <option>High Staff Turnover</option>
                                    <option>Safety Violation Risk</option>
                                    <option>Insufficient Training</option>
                                </optgroup>
                                <optgroup label="🔍 Quality & Customer">
                                    <option>Customer Return / Rejection</option>
                                    <option>Measurement Out of Tolerance</option>
                                    <option>Appearance Defect</option>
                                    <option>Label / Packing Error</option>
                                </optgroup>
                                <optgroup label="📦 Supply Chain">
                                    <option>Delivery Delay</option>
                                    <option>Supplier Quality Decline</option>
                                    <option>Raw Material Price Increase</option>
                                    <option>Transportation Damage</option>
                                </optgroup>
                                <option value="__OTHER__">✏️ أخرى (كتابة يدوية)</option>
                            </select>
                            <input type="text" id="risk-desc-custom-en" name="description_en_custom"
                                placeholder="وصف الخطر بالإنجليزية" style="display:none;margin-top:8px">
                            <input type="hidden" id="risk-desc-ar-hidden" name="description_ar" value="">
                            <div id="risk-desc-ar-custom-row" style="display:none;margin-top:8px">
                                <input type="text" id="risk-desc-custom-ar" name="description_ar_custom"
                                    placeholder="وصف الخطر بالعربية">
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>🛡️ الضوابط الحالية <small>/ Existing Controls</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ ما هي
                                الإجراءات الموجودة حالياً للتحكم في هذا الخطر؟</small>
                            <select name="existing_controls" id="risk-controls"
                                onchange="toggleCustom(this,'ctrl-custom')">
                                <option value="">-- 🛡️ اختر الضوابط --</option>
                                <option value="SOPs & Work Instructions | إجراءات العمل القياسية">📋 SOPs & Work
                                    Instructions | إجراءات العمل القياسية</option>
                                <option value="Preventive Maintenance Plan | جدول الصيانة الوقائية">🔧 Preventive
                                    Maintenance Plan | جدول الصيانة الوقائية</option>
                                <option value="Quality Inspection (AQL) | فحص الجودة">🔍 Quality Inspection (AQL) | فحص
                                    الجودة</option>
                                <option value="PPE Usage (Mandatory) | معدات الوقاية الشخصية">🦺 PPE Usage (Mandatory) |
                                    معدات الوقاية الشخصية</option>
                                <option value="Safety Stock Level | مخزون الأمان">📦 Safety Stock Level | مخزون الأمان
                                </option>
                                <option value="Metal Detection Policy | نظام الكشف عن المعادن">🧲 Metal Detection Policy
                                    | نظام الكشف عن المعادن</option>
                                <option value="CCTV & Security Access | كاميرات المراقبة / الأمن">📹 CCTV & Security
                                    Access | كاميرات المراقبة / الأمن</option>
                                <option value="Staff Training Matrix | التدريب والتأهيل">🎓 Staff Training Matrix |
                                    التدريب والتأهيل</option>
                                <option value="Approved Supplier Contracts | العقود المعتمدة">📄 Approved Supplier
                                    Contracts | العقود المعتمدة</option>
                                <option value="Fire Alarm & Sprinklers | أجهزة الإنذار / الإطفاء">🔥 Fire Alarm &
                                    Sprinklers | أجهزة الإنذار / الإطفاء</option>
                                <option value="__OTHER__">✏️ أخرى (كتابة يدوية)</option>
                            </select>
                            <input type="text" id="ctrl-custom" name="existing_controls_custom"
                                placeholder="اكتب الضوابط..." style="display:none;margin-top:8px">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>🎲 الاحتمالية <small>/ Likelihood (1-5)</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ ما احتمال
                                حدوث هذا الخطر؟ (1=نادر → 5=شبه مؤكد)</small>
                            <select name="likelihood" id="risk-likelihood" onchange="updateScorePreview()">
                                <option value="1">1 — 🟢 نادر (Rare)</option>
                                <option value="2">2 — 🟡 غير مرجح (Unlikely)</option>
                                <option value="3" selected>3 — 🟠 ممكن (Possible)</option>
                                <option value="4">4 — 🟠 مرجح (Likely)</option>
                                <option value="5">5 — 🔴 شبه مؤكد (Almost Certain)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>⚡ الشدة <small>/ Severity (1-5)</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ ما مدى تأثير
                                الخطر إذا حدث؟ (1=ضئيل → 5=كارثي)</small>
                            <select name="severity_risk" id="risk-severity" onchange="updateScorePreview()">
                                <option value="1">1 — 🟢 ضئيل (Negligible)</option>
                                <option value="2">2 — 🟡 طفيف (Minor)</option>
                                <option value="3" selected>3 — 🟠 معتدل (Moderate)</option>
                                <option value="4">4 — 🟠 كبير (Major)</option>
                                <option value="5">5 — 🔴 كارثي (Catastrophic)</option>
                            </select>
                        </div>
                    </div>
                    <div class="score-preview" id="score-preview">
                        المخاطرة: 3 × 3 = <strong>9</strong> → <span class="badge b-medium">Medium</span>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>🛠️ إجراء التخفيف <small>/ Mitigation Action</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ ماذا سنفعل
                                لتقليل احتمال أو تأثير هذا الخطر؟</small>
                            <select name="mitigation_action" id="risk-mitigation"
                                onchange="toggleCustom(this,'mit-custom')">
                                <option value="">-- 🛠️ اختر إجراء التخفيف --</option>
                                <option value="Increase Inspection Frequency | زيادة وتيرة الفحص">🔍 Increase Inspection
                                    Frequency | زيادة وتيرة الفحص</option>
                                <option value="Conduct Refresher Training | إعادة تدريب الموظفين">🎓 Conduct Refresher
                                    Training | إعادة تدريب الموظفين</option>
                                <option value="Update SOPs / Process | تحديث إجراءات العمل">📋 Update SOPs / Process |
                                    تحديث إجراءات العمل</option>
                                <option value="Source Alternative Supplier | البحث عن مورد بديل">🔄 Source Alternative
                                    Supplier | البحث عن مورد بديل</option>
                                <option value="Upgrade Machine / Tooling | شراء معدات جديدة/أحدث">⚙️ Upgrade Machine /
                                    Tooling | شراء معدات جديدة/أحدث</option>
                                <option value="Conduct Internal Audit | إجراء تدقيق داخلي">📝 Conduct Internal Audit |
                                    إجراء تدقيق داخلي</option>
                                <option value="Install Safety Guards/Sensors | تركيب أنظمة حماية إضافية">🛡️ Install
                                    Safety Guards/Sensors | تركيب أنظمة حماية إضافية</option>
                                <option value="Increase Buffer Stock | رفع مخزون الطوارئ">📦 Increase Buffer Stock | رفع
                                    مخزون الطوارئ</option>
                                <option value="Transfer Risk (Insurance) | نقل المخاطر (تأمين)">🏦 Transfer Risk
                                    (Insurance) | نقل المخاطر (تأمين)</option>
                                <option value="__OTHER__">✏️ أخرى (كتابة يدوية)</option>
                            </select>
                            <input type="text" id="mit-custom" name="mitigation_action_custom"
                                placeholder="اكتب إجراء التخفيف..." style="display:none;margin-top:8px">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>👤 المسؤول <small>/ Responsible</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ من سيتولى
                                متابعة وتخفيف هذا الخطر؟</small>
                            <select name="responsible">
                                <option value="">-- 👤 اختر المسؤول --</option>
                                <optgroup label="🏭 الإنتاج / Production">
                                    <option value="Production Manager | مدير الإنتاج">Production Manager | مدير الإنتاج
                                    </option>
                                    <option value="Floor Manager | رئيس الورشة">Floor Manager | رئيس الورشة</option>
                                    <option value="Line Supervisor | رئيس الفريق">Line Supervisor | رئيس الفريق</option>
                                </optgroup>
                                <optgroup label="🔍 الجودة / Quality & Technical">
                                    <option value="Quality Manager | مدير الجودة">Quality Manager | مدير الجودة</option>
                                    <option value="Quality Controller (QC) | مراقب الجودة">Quality Controller (QC) |
                                        مراقب الجودة</option>
                                    <option value="Technical Manager | المدير التقني">Technical Manager | المدير التقني
                                    </option>
                                    <option value="Method Agent | مسؤول الطرائق">Method Agent | مسؤول الطرائق</option>
                                </optgroup>
                                <optgroup label="🔧 الصيانة / Maintenance">
                                    <option value="Maintenance Manager | مدير الصيانة">Maintenance Manager | مدير
                                        الصيانة</option>
                                    <option value="Mechanic | ميكانيكي">Mechanic | ميكانيكي</option>
                                </optgroup>
                                <optgroup label="📦 الإمداد / Supply Chain">
                                    <option value="Purchasing Manager | مدير المشتريات">Purchasing Manager | مدير
                                        المشتريات</option>
                                    <option value="Warehouse Manager | أمين المخزن">Warehouse Manager | أمين المخزن
                                    </option>
                                </optgroup>
                                <optgroup label="💼 الإدارة / Admin & HR">
                                    <option value="HR Manager | مدير الموارد البشرية">HR Manager | مدير الموارد البشرية
                                    </option>
                                    <option value="HSE Officer | مسؤول السلامة">HSE Officer | مسؤول السلامة</option>
                                    <option value="Factory Director | مدير المصنع">Factory Director | مدير المصنع
                                    </option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>📅 الموعد النهائي <small>/ Deadline</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ آخر موعد
                                لتنفيذ إجراء التخفيف</small>
                            <input type="date" name="deadline">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>🔗 ربط NCR <small>/ Link to NCR (optional)</small></label>
                            <small style="display:block; color:#888; margin:-4px 0 6px; font-size:0.8em;">⬅ إذا كان
                                الخطر مرتبط بتقرير عدم مطابقة سابق</small>
                            <select name="related_ncr">
                                <option value="">-- بدون ربط --</option>
                                <?php foreach ($ncr_list as $ncr): ?>
                                    <option value="<?= htmlspecialchars($ncr['ncr_number']) ?>">
                                        <?= htmlspecialchars($ncr['ncr_number']) ?> —
                                        <?= htmlspecialchars(mb_substr($ncr['description_en'] ?? '', 0, 40)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-btns">
                        <button type="button" class="btn-cancel" onclick="closeModal('risk-modal')">إلغاء</button>
                        <button type="submit" name="create_risk" class="btn-submit">📋 تسجيل الخطر</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══════ REVIEW MODAL ═══════ -->
        <div class="modal-overlay" id="review-modal">
            <div class="modal-box">
                <h2>🔄 مراجعة الخطر <small style="font-weight:400" id="review-risk-ref"></small></h2>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="risk_id" id="review-risk-id">
                    <div class="form-row">
                        <div class="form-group">
                            <label>الاحتمالية الجديدة <small>/ New Likelihood</small></label>
                            <select name="new_likelihood" id="review-likelihood" onchange="updateReviewScore()">
                                <option value="1">1 — نادر</option>
                                <option value="2">2 — غير مرجح</option>
                                <option value="3">3 — ممكن</option>
                                <option value="4">4 — مرجح</option>
                                <option value="5">5 — شبه مؤكد</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>الشدة الجديدة <small>/ New Severity</small></label>
                            <select name="new_severity" id="review-severity" onchange="updateReviewScore()">
                                <option value="1">1 — ضئيل</option>
                                <option value="2">2 — طفيف</option>
                                <option value="3">3 — معتدل</option>
                                <option value="4">4 — كبير</option>
                                <option value="5">5 — كارثي</option>
                            </select>
                        </div>
                    </div>
                    <div class="score-preview" id="review-score-preview">المخاطرة: ? × ? = ?</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>المراجع <small>/ Reviewed By</small></label>
                            <input type="text" name="reviewed_by" value="<?= htmlspecialchars($user_name) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>ملاحظات <small>/ Notes</small></label>
                            <textarea name="review_notes" rows="3" placeholder="ملاحظات المراجعة..."></textarea>
                        </div>
                    </div>
                    <div class="modal-btns">
                        <button type="button" class="btn-cancel" onclick="closeModal('review-modal')">إلغاء</button>
                        <button type="submit" name="add_review" class="btn-submit">🔄 حفظ المراجعة</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══════ MONTHLY REPORT MODAL ═══════ -->
        <div class="modal-overlay" id="report-modal">
            <div class="modal-box" style="max-width:700px">
                <h2>📈 تقرير شهري للمخاطر <small style="font-weight:400">Monthly Risk Report</small></h2>
                <div style="margin:15px 0">
                    <label>الشهر:</label>
                    <input type="month" id="report-month" value="<?= date('Y-m') ?>"
                        style="padding:6px 12px;border-radius:6px;border:1px solid #ccc">
                    <button type="button" onclick="printMonthlyReport()" class="btn-submit"
                        style="margin-right:10px">🖨️ طباعة</button>
                </div>
                <div id="report-preview"
                    style="background:#f8f9fa;padding:15px;border-radius:8px;max-height:400px;overflow-y:auto">
                    <p style="text-align:center;color:#888">اختر الشهر واضغط طباعة</p>
                </div>
                <div class="modal-btns" style="margin-top:15px">
                    <button type="button" class="btn-cancel" onclick="closeModal('report-modal')">إغلاق</button>
                </div>
            </div>
        </div>

        <!-- Print Area -->
        <div id="print-area"></div>

        <script>
            // --- Data for printing ---
            const risksData = <?= json_encode($risks, JSON_UNESCAPED_UNICODE) ?>;

            // --- EN→AR Description Map ---
            const riskDescMap = {
                'Production Line Stoppage': 'توقف خط الإنتاج',
                'Inconsistent Stitching Quality': 'عدم انتظام جودة الغرز',
                'Wrong Cut / Pattern Error': 'خطأ في القص / الباترون',
                'Overproduction / Wrong Quantity': 'إنتاج زائد / كمية خاطئة',
                'Sewing Machine Malfunction': 'عطل ماكينة الخياطة',
                'Cutting Machine Failure': 'عطل ماكينة القص',
                'Iron / Press Malfunction': 'عطل المكواة / المكبس',
                'Needle Breakage Frequency': 'تكرار كسر الإبر',
                'Fabric Defect from Supplier': 'عيب قماش من المورد',
                'Thread Color Variation': 'تباين لون الخيط',
                'Accessory Shortage': 'نقص إكسسوارات',
                'Wrong Material Delivery': 'توريد مواد خاطئة',
                'Operator Skill Gap': 'نقص مهارات العامل',
                'High Staff Turnover': 'ارتفاع دوران العمالة',
                'Safety Violation Risk': 'خطر مخالفة السلامة',
                'Insufficient Training': 'عدم كفاية التدريب',
                'Customer Return / Rejection': 'إرجاع / رفض العميل',
                'Measurement Out of Tolerance': 'قياسات خارج الحدود',
                'Appearance Defect': 'عيوب المظهر',
                'Label / Packing Error': 'خطأ في التغليف / البطاقات',
                'Delivery Delay': 'تأخير التسليم',
                'Supplier Quality Decline': 'تراجع جودة المورد',
                'Raw Material Price Increase': 'ارتفاع أسعار المواد',
                'Transportation Damage': 'تلف أثناء النقل',
            };

            // --- Auto-suggestion: Risk → Controls + Mitigation ---
            const riskAutoMap = {
                'Production Line Stoppage': { ctrl: 'Preventive Maintenance Plan | جدول الصيانة الوقائية', mit: 'Upgrade Machine / Tooling | شراء معدات جديدة/أحدث' },
                'Inconsistent Stitching Quality': { ctrl: 'Quality Inspection (AQL) | فحص الجودة', mit: 'Increase Inspection Frequency | زيادة وتيرة الفحص' },
                'Wrong Cut / Pattern Error': { ctrl: 'SOPs & Work Instructions | إجراءات العمل القياسية', mit: 'Update SOPs / Process | تحديث إجراءات العمل' },
                'Overproduction / Wrong Quantity': { ctrl: 'SOPs & Work Instructions | إجراءات العمل القياسية', mit: 'Conduct Internal Audit | إجراء تدقيق داخلي' },
                'Sewing Machine Malfunction': { ctrl: 'Preventive Maintenance Plan | جدول الصيانة الوقائية', mit: 'Upgrade Machine / Tooling | شراء معدات جديدة/أحدث' },
                'Cutting Machine Failure': { ctrl: 'Preventive Maintenance Plan | جدول الصيانة الوقائية', mit: 'Upgrade Machine / Tooling | شراء معدات جديدة/أحدث' },
                'Iron / Press Malfunction': { ctrl: 'Preventive Maintenance Plan | جدول الصيانة الوقائية', mit: 'Upgrade Machine / Tooling | شراء معدات جديدة/أحدث' },
                'Needle Breakage Frequency': { ctrl: 'Metal Detection Policy | نظام الكشف عن المعادن', mit: 'Conduct Refresher Training | إعادة تدريب الموظفين' },
                'Fabric Defect from Supplier': { ctrl: 'Quality Inspection (AQL) | فحص الجودة', mit: 'Source Alternative Supplier | البحث عن مورد بديل' },
                'Thread Color Variation': { ctrl: 'Quality Inspection (AQL) | فحص الجودة', mit: 'Source Alternative Supplier | البحث عن مورد بديل' },
                'Accessory Shortage': { ctrl: 'Safety Stock Level | مخزون الأمان', mit: 'Increase Buffer Stock | رفع مخزون الطوارئ' },
                'Wrong Material Delivery': { ctrl: 'Approved Supplier Contracts | العقود المعتمدة', mit: 'Conduct Internal Audit | إجراء تدقيق داخلي' },
                'Operator Skill Gap': { ctrl: 'Staff Training Matrix | التدريب والتأهيل', mit: 'Conduct Refresher Training | إعادة تدريب الموظفين' },
                'High Staff Turnover': { ctrl: 'Staff Training Matrix | التدريب والتأهيل', mit: 'Conduct Refresher Training | إعادة تدريب الموظفين' },
                'Safety Violation Risk': { ctrl: 'PPE Usage (Mandatory) | معدات الوقاية الشخصية', mit: 'Install Safety Guards/Sensors | تركيب أنظمة حماية إضافية' },
                'Insufficient Training': { ctrl: 'Staff Training Matrix | التدريب والتأهيل', mit: 'Conduct Refresher Training | إعادة تدريب الموظفين' },
                'Customer Return / Rejection': { ctrl: 'Quality Inspection (AQL) | فحص الجودة', mit: 'Increase Inspection Frequency | زيادة وتيرة الفحص' },
                'Measurement Out of Tolerance': { ctrl: 'Quality Inspection (AQL) | فحص الجودة', mit: 'Update SOPs / Process | تحديث إجراءات العمل' },
                'Appearance Defect': { ctrl: 'Quality Inspection (AQL) | فحص الجودة', mit: 'Increase Inspection Frequency | زيادة وتيرة الفحص' },
                'Label / Packing Error': { ctrl: 'SOPs & Work Instructions | إجراءات العمل القياسية', mit: 'Update SOPs / Process | تحديث إجراءات العمل' },
                'Delivery Delay': { ctrl: 'Approved Supplier Contracts | العقود المعتمدة', mit: 'Source Alternative Supplier | البحث عن مورد بديل' },
                'Supplier Quality Decline': { ctrl: 'Approved Supplier Contracts | العقود المعتمدة', mit: 'Source Alternative Supplier | البحث عن مورد بديل' },
                'Raw Material Price Increase': { ctrl: 'Approved Supplier Contracts | العقود المعتمدة', mit: 'Increase Buffer Stock | رفع مخزون الطوارئ' },
                'Transportation Damage': { ctrl: 'Approved Supplier Contracts | العقود المعتمدة', mit: 'Transfer Risk (Insurance) | نقل المخاطر (تأمين)' },
            };

            // --- Modal functions ---
            function openModal(id) { document.getElementById(id).classList.add('active'); }
            function closeModal(id) { document.getElementById(id).classList.remove('active'); }

            // --- Toggle __OTHER__ custom input ---
            function toggleCustom(sel, customId) {
                document.getElementById(customId).style.display = sel.value === '__OTHER__' ? 'block' : 'none';
            }

            // --- Description sync + auto-suggestion ---
            function syncRiskDesc(sel) {
                const v = sel.value;
                const customEn = document.getElementById('risk-desc-custom-en');
                const customArRow = document.getElementById('risk-desc-ar-custom-row');
                const hiddenAr = document.getElementById('risk-desc-ar-hidden');
                const ctrlSel = document.getElementById('risk-controls');
                const mitSel = document.getElementById('risk-mitigation');

                if (v === '__OTHER__') {
                    customEn.style.display = 'block';
                    customArRow.style.display = 'block';
                    hiddenAr.value = '';
                } else {
                    customEn.style.display = 'none';
                    customArRow.style.display = 'none';
                    hiddenAr.value = riskDescMap[v] || '';

                    // Auto-suggest controls + mitigation
                    const auto = riskAutoMap[v];
                    if (auto) {
                        setSelectValue(ctrlSel, auto.ctrl);
                        setSelectValue(mitSel, auto.mit);
                        // Hide custom inputs if they were shown
                        document.getElementById('ctrl-custom').style.display = 'none';
                        document.getElementById('mit-custom').style.display = 'none';
                    }
                }
            }

            // Helper: set select to matching value
            function setSelectValue(sel, val) {
                for (let i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].value === val) { sel.selectedIndex = i; return; }
                }
            }

            // --- Score preview ---
            function calcLevel(score) {
                if (score >= 16) return { label: 'Critical', cls: 'b-critical', ar: 'حرج' };
                if (score >= 10) return { label: 'High', cls: 'b-high', ar: 'مرتفع' };
                if (score >= 5) return { label: 'Medium', cls: 'b-medium', ar: 'متوسط' };
                return { label: 'Low', cls: 'b-low', ar: 'منخفض' };
            }

            function updateScorePreview() {
                const l = parseInt(document.getElementById('risk-likelihood').value);
                const s = parseInt(document.getElementById('risk-severity').value);
                const score = l * s;
                const level = calcLevel(score);
                document.getElementById('score-preview').innerHTML =
                    `المخاطرة: ${l} × ${s} = <strong>${score}</strong> → <span class="badge ${level.cls}">${level.label} (${level.ar})</span>`;
            }

            function updateReviewScore() {
                const l = parseInt(document.getElementById('review-likelihood').value);
                const s = parseInt(document.getElementById('review-severity').value);
                const score = l * s;
                const level = calcLevel(score);
                document.getElementById('review-score-preview').innerHTML =
                    `المخاطرة الجديدة: ${l} × ${s} = <strong>${score}</strong> → <span class="badge ${level.cls}">${level.label} (${level.ar})</span>`;
            }

            // --- Review modal ---
            function openReviewModal(id, riskNum, curL, curS) {
                document.getElementById('review-risk-id').value = id;
                document.getElementById('review-risk-ref').textContent = riskNum;
                document.getElementById('review-likelihood').value = curL;
                document.getElementById('review-severity').value = curS;
                updateReviewScore();
                openModal('review-modal');
            }

            // --- Filters ---
            function filterRisks() {
                const fLevel = document.getElementById('f-level').value;
                const fCat = document.getElementById('f-category').value;
                const fStat = document.getElementById('f-status').value;
                const fReporter = document.getElementById('f-reporter').value;
                const rows = document.querySelectorAll('#risk-table tbody tr');
                let shown = 0;
                rows.forEach(row => {
                    const level = row.dataset.level || '';
                    const cat = row.dataset.category || '';
                    const stat = row.dataset.status || '';
                    const reporter = row.dataset.reporter || '';
                    const match = (!fLevel || level === fLevel) && (!fCat || cat === fCat) && (!fStat || stat === fStat) && (!fReporter || reporter === fReporter);
                    row.style.display = match ? '' : 'none';
                    if (match && !row.classList.contains('review-row')) shown++;
                });
                document.getElementById('filter-count').textContent = (fLevel || fCat || fStat || fReporter) ? `عرض ${shown} من ${<?= $total ?>}` : '';
            }

            function resetFilters() {
                document.getElementById('f-level').value = '';
                document.getElementById('f-category').value = '';
                document.getElementById('f-status').value = '';
                document.getElementById('f-reporter').value = '';
                filterRisks();
            }

            // --- Print ---
            function fmtDate(d) { if (!d) return '-'; const dt = new Date(d); return dt.toLocaleDateString('en-GB'); }

            function printRisk(id) {
                const r = risksData.find(x => x.id == id);
                if (!r) return;
                const level = calcLevel(r.risk_score);
                const pa = document.getElementById('print-area');
                pa.innerHTML = `
            <div class="print-header">
                <div class="company">CANDYTEX<small>Garment Manufacturing — ISO 9001:2015</small></div>
                <div class="doc-meta">
                    <div class="doc-num">${r.risk_number}</div>
                    <div>Document: QMS-RISK-001</div>
                    <div>Date: ${fmtDate(r.created_at)}</div>
                    <div>Status: ${r.status}</div>
                </div>
            </div>
            <div class="print-title">RISK ASSESSMENT REPORT<br><small>تقرير تقييم المخاطر — ISO 9001:2015 §6.1</small></div>
            <table class="print-table">
                <tr class="section-header"><td colspan="2">RISK IDENTIFICATION / تحديد الخطر</td></tr>
                <tr><th>Risk Number / رقم الخطر</th><td>${r.risk_number}</td></tr>
                <tr><th>Category / الفئة</th><td>${r.category || '-'}</td></tr>
                <tr><th>Source / المصدر</th><td>${r.source || '-'}</td></tr>
                <tr><th>Location / الموقع</th><td>${r.location || '-'}</td></tr>
                <tr><th>Department / القسم</th><td>${r.department || '-'}</td></tr>
                <tr><th>Description (EN)</th><td>${r.description_en || '-'}</td></tr>
                <tr><th>Description (AR)</th><td style="direction:rtl">${r.description_ar || '-'}</td></tr>
                <tr><th>Existing Controls</th><td>${r.existing_controls || '-'}</td></tr>
                <tr class="section-header"><td colspan="2">RISK ASSESSMENT / تقييم المخاطر</td></tr>
                <tr><th>Likelihood / الاحتمالية</th><td>${r.likelihood} / 5</td></tr>
                <tr><th>Severity / الشدة</th><td>${r.severity} / 5</td></tr>
                <tr><th>Risk Score / درجة الخطر</th><td><strong>${r.risk_score}</strong> / 25</td></tr>
                <tr><th>Risk Level / مستوى الخطر</th><td><strong>${r.risk_level}</strong></td></tr>
                <tr class="section-header"><td colspan="2">RISK TREATMENT / معالجة الخطر</td></tr>
                <tr><th>Mitigation Action</th><td>${r.mitigation_action || '-'}</td></tr>
                <tr><th>Responsible / المسؤول</th><td>${r.responsible || '-'}</td></tr>
                <tr><th>Deadline / الموعد</th><td>${fmtDate(r.deadline)}</td></tr>
                <tr><th>Status / الحالة</th><td>${r.status}</td></tr>
            </table>
            <div class="print-signatures">
                <div class="print-sig-box">
                    <h4>Prepared By / أعدّ بواسطة</h4>
                    <div>Name: ${r.reporter_name || r.created_by || '___'}</div>
                    <div class="sig-line"></div><small>Signature / التوقيع</small>
                </div>
                <div class="print-sig-box">
                    <h4>Approved By / اعتمد بواسطة</h4>
                    <div>Name: ___________________</div>
                    <div class="sig-line"></div><small>Signature / التوقيع</small>
                </div>
            </div>
            <div class="print-footer">CANDYTEX — Quality Management System — ISO 9001:2015 — Confidential</div>
        `;
                window.print();
            }

            // ═══════ MONTHLY REPORT PRINT ═══════
            function printMonthlyReport() {
                const month = document.getElementById('report-month').value;
                if (!month) return alert('اختر الشهر');
                const [year, mon] = month.split('-');
                const monthName = new Date(year, mon - 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                const monthRisks = risksData.filter(r => r.created_at && r.created_at.startsWith(month));
                const allActive = risksData.filter(r => r.status !== 'Closed');
                const levelCount = { Critical: 0, High: 0, Medium: 0, Low: 0 };
                allActive.forEach(r => { if (levelCount[r.risk_level] !== undefined) levelCount[r.risk_level]++; });
                const catCount = {};
                allActive.forEach(r => { catCount[r.category] = (catCount[r.category] || 0) + 1; });

                const pa = document.getElementById('print-area');
                pa.innerHTML = `
                    <div class="print-header">
                        <div class="print-logo">🏭 CANDYTEX S.A.R.L<br><small style="font-size:10pt;font-weight:normal">Excellence in Textiles</small></div>
                        <div style="text-align:center">
                            <h2 style="margin:0">MONTHLY RISK REPORT</h2>
                            <p style="margin:5px 0">تقرير المخاطر الشهري</p>
                            <b>Period: ${monthName}</b>
                        </div>
                        <div class="doc-info"><b>Ref:</b> OP-RISK-RPT<br><b>Rev:</b> 1.0 (2026)<br><b>Type:</b> Confidential</div>
                    </div>
                    <h3>ملخص عام / Summary</h3>
                    <table class="iso-table" style="width:100%;margin-bottom:20px">
                        <tr><th>إجمالي المخاطر</th><td>${risksData.length}</td><th>مخاطر نشطة</th><td>${allActive.length}</td></tr>
                        <tr><th>جديدة هذا الشهر</th><td>${monthRisks.length}</td><th>مغلقة</th><td>${risksData.filter(r => r.status === 'Closed').length}</td></tr>
                    </table>
                    <h3>توزيع المستويات / Level Distribution</h3>
                    <table class="iso-table" style="width:100%;margin-bottom:20px">
                        <tr><th>Critical 🔴</th><td>${levelCount.Critical}</td><th>High 🟠</th><td>${levelCount.High}</td></tr>
                        <tr><th>Medium 🟡</th><td>${levelCount.Medium}</td><th>Low 🟢</th><td>${levelCount.Low}</td></tr>
                    </table>
                    <h3>حسب الفئة / By Category</h3>
                    <table class="iso-table" style="width:100%;margin-bottom:20px">
                        ${Object.entries(catCount).map(([k, v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('')}
                    </table>
                    <h3>أعلى 5 مخاطر / Top 5 Risks</h3>
                    <table class="iso-table" style="width:100%;margin-bottom:20px">
                        <tr><th>رقم</th><th>الفئة</th><th>الوصف</th><th>Score</th><th>المستوى</th></tr>
                        ${allActive.sort((a, b) => b.risk_score - a.risk_score).slice(0, 5).map(r => `<tr><td>${r.risk_number}</td><td>${r.category}</td><td>${r.description_en || '-'}</td><td>${r.risk_score}</td><td>${r.risk_level}</td></tr>`).join('')}
                    </table>
                    <div class="print-signatures">
                        <div class="print-sig-box"><h4>Prepared By</h4><div>Name: <?= htmlspecialchars($user_name) ?></div><div class="sig-line"></div><small>Signature</small></div>
                        <div class="print-sig-box"><h4>Approved By</h4><div>Name: _______________</div><div class="sig-line"></div><small>Signature</small></div>
                    </div>
                    <div class="print-footer">CANDYTEX — Quality Management System — ISO 9001:2015 — Confidential</div>
                `;
                window.print();
            }

            // ═══════ CHART.JS INITIALIZATION ═══════
            document.addEventListener('DOMContentLoaded', function () {
                // Chart 1: Level Distribution (Doughnut)
                new Chart(document.getElementById('chart-level'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Critical', 'High', 'Medium', 'Low'],
                        datasets: [{
                            data: [<?= $critical ?>, <?= $high ?>, <?= $medium ?>, <?= $low ?>],
                            backgroundColor: ['#e74c3c', '#e67e22', '#f1c40f', '#2ecc71'],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'bottom', labels: { font: { size: 11 } } }
                        }
                    }
                });

                // Chart 2: Category Distribution (Bar)
                new Chart(document.getElementById('chart-category'), {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode(array_keys($cat_dist)) ?>,
                        datasets: [{
                            label: 'Risks',
                            data: <?= json_encode(array_values($cat_dist)) ?>,
                            backgroundColor: '#667eea',
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });

                // Chart 3: Monthly Trend (Line)
                const trendLabels = <?= json_encode(array_column($trend_data, 'label')) ?>;
                const trendNew = <?= json_encode(array_column($trend_data, 'new')) ?>;
                const trendClosed = <?= json_encode(array_column($trend_data, 'closed')) ?>;
                new Chart(document.getElementById('chart-trend'), {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: [
                            {
                                label: 'جديد (New)',
                                data: trendNew,
                                borderColor: '#e74c3c',
                                backgroundColor: 'rgba(231,76,60,0.1)',
                                fill: true,
                                tension: 0.3
                            },
                            {
                                label: 'مغلق (Closed)',
                                data: trendClosed,
                                borderColor: '#2ecc71',
                                backgroundColor: 'rgba(46,204,113,0.1)',
                                fill: true,
                                tension: 0.3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });
            });
        </script>
    </div>
</body>

</html>
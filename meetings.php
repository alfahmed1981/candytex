<?php
session_start();
require 'db.php';
require 'includes/auth.php';
require_once 'includes/smtp_send.php';

if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];
$user_name = $_SESSION['user_name'] ?? 'Unknown';
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// ═══ AUTO-CREATE TABLES ═══
$pdo->exec("CREATE TABLE IF NOT EXISTS `meetings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `meeting_date` DATE NOT NULL,
    `meeting_time` TIME NOT NULL,
    `location` VARCHAR(255) DEFAULT NULL,
    `committee` VARCHAR(100) DEFAULT NULL,
    `called_by` VARCHAR(100) DEFAULT NULL,
    `agenda_items` TEXT DEFAULT NULL,
    `decisions` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('planned','completed','cancelled') DEFAULT 'planned',
    `created_by` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `meeting_attendees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `meeting_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `role_title` VARCHAR(100) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `attended` TINYINT(1) DEFAULT NULL,
    `signature` VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (`meeting_id`) REFERENCES `meetings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ═══ AUTO-CREATE NOTIFICATIONS TABLE ═══
$pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_cin` VARCHAR(20) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `link` VARCHAR(500) DEFAULT NULL,
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ═══ SEED FIRST MEETING ═══
$cnt = $pdo->query("SELECT COUNT(*) FROM meetings")->fetchColumn();
if ($cnt == 0) {
    $pdo->prepare("INSERT INTO meetings (title, meeting_date, meeting_time, location, committee, called_by, agenda_items, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([
            'اجتماع لجنة السلامة',
            '2026-02-13',
            '11:30:00',
            'Candy 1 - قاعة الاجتماعات',
            'لجنة السلامة والصحة المهنية',
            $user_name,
            json_encode(['كيفية تيسير مهام التعامل مع الموقع', 'لوحة SQDC و مشاكلها', 'متابعة الإجراءات السابقة'], JSON_UNESCAPED_UNICODE),
            'اجتماع يوم الجمعة الأسبوعي',
            $user_cin
        ]);
    $mid = $pdo->lastInsertId();
    $att = $pdo->prepare("INSERT INTO meeting_attendees (meeting_id, name, role_title, department) VALUES (?,?,?,?)");
    $att->execute([$mid, 'مسؤول السلامة', 'رئيس اللجنة', 'Quality']);
    $att->execute([$mid, 'رئيس الإنتاج', 'عضو', 'Production']);
    $att->execute([$mid, 'رئيس الصيانة', 'عضو', 'Maintenance']);
    $att->execute([$mid, 'مسؤول الجودة', 'عضو', 'Quality']);
}

// ═══ LOAD ISO REFERENCE DATA ═══
// Departments & Locations from DB
$departments = $pdo->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$locations = $pdo->query("SELECT name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
// Users for "called_by" and "attendee" selection
$all_users = $pdo->query("SELECT cin, name, role, department, email, phone, whatsapp FROM users WHERE status='active' ORDER BY name")->fetchAll();

// ISO Predefined Lists
$meeting_types = [
    'اجتماع لجنة السلامة والصحة المهنية' => 'اجتماع لجنة السلامة والصحة المهنية',
    'اجتماع مراجعة الإدارة' => 'اجتماع مراجعة الإدارة',
    'اجتماع لجنة الجودة' => 'اجتماع لجنة الجودة',
    'اجتماع فريق التحسين المستمر' => 'اجتماع فريق التحسين المستمر',
    'اجتماع تخطيط الإنتاج' => 'اجتماع تخطيط الإنتاج',
    'اجتماع تقييم المخاطر' => 'اجتماع تقييم المخاطر',
    'اجتماع إطلاق مشروع' => 'اجتماع إطلاق مشروع',
    'اجتماع متابعة NCR/CAR' => 'اجتماع متابعة NCR/CAR',
    'اجتماع طارئ' => 'اجتماع طارئ',
    'اجتماع تدريبي' => 'اجتماع تدريبي',
    'أخرى' => 'أخرى'
];

$committees = [
    'لجنة السلامة والصحة المهنية',
    'لجنة الجودة',
    'لجنة مراجعة الإدارة',
    'فريق التحسين المستمر',
    'لجنة الإنتاج',
    'لجنة الصيانة',
    'لجنة التدريب',
    'لجنة تقييم المخاطر',
    'أخرى'
];

$meeting_locations = [
    'Candy 1 - قاعة الاجتماعات',
    'Candy 2 - قاعة الاجتماعات',
    'Candy 3 - قاعة الاجتماعات',
    'المكتب الرئيسي',
    'ورشة الإنتاج',
    'مكتب الجودة',
    'مكتب المدير العام',
    'عبر الإنترنت (ZOOM/Teams)',
    'أخرى'
];

$role_titles = [
    'رئيس اللجنة',
    'نائب الرئيس',
    'المقرر',
    'عضو',
    'مدعو',
    'مراقب',
    'مستشار خارجي'
];

$predefined_agenda = [
    'مراجعة محضر الاجتماع السابق',
    'متابعة القرارات السابقة',
    'كيفية تيسير مهام التعامل مع الموقع',
    'لوحة SQDC و مشاكلها',
    'تحليل حوادث السلامة',
    'مراجعة سجل المخاطر',
    'مراجعة إجراءات عدم المطابقة NCR/CAR',
    'مراجعة مؤشرات الأداء KPI',
    'خطة التدريب والتوعية',
    'تحسين بيئة العمل',
    'خطة الصيانة الوقائية',
    'مراجعة شكاوى العملاء',
    'تحليل الأسباب الجذرية',
    'خطة العمل التصحيحية',
    'مواضيع متفرقة'
];

$msg = '';

// ═══ HANDLE PRINT VIEWS ═══
if (isset($_GET['print']) && isset($_GET['id'])) {
    $mid = intval($_GET['id']);
    $m = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
    $m->execute([$mid]);
    $meeting = $m->fetch();
    if (!$meeting) {
        die("اجتماع غير موجود");
    }
    $att_stmt = $pdo->prepare("SELECT * FROM meeting_attendees WHERE meeting_id = ? ORDER BY id");
    $att_stmt->execute([$mid]);
    $attendees = $att_stmt->fetchAll();
    $agenda = json_decode($meeting['agenda_items'] ?: '[]', true) ?: [];
    $decisions = json_decode($meeting['decisions'] ?: '[]', true) ?: [];
    $print_type = $_GET['print'];
    include __DIR__ . '/meetings_print.php';
    exit;
}

// ═══ POST ACTIONS ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Only admins can manage meetings
    if (!$is_admin) {
        $msg = "⛔ ليس لديك صلاحية لإدارة الاجتماعات";
    } else {

    if (isset($_POST['create_meeting'])) {
        $title = trim($_POST['title']);
        if ($title === 'أخرى' && !empty(trim($_POST['title_other'] ?? ''))) {
            $title = trim($_POST['title_other']);
        }
        $date = $_POST['meeting_date'];
        $time = $_POST['meeting_time'];
        $loc = trim($_POST['location'] ?? '');
        if ($loc === 'أخرى' && !empty(trim($_POST['location_other'] ?? ''))) {
            $loc = trim($_POST['location_other']);
        }
        $committee = trim($_POST['committee'] ?? '');
        if ($committee === 'أخرى' && !empty(trim($_POST['committee_other'] ?? ''))) {
            $committee = trim($_POST['committee_other']);
        }
        $called = trim($_POST['called_by'] ?? '');
        // Agenda from checkboxes + custom items
        $items = $_POST['agenda_items'] ?? [];
        $custom_agenda = trim($_POST['agenda_custom'] ?? '');
        if ($custom_agenda) {
            $items = array_merge($items, array_filter(array_map('trim', explode("\n", $custom_agenda))));
        }
        $notes = trim($_POST['notes'] ?? '');

        $pdo->prepare("INSERT INTO meetings (title, meeting_date, meeting_time, location, committee, called_by, agenda_items, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$title, $date, $time, $loc, $committee, $called, json_encode($items, JSON_UNESCAPED_UNICODE), $notes, $user_cin]);
        audit_log($pdo, 'meeting_create', "Created meeting: $title");
        $msg = "✅ تم إنشاء الاجتماع بنجاح";
    }

    if (isset($_POST['update_meeting'])) {
        $id = intval($_POST['meeting_id']);
        $title = trim($_POST['title']);
        if ($title === 'أخرى' && !empty(trim($_POST['title_other'] ?? ''))) {
            $title = trim($_POST['title_other']);
        }
        $date = $_POST['meeting_date'];
        $time = $_POST['meeting_time'];
        $loc = trim($_POST['location'] ?? '');
        if ($loc === 'أخرى' && !empty(trim($_POST['location_other'] ?? ''))) {
            $loc = trim($_POST['location_other']);
        }
        $committee = trim($_POST['committee'] ?? '');
        if ($committee === 'أخرى' && !empty(trim($_POST['committee_other'] ?? ''))) {
            $committee = trim($_POST['committee_other']);
        }
        $called = trim($_POST['called_by'] ?? '');
        $items = $_POST['agenda_items'] ?? [];
        $custom_agenda = trim($_POST['agenda_custom'] ?? '');
        if ($custom_agenda) {
            $items = array_merge($items, array_filter(array_map('trim', explode("\n", $custom_agenda))));
        }
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'planned';
        $decisions_raw = trim($_POST['decisions_text'] ?? '');
        $decisions = array_filter(array_map('trim', explode("\n", $decisions_raw)));

        $pdo->prepare("UPDATE meetings SET title=?, meeting_date=?, meeting_time=?, location=?, committee=?, called_by=?, agenda_items=?, notes=?, status=?, decisions=? WHERE id=?")
            ->execute([$title, $date, $time, $loc, $committee, $called, json_encode($items, JSON_UNESCAPED_UNICODE), $notes, $status, json_encode($decisions, JSON_UNESCAPED_UNICODE), $id]);
        audit_log($pdo, 'meeting_update', "Updated meeting #$id: $title");
        $msg = "✅ تم تحديث الاجتماع";
    }

    if (isset($_POST['delete_meeting'])) {
        $id = intval($_POST['meeting_id']);
        $pdo->prepare("DELETE FROM meetings WHERE id = ?")->execute([$id]);
        audit_log($pdo, 'meeting_delete', "Deleted meeting #$id");
        $msg = "✅ تم حذف الاجتماع";
    }

    if (isset($_POST['add_attendees_bulk'])) {
        $mid = intval($_POST['meeting_id']);
        $role = trim($_POST['bulk_role'] ?? 'عضو');
        $selected = $_POST['selected_users'] ?? [];
        $added = 0;
        foreach ($selected as $cin) {
            $u = $pdo->prepare("SELECT name, department FROM users WHERE cin = ?"); $u->execute([$cin]); $uf = $u->fetch();
            if ($uf) {
                // Check not already added
                $exists = $pdo->prepare("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = ? AND name = ?");
                $exists->execute([$mid, $uf['name']]); 
                if ($exists->fetchColumn() == 0) {
                    $pdo->prepare("INSERT INTO meeting_attendees (meeting_id, name, role_title, department) VALUES (?,?,?,?)")
                        ->execute([$mid, $uf['name'], $role, $uf['department'] ?? '']);
                    $added++;
                }
            }
        }
        $msg = "✅ تمت إضافة $added حاضر(ين)";
    }

    if (isset($_POST['add_attendee_manual'])) {
        $mid = intval($_POST['meeting_id']);
        $name = trim($_POST['att_name'] ?? '');
        $role = trim($_POST['att_role'] ?? '');
        $dept = trim($_POST['att_dept'] ?? '');
        if ($name) {
            $pdo->prepare("INSERT INTO meeting_attendees (meeting_id, name, role_title, department) VALUES (?,?,?,?)")
                ->execute([$mid, $name, $role, $dept]);
        }
        $msg = "✅ تمت إضافة الحاضر";
    }

    if (isset($_POST['remove_attendee'])) {
        $aid = intval($_POST['attendee_id']);
        $pdo->prepare("DELETE FROM meeting_attendees WHERE id = ?")->execute([$aid]);
        $msg = "✅ تم حذف الحاضر";
    }

    if (isset($_POST['mark_attendance'])) {
        $mid = intval($_POST['meeting_id']);
        $att_list = $pdo->prepare("SELECT id FROM meeting_attendees WHERE meeting_id = ?");
        $att_list->execute([$mid]);
        foreach ($att_list->fetchAll() as $a) {
            $val = isset($_POST['attended_' . $a['id']]) ? 1 : 0;
            $pdo->prepare("UPDATE meeting_attendees SET attended = ? WHERE id = ?")->execute([$val, $a['id']]);
        }
        $msg = "✅ تم تسجيل الحضور";
    }

    // ═══ SEND INVITATIONS ═══
    if (isset($_POST['send_invite_email'])) {
        $mid = intval($_POST['meeting_id']);
        $m = $pdo->prepare("SELECT * FROM meetings WHERE id = ?"); $m->execute([$mid]); $meeting = $m->fetch();
        if ($meeting) {
            $agenda = json_decode($meeting['agenda_items'] ?: '[]', true) ?: [];
            $target = $_POST['invite_target'] ?? 'all';
            $att_stmt = $pdo->prepare("SELECT ma.*, u.email, u.phone, u.whatsapp, u.cin as user_cin FROM meeting_attendees ma LEFT JOIN users u ON u.name = ma.name WHERE ma.meeting_id = ?");
            $att_stmt->execute([$mid]);
            $attendees = $att_stmt->fetchAll();

            $subject = "استدعاء لحضور اجتماع: " . $meeting['title'];
            $body  = "السلام عليكم ورحمة الله تعالى وبركاته،\n\n";
            $body .= "يشرفنا دعوتكم لحضور اجتماع: " . $meeting['title'] . "\n\n";
            $body .= "📅 التاريخ: " . $meeting['meeting_date'] . "\n";
            $body .= "🕐 الوقت: " . substr($meeting['meeting_time'],0,5) . "\n";
            if ($meeting['location']) $body .= "📍 المكان: " . $meeting['location'] . "\n";
            if ($meeting['committee']) $body .= "🏛️ اللجنة: " . $meeting['committee'] . "\n";
            if ($agenda) {
                $body .= "\n📋 جدول الأعمال:\n";
                foreach ($agenda as $i => $item) { $body .= ($i+1) . ". " . $item . "\n"; }
            }
            $body .= "\nمع فائق التقدير والاحترام\nCandy Tex";

            $sent = 0; $failed = 0;
            foreach ($attendees as $a) {
                if ($target !== 'all' && $a['name'] !== $target) continue;
                $email = $a['email'] ?? '';
                if ($email) {
                    $result = send_smtp_email($pdo, $email, $subject, $body);
                    if ($result === true) $sent++; else $failed++;
                }
            }
            $msg = "📧 تم إرسال $sent بريد إلكتروني" . ($failed ? " (فشل: $failed)" : "");
            if ($sent == 0 && $failed == 0) $msg = "⚠️ لا يوجد بريد إلكتروني لأي من المدعوين";
        }
    }

    if (isset($_POST['send_invite_app'])) {
        $mid = intval($_POST['meeting_id']);
        $m = $pdo->prepare("SELECT * FROM meetings WHERE id = ?"); $m->execute([$mid]); $meeting = $m->fetch();
        if ($meeting) {
            $target = $_POST['invite_target'] ?? 'all';
            $att_stmt = $pdo->prepare("SELECT ma.*, u.cin as user_cin FROM meeting_attendees ma LEFT JOIN users u ON u.name = ma.name WHERE ma.meeting_id = ?");
            $att_stmt->execute([$mid]);
            $attendees = $att_stmt->fetchAll();

            $notif_title = "📩 استدعاء اجتماع: " . $meeting['title'];
            $notif_msg = "تم استدعاؤكم لحضور اجتماع " . $meeting['title'] . " يوم " . $meeting['meeting_date'] . " الساعة " . substr($meeting['meeting_time'],0,5);
            if ($meeting['location']) $notif_msg .= " في " . $meeting['location'];
            $link = "meetings.php?id=" . $mid;

            $sent = 0;
            $ins = $pdo->prepare("INSERT INTO notifications (user_cin, title, message, link) VALUES (?,?,?,?)");
            foreach ($attendees as $a) {
                if ($target !== 'all' && $a['name'] !== $target) continue;
                if (!empty($a['user_cin'])) {
                    $ins->execute([$a['user_cin'], $notif_title, $notif_msg, $link]);
                    $sent++;
                }
            }
            $msg = "🔔 تم إرسال $sent إشعار عبر التطبيق";
        }
    }
} // end admin else
}

// ═══ FETCH MEETINGS ═══
$filter_status = $_GET['filter_status'] ?? '';
$sql = "SELECT * FROM meetings";
$params = [];
if ($filter_status) {
    $sql .= " WHERE status = ?";
    $params[] = $filter_status;
}
$sql .= " ORDER BY meeting_date DESC, meeting_time DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$meetings = $stmt->fetchAll();

// Detail view
$detail = null;
$detail_attendees = [];
$detail_agenda = [];
$detail_decisions = [];
if (isset($_GET['id']) && !isset($_GET['print'])) {
    $did = intval($_GET['id']);
    $ds = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
    $ds->execute([$did]);
    $detail = $ds->fetch();
    if ($detail) {
        $da = $pdo->prepare("SELECT * FROM meeting_attendees WHERE meeting_id = ? ORDER BY id");
        $da->execute([$did]);
        $detail_attendees = $da->fetchAll();
        $detail_agenda = json_decode($detail['agenda_items'] ?: '[]', true) ?: [];
        $detail_decisions = json_decode($detail['decisions'] ?: '[]', true) ?: [];
    }
}

$status_labels = ['planned' => '📅 مخطط', 'completed' => '✅ منعقد', 'cancelled' => '❌ ملغى'];
$status_colors = ['planned' => '#007bff', 'completed' => '#28a745', 'cancelled' => '#dc3545'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الاجتماعات — Candy Tex</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .meetings-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .meeting-card {
            background: #fff;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
            border-right: 5px solid #007bff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            cursor: pointer;
            transition: transform .15s, box-shadow .15s;
        }

        .meeting-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, .12);
        }

        .meeting-card.status-completed {
            border-right-color: #28a745;
        }

        .meeting-card.status-cancelled {
            border-right-color: #dc3545;
            opacity: .7;
        }

        .mc-info h4 {
            margin: 0 0 5px;
            font-size: 16px;
        }

        .mc-info p {
            margin: 0;
            font-size: 13px;
            color: #666;
        }

        .mc-meta {
            text-align: left;
            font-size: 12px;
            color: #888;
            white-space: nowrap;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            color: #fff;
            font-size: 12px;
            font-weight: bold;
        }

        .detail-panel {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .1);
            margin-bottom: 20px;
        }

        .detail-panel h2 {
            margin: 0 0 5px;
            color: #1a237e;
        }

        .detail-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin: 15px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 14px;
        }

        .detail-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .agenda-list {
            list-style: none;
            padding: 0;
            counter-reset: agenda;
        }

        .agenda-list li {
            padding: 10px 15px;
            margin-bottom: 6px;
            background: #f0f7ff;
            border-radius: 8px;
            border-right: 3px solid #007bff;
            counter-increment: agenda;
        }

        .agenda-list li::before {
            content: counter(agenda) ". ";
            font-weight: bold;
            color: #007bff;
        }

        .att-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .att-table th {
            background: #343a40;
            color: #fff;
            padding: 10px;
            text-align: right;
        }

        .att-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
        }

        .print-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 15px 0;
        }

        .print-actions a {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 10px 18px;
            border-radius: 8px;
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            transition: opacity .2s;
        }

        .print-actions a:hover {
            opacity: .85;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-grid.full {
            grid-template-columns: 1fr;
        }

        .form-group2 {
            display: flex;
            flex-direction: column;
        }

        .form-group2 label {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 4px;
            color: #555;
        }

        .form-group2 select,
        .form-group2 input,
        .form-group2 textarea {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
        }

        .form-group2 select {
            cursor: pointer;
        }

        .section-title {
            font-size: 16px;
            font-weight: bold;
            margin: 20px 0 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #007bff;
            color: #1a237e;
        }

        /* Agenda checkboxes */
        .agenda-check-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin: 10px 0;
        }

        .agenda-check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            transition: background .15s;
            border: 1px solid transparent;
        }

        .agenda-check-item:hover {
            background: #e3f2fd;
            border-color: #90caf9;
        }

        .agenda-check-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #1a237e;
        }

        .other-field {
            display: none;
            margin-top: 6px;
        }

        .other-field.show {
            display: block;
        }

        .iso-badge {
            display: inline-block;
            background: #1a237e;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            margin-right: 5px;
        }

        .tab-buttons {
            display: flex;
            gap: 5px;
            margin-bottom: 10px;
        }

        .tab-btn {
            padding: 8px 16px;
            border-radius: 8px 8px 0 0;
            border: 1px solid #ddd;
            border-bottom: none;
            background: #f8f9fa;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            color: #555;
        }

        .tab-btn.active {
            background: #fff;
            color: #1a237e;
            border-bottom: 2px solid #fff;
        }

        @media(max-width:768px) {

            .form-grid,
            .agenda-check-grid {
                grid-template-columns: 1fr;
            }

            .meeting-card {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>

    <div class="main-content">
        <div class="meetings-container">

            <?php if ($msg): ?>
                <div
                    style="background:#d4edda;color:#155724;padding:12px;border-radius:8px;margin-bottom:15px;font-weight:bold;">
                    <?= $msg ?></div>
            <?php endif; ?>

            <?php if ($detail): ?>
                <!-- ═══ DETAIL VIEW ═══ -->
                <a href="meetings.php"
                    style="display:inline-block;margin-bottom:15px;color:#007bff;text-decoration:none;font-weight:bold;">→
                    العودة للقائمة</a>

                <div class="detail-panel">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
                        <div>
                            <h2><?= htmlspecialchars($detail['title']) ?></h2>
                            <span class="status-badge"
                                style="background:<?= $status_colors[$detail['status']] ?>"><?= $status_labels[$detail['status']] ?></span>
                        </div>
                        <div class="print-actions">
                            <a href="?print=invite&id=<?= $detail['id'] ?>" style="background:#1565c0;" target="_blank">🖨️
                                استدعاء</a>
                            <a href="?print=agenda&id=<?= $detail['id'] ?>" style="background:#2e7d32;" target="_blank">📋
                                جدول الأعمال</a>
                            <a href="?print=minutes&id=<?= $detail['id'] ?>" style="background:#e65100;" target="_blank">📝
                                محضر الاجتماع</a>
                            <a href="?print=attendance&id=<?= $detail['id'] ?>" style="background:#6a1b9a;"
                                target="_blank">👥 لائحة الحضور</a>
                        </div>

                        <?php if ($is_admin): ?>
                        <!-- ═══ INVITATION CHANNELS ═══ -->
                        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <span style="font-size:13px;font-weight:bold;color:#555;">📨 إرسال الاستدعاء:</span>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="meeting_id" value="<?= $detail['id'] ?>">
                                <input type="hidden" name="invite_target" value="all">
                                <button type="submit" name="send_invite_email" style="background:#d93025;color:#fff;padding:7px 14px;border:none;border-radius:8px;font-size:12px;cursor:pointer;">📧 بريد الكل</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="meeting_id" value="<?= $detail['id'] ?>">
                                <input type="hidden" name="invite_target" value="all">
                                <button type="submit" name="send_invite_app" style="background:#ff6f00;color:#fff;padding:7px 14px;border:none;border-radius:8px;font-size:12px;cursor:pointer;">🔔 إشعار الكل</button>
                            </form>
                            <?php
                            $wa_agenda = json_decode($detail['agenda_items'] ?: '[]', true) ?: [];
                            $wa_text = "السلام عليكم ورحمة الله،\nاستدعاء لحضور اجتماع: " . $detail['title'] . "\n";
                            $wa_text .= "📅 " . $detail['meeting_date'] . " 🕐 " . substr($detail['meeting_time'],0,5) . "\n";
                            if ($detail['location']) $wa_text .= "📍 " . $detail['location'] . "\n";
                            if ($wa_agenda) {
                                $wa_text .= "\n📋 جدول الأعمال:\n";
                                foreach ($wa_agenda as $i => $item) { $wa_text .= ($i+1) . ". " . $item . "\n"; }
                            }
                            $wa_text .= "\nنرجو الحضور في الموعد. شكرا";
                            $wa_encoded = urlencode($wa_text);
                            ?>
                            <button onclick="sendWhatsAppAll()" style="background:#25D366;color:#fff;padding:7px 14px;border:none;border-radius:8px;font-size:12px;cursor:pointer;">📱 واتساب الكل</button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="detail-meta">
                        <span>📅 <?= $detail['meeting_date'] ?></span>
                        <span>🕐 <?= substr($detail['meeting_time'], 0, 5) ?></span>
                        <span>📍 <?= htmlspecialchars($detail['location'] ?: '—') ?></span>
                        <span>🏛️ <?= htmlspecialchars($detail['committee'] ?: '—') ?></span>
                        <span>📞 <?= htmlspecialchars($detail['called_by'] ?: '—') ?></span>
                    </div>

                    <!-- Agenda -->
                    <div class="section-title">📋 جدول الأعمال</div>
                    <?php if ($detail_agenda): ?>
                        <ol class="agenda-list"><?php foreach ($detail_agenda as $item): ?>
                                <li><?= htmlspecialchars($item) ?></li><?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p style="color:#999;">لم يتم تحديد جدول الأعمال بعد</p><?php endif; ?>

                    <?php if ($detail['notes']): ?>
                        <div class="section-title">📝 ملاحظات</div>
                        <p style="background:#fff8e1;padding:12px;border-radius:8px;">
                            <?= nl2br(htmlspecialchars($detail['notes'])) ?></p>
                    <?php endif; ?>

                    <!-- Decisions -->
                    <?php if ($detail_decisions): ?>
                        <div class="section-title">📌 القرارات</div>
                        <ol class="agenda-list"><?php foreach ($detail_decisions as $d): ?>
                                <li style="background:#e8f5e9;border-right-color:#28a745;"><?= htmlspecialchars($d) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>

                <!-- Attendees -->
                <div class="detail-panel">
                    <div class="section-title">👥 المدعوون / الحاضرون</div>
                    <?php if ($detail_attendees): ?>
                        <div style="overflow-x:auto;">
                            <table class="att-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>الاسم</th>
                                        <th>الصفة</th>
                                        <th>القسم</th>
                                        <th>الحضور</th>
                                        <?php if ($is_admin): ?>
                                        <th>استدعاء</th>
                                        <th>إجراء</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detail_attendees as $i => $a):
                                        // Get user contact info
                                        $u_info = $pdo->prepare("SELECT cin, email, phone, whatsapp FROM users WHERE name = ? LIMIT 1");
                                        $u_info->execute([$a['name']]); $u_contact = $u_info->fetch();
                                        $has_email = !empty($u_contact['email']);
                                        $has_phone = !empty($u_contact['whatsapp'] ?: $u_contact['phone']);
                                        $has_cin = !empty($u_contact['cin']);
                                        $phone_raw = $u_contact['whatsapp'] ?? $u_contact['phone'] ?? '';
                                        $clean_phone = preg_replace('/[^0-9]/', '', $phone_raw);
                                        if (substr($clean_phone, 0, 1) === '0') $clean_phone = '212' . substr($clean_phone, 1);
                                    ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><strong><?= htmlspecialchars($a['name']) ?></strong></td>
                                            <td><?= htmlspecialchars($a['role_title'] ?: '—') ?></td>
                                            <td><?= htmlspecialchars($a['department'] ?: '—') ?></td>
                                            <td><?= $a['attended'] === null ? '—' : ($a['attended'] ? '✅' : '❌') ?></td>
                                            <?php if ($is_admin): ?>
                                            <td style="white-space:nowrap;">
                                                <?php if ($has_email): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="meeting_id" value="<?= $detail['id'] ?>">
                                                        <input type="hidden" name="invite_target" value="<?= htmlspecialchars($a['name']) ?>">
                                                        <button type="submit" name="send_invite_email" title="بريد إلكتروني" style="padding:2px 6px;background:#d93025;color:#fff;border:none;border-radius:4px;font-size:11px;cursor:pointer;">📧</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($has_phone): ?>
                                                    <a href="https://wa.me/<?= $clean_phone ?>?text=<?= $wa_encoded ?>" target="_blank" title="واتساب" style="padding:2px 6px;background:#25D366;color:#fff;border:none;border-radius:4px;font-size:11px;text-decoration:none;display:inline-block;">📱</a>
                                                <?php endif; ?>
                                                <?php if ($has_cin): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="meeting_id" value="<?= $detail['id'] ?>">
                                                        <input type="hidden" name="invite_target" value="<?= htmlspecialchars($a['name']) ?>">
                                                        <button type="submit" name="send_invite_app" title="إشعار عبر التطبيق" style="padding:2px 6px;background:#ff6f00;color:#fff;border:none;border-radius:4px;font-size:11px;cursor:pointer;">🔔</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (!$has_email && !$has_phone && !$has_cin): ?>
                                                    <span style="color:#999;font-size:11px;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST" style="display:inline;"
                                                    onsubmit="return confirm('حذف هذا الحاضر؟')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="attendee_id" value="<?= $a['id'] ?>">
                                                    <button type="submit" name="remove_attendee" class="btn btn-red"
                                                        style="padding:3px 8px;font-size:11px;">🗑️</button>
                                                </form>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mark Attendance -->
                        <?php if ($is_admin): ?>
                        <form method="POST" style="margin-top:15px;background:#f0f7ff;padding:15px;border-radius:8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="meeting_id" value="<?= $detail['id'] ?>">
                            <strong>تسجيل الحضور:</strong>
                            <div style="display:flex;flex-wrap:wrap;gap:10px;margin:10px 0;">
                                <?php foreach ($detail_attendees as $a): ?>
                                    <label
                                        style="display:flex;align-items:center;gap:5px;font-size:13px;background:#fff;padding:5px 10px;border-radius:6px;">
                                        <input type="checkbox" name="attended_<?= $a['id'] ?>" <?= $a['attended'] ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($a['name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" name="mark_attendance"
                                style="background:#28a745;padding:8px 20px;border-radius:8px;">✅ حفظ الحضور</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($is_admin): ?>
                    <!-- Add Attendees (ISO) -->
                    <div class="tab-buttons" style="margin-top:15px;">
                        <button type="button" class="tab-btn active" onclick="switchAttTab('system',this)">👤 اختيار من النظام</button>
                        <button type="button" class="tab-btn" onclick="switchAttTab('manual',this)">✏️ إضافة خارجي</button>
                    </div>

                    <!-- System Users Checkbox Grid -->
                    <form method="POST" id="att_system" style="background:#f8f9fa;padding:15px;border-radius:0 0 8px 8px;border:1px solid #ddd;border-top:none;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="meeting_id" value="<?= $detail['id'] ?>">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                            <strong>👥 اختر الحاضرين من قائمة الموظفين: <span class="iso-badge">ISO</span></strong>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <select name="bulk_role" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:12px;">
                                    <?php foreach ($role_titles as $rt): ?>
                                        <option value="<?= htmlspecialchars($rt) ?>" <?= $rt==='عضو'?'selected':'' ?>><?= htmlspecialchars($rt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" onclick="selectAllUsers()" style="padding:5px 10px;background:#6c757d;color:#fff;border:none;border-radius:6px;font-size:11px;cursor:pointer;">تحديد الكل</button>
                                <button type="button" onclick="deselectAllUsers()" style="padding:5px 10px;background:#adb5bd;color:#fff;border:none;border-radius:6px;font-size:11px;cursor:pointer;">إلغاء الكل</button>
                            </div>
                        </div>
                        <?php
                        // Group users by department
                        $existing_names = array_column($detail_attendees, 'name');
                        $users_by_dept = [];
                        foreach ($all_users as $u) { $d = $u['department'] ?? 'بدون قسم'; $users_by_dept[$d][] = $u; }
                        ksort($users_by_dept);
                        ?>
                        <?php foreach ($users_by_dept as $dept_name => $dept_users): ?>
                            <div style="margin-bottom:10px;">
                                <div style="font-weight:bold;font-size:13px;color:#1a237e;margin-bottom:5px;padding:5px 10px;background:#e8eaf6;border-radius:6px;display:flex;justify-content:space-between;align-items:center;">
                                    <span>🏢 <?= htmlspecialchars($dept_name) ?> (<?= count($dept_users) ?>)</span>
                                    <button type="button" onclick="toggleDept(this)" style="padding:2px 8px;background:#5c6bc0;color:#fff;border:none;border-radius:4px;font-size:10px;cursor:pointer;">تحديد القسم</button>
                                </div>
                                <div class="agenda-check-grid dept-grid">
                                    <?php foreach ($dept_users as $u):
                                        $already = in_array($u['name'], $existing_names);
                                    ?>
                                        <label class="agenda-check-item" style="<?= $already ? 'opacity:.5;background:#e8f5e9;' : '' ?>">
                                            <input type="checkbox" name="selected_users[]" value="<?= htmlspecialchars($u['cin']) ?>" <?= $already ? 'checked disabled' : '' ?>>
                                            <?= htmlspecialchars($u['name']) ?>
                                            <span style="font-size:10px;color:#888;margin-right:auto;"><?= htmlspecialchars($u['role'] ?? '') ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" name="add_attendees_bulk" style="background:#28a745;padding:10px 25px;border-radius:8px;margin-top:10px;font-weight:bold;">✅ إضافة المحددين</button>
                    </form>

                    <!-- Manual (External) -->
                    <form method="POST" id="att_manual" style="display:none;background:#f8f9fa;padding:15px;border-radius:0 0 8px 8px;border:1px solid #ddd;border-top:none;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="meeting_id" value="<?= $detail['id'] ?>">
                        <strong>✏️ إضافة شخص خارجي:</strong>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                            <input type="text" name="att_name" placeholder="الاسم الكامل" required style="flex:2;min-width:120px;padding:8px;border:1px solid #ddd;border-radius:6px;">
                            <select name="att_role" style="flex:1;min-width:100px;padding:8px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">— الصفة —</option>
                                <?php foreach ($role_titles as $rt): ?>
                                    <option value="<?= htmlspecialchars($rt) ?>"><?= htmlspecialchars($rt) ?></option>
                                <?php endforeach; ?>
                                <option value="مستشار خارجي">مستشار خارجي</option>
                                <option value="مندوب">مندوب</option>
                            </select>
                            <input type="text" name="att_dept" placeholder="الجهة / المؤسسة" style="flex:1;min-width:100px;padding:8px;border:1px solid #ddd;border-radius:6px;">
                            <button type="submit" name="add_attendee_manual" style="background:#007bff;padding:8px 15px;border-radius:6px;white-space:nowrap;">➕ إضافة</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                <!-- Edit Meeting (ISO) -->
                <div class="detail-panel">
                    <div class="section-title">✏️ تعديل الاجتماع <span class="iso-badge">ISO</span></div>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="meeting_id" value="<?= $detail['id'] ?>">
                        <div class="form-grid">
                            <div class="form-group2"><label>نوع الاجتماع</label>
                                <select name="title" onchange="toggleOther(this,'title_other_edit')">
                                    <?php foreach ($meeting_types as $k => $v): ?>
                                        <option value="<?= htmlspecialchars($k) ?>" <?= $detail['title'] == $k ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($v) ?></option>
                                    <?php endforeach; ?>
                                    <?php if (!isset($meeting_types[$detail['title']])): ?>
                                        <option value="<?= htmlspecialchars($detail['title']) ?>" selected>
                                            <?= htmlspecialchars($detail['title']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <input type="text" name="title_other" id="title_other_edit" class="other-field"
                                    placeholder="حدد نوع الاجتماع...">
                            </div>
                            <div class="form-group2"><label>اللجنة</label>
                                <select name="committee" onchange="toggleOther(this,'comm_other_edit')">
                                    <option value="">— بدون لجنة —</option>
                                    <?php foreach ($committees as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>" <?= $detail['committee'] == $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($detail['committee'] && !in_array($detail['committee'], $committees)): ?>
                                        <option value="<?= htmlspecialchars($detail['committee']) ?>" selected>
                                            <?= htmlspecialchars($detail['committee']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <input type="text" name="committee_other" id="comm_other_edit" class="other-field"
                                    placeholder="حدد اللجنة...">
                            </div>
                            <div class="form-group2"><label>التاريخ</label><input type="date" name="meeting_date"
                                    value="<?= $detail['meeting_date'] ?>" required></div>
                            <div class="form-group2"><label>الوقت</label><input type="time" name="meeting_time"
                                    value="<?= substr($detail['meeting_time'], 0, 5) ?>" required></div>
                            <div class="form-group2"><label>المكان</label>
                                <select name="location" onchange="toggleOther(this,'loc_other_edit')">
                                    <option value="">— اختر المكان —</option>
                                    <?php foreach ($meeting_locations as $l): ?>
                                        <option value="<?= htmlspecialchars($l) ?>" <?= $detail['location'] == $l ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($l) ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($detail['location'] && !in_array($detail['location'], $meeting_locations)): ?>
                                        <option value="<?= htmlspecialchars($detail['location']) ?>" selected>
                                            <?= htmlspecialchars($detail['location']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <input type="text" name="location_other" id="loc_other_edit" class="other-field"
                                    placeholder="حدد المكان...">
                            </div>
                            <div class="form-group2"><label>الداعي للاجتماع</label>
                                <select name="called_by">
                                    <option value="">— اختر —</option>
                                    <?php foreach ($all_users as $u): ?>
                                        <option value="<?= htmlspecialchars($u['name']) ?>"
                                            <?= $detail['called_by'] == $u['name'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u['name']) ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($detail['called_by'] && !in_array($detail['called_by'], array_column($all_users, 'name'))): ?>
                                        <option value="<?= htmlspecialchars($detail['called_by']) ?>" selected>
                                            <?= htmlspecialchars($detail['called_by']) ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="form-group2"><label>الحالة</label>
                                <select name="status">
                                    <option value="planned" <?= $detail['status'] == 'planned' ? 'selected' : '' ?>>📅 مخطط
                                    </option>
                                    <option value="completed" <?= $detail['status'] == 'completed' ? 'selected' : '' ?>>✅ منعقد
                                    </option>
                                    <option value="cancelled" <?= $detail['status'] == 'cancelled' ? 'selected' : '' ?>>❌ ملغى
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:15px;">📋 جدول الأعمال <span
                                class="iso-badge">ISO</span></div>
                        <div class="agenda-check-grid">
                            <?php foreach ($predefined_agenda as $pa): ?>
                                <label class="agenda-check-item">
                                    <input type="checkbox" name="agenda_items[]" value="<?= htmlspecialchars($pa) ?>"
                                        <?= in_array($pa, $detail_agenda) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($pa) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php $custom_items = array_diff($detail_agenda, $predefined_agenda); ?>
                        <div class="form-group2" style="margin-top:8px;">
                            <label>نقاط إضافية (سطر لكل نقطة)</label>
                            <textarea name="agenda_custom" rows="2"
                                placeholder="نقطة مخصصة لا توجد بالقائمة أعلاه..."><?= htmlspecialchars(implode("\n", $custom_items)) ?></textarea>
                        </div>

                        <div class="form-grid full" style="margin-top:12px;">
                            <div class="form-group2"><label>القرارات (سطر لكل قرار)</label><textarea name="decisions_text"
                                    rows="3"><?= htmlspecialchars(implode("\n", $detail_decisions)) ?></textarea></div>
                            <div class="form-group2"><label>ملاحظات</label><textarea name="notes"
                                    rows="3"><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea></div>
                        </div>
                        <div style="display:flex;gap:10px;margin-top:15px;">
                            <button type="submit" name="update_meeting"
                                style="background:#007bff;padding:10px 25px;border-radius:8px;">💾 حفظ التعديلات</button>
                            <button type="submit" name="delete_meeting"
                                style="background:#dc3545;padding:10px 25px;border-radius:8px;"
                                onclick="return confirm('هل تريد حذف هذا الاجتماع نهائياً؟')">🗑️ حذف</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- ═══ LIST VIEW ═══ -->
                <div class="header">
                    <h2>🗓️ إدارة الاجتماعات <span style="font-size:.6em;color:#666;">Meeting Management <span
                                class="iso-badge">ISO</span></span></h2>
                </div>

                <!-- Filter -->
                <div style="display:flex;gap:10px;margin-bottom:15px;flex-wrap:wrap;align-items:center;">
                    <form method="GET" style="display:flex;gap:8px;align-items:center;">
                        <select name="filter_status" style="padding:8px;border:1px solid #ddd;border-radius:8px;">
                            <option value="">الكل</option>
                            <option value="planned" <?= $filter_status == 'planned' ? 'selected' : '' ?>>📅 مخطط</option>
                            <option value="completed" <?= $filter_status == 'completed' ? 'selected' : '' ?>>✅ منعقد</option>
                            <option value="cancelled" <?= $filter_status == 'cancelled' ? 'selected' : '' ?>>❌ ملغى</option>
                        </select>
                        <button type="submit" style="background:#007bff;padding:8px 15px;border-radius:8px;width:auto;">🔍
                            تصفية</button>
                        <a href="meetings.php"
                            style="padding:8px 15px;background:#6c757d;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;">إعادة</a>
                    </form>
                    <?php if ($is_admin): ?>
                    <button
                        onclick="document.getElementById('newMeetingForm').style.display=document.getElementById('newMeetingForm').style.display==='none'?'block':'none'"
                        style="background:#28a745;padding:10px 20px;border-radius:8px;width:auto;">➕ اجتماع جديد</button>
                    <?php endif; ?>
                </div>

                <?php if ($is_admin): ?>
                <!-- New Meeting Form (ISO) -->
                <div id="newMeetingForm"
                    style="display:none;background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.1);margin-bottom:20px;">
                    <h3 style="margin-top:0;color:#1a237e;">➕ إنشاء اجتماع جديد <span class="iso-badge">ISO</span></h3>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <div class="form-grid">
                            <div class="form-group2"><label>نوع الاجتماع *</label>
                                <select name="title" required onchange="toggleOther(this,'title_other_new')">
                                    <option value="">— اختر نوع الاجتماع —</option>
                                    <?php foreach ($meeting_types as $k => $v): ?>
                                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="title_other" id="title_other_new" class="other-field"
                                    placeholder="حدد نوع الاجتماع...">
                            </div>
                            <div class="form-group2"><label>اللجنة</label>
                                <select name="committee" onchange="toggleOther(this,'comm_other_new')">
                                    <option value="">— بدون لجنة —</option>
                                    <?php foreach ($committees as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="committee_other" id="comm_other_new" class="other-field"
                                    placeholder="حدد اللجنة...">
                            </div>
                            <div class="form-group2"><label>التاريخ *</label><input type="date" name="meeting_date"
                                    required></div>
                            <div class="form-group2"><label>الوقت *</label><input type="time" name="meeting_time" required>
                            </div>
                            <div class="form-group2"><label>المكان</label>
                                <select name="location" onchange="toggleOther(this,'loc_other_new')">
                                    <option value="">— اختر المكان —</option>
                                    <?php foreach ($meeting_locations as $l): ?>
                                        <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="location_other" id="loc_other_new" class="other-field"
                                    placeholder="حدد المكان...">
                            </div>
                            <div class="form-group2"><label>الداعي للاجتماع</label>
                                <select name="called_by">
                                    <option value="">— اختر —</option>
                                    <?php foreach ($all_users as $u): ?>
                                        <option value="<?= htmlspecialchars($u['name']) ?>"><?= htmlspecialchars($u['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:15px;">📋 جدول الأعمال <span
                                class="iso-badge">ISO</span></div>
                        <div class="agenda-check-grid">
                            <?php foreach ($predefined_agenda as $pa): ?>
                                <label class="agenda-check-item">
                                    <input type="checkbox" name="agenda_items[]" value="<?= htmlspecialchars($pa) ?>">
                                    <?= htmlspecialchars($pa) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-group2" style="margin-top:8px;">
                            <label>نقاط إضافية (سطر لكل نقطة)</label>
                            <textarea name="agenda_custom" rows="2"
                                placeholder="نقطة مخصصة لا توجد بالقائمة أعلاه..."></textarea>
                        </div>

                        <div class="form-grid full" style="margin-top:12px;">
                            <div class="form-group2"><label>ملاحظات</label><textarea name="notes" rows="2"></textarea></div>
                        </div>
                        <button type="submit" name="create_meeting"
                            style="background:#28a745;padding:10px 25px;border-radius:8px;margin-top:15px;">✅ إنشاء
                            الاجتماع</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Meetings List -->
                <?php if (empty($meetings)): ?>
                    <div style="text-align:center;padding:50px;color:#999;background:#fff;border-radius:12px;">
                        <h3>لا توجد اجتماعات حالياً</h3>
                        <p>أنشئ اجتماعاً جديداً للبدء</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($meetings as $m):
                        $ag = json_decode($m['agenda_items'] ?: '[]', true) ?: [];
                        $ac = $pdo->prepare("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = ?");
                        $ac->execute([$m['id']]);
                        $att_count = $ac->fetchColumn();
                        ?>
                        <a href="?id=<?= $m['id'] ?>" style="text-decoration:none;color:inherit;">
                            <div class="meeting-card status-<?= $m['status'] ?>">
                                <div class="mc-info">
                                    <h4><?= htmlspecialchars($m['title']) ?></h4>
                                    <p>
                                        📅 <?= $m['meeting_date'] ?> &nbsp; 🕐 <?= substr($m['meeting_time'], 0, 5) ?>
                                        <?php if ($m['committee']): ?>&nbsp; 🏛️
                                            <?= htmlspecialchars($m['committee']) ?>            <?php endif; ?>
                                        <?php if ($m['location']): ?>&nbsp; 📍 <?= htmlspecialchars($m['location']) ?><?php endif; ?>
                                        &nbsp; 👥 <?= $att_count ?> مدعو
                                        &nbsp; 📋 <?= count($ag) ?> نقاط
                                    </p>
                                </div>
                                <div class="mc-meta">
                                    <span class="status-badge"
                                        style="background:<?= $status_colors[$m['status']] ?>"><?= $status_labels[$m['status']] ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Toggle "other" field when "أخرى" is selected
        function toggleOther(sel, otherId) {
            var el = document.getElementById(otherId);
            if (sel.value === 'أخرى') { el.classList.add('show'); el.focus(); }
            else { el.classList.remove('show'); el.value = ''; }
        }

        // Switch attendee tab (system vs manual)
        function switchAttTab(mode, btn) {
            document.getElementById('att_system').style.display = mode === 'system' ? 'block' : 'none';
            document.getElementById('att_manual').style.display = mode === 'manual' ? 'block' : 'none';
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }

        // Select/Deselect all users
        function selectAllUsers() {
            document.querySelectorAll('#att_system input[type="checkbox"]:not(:disabled)').forEach(c => c.checked = true);
        }
        function deselectAllUsers() {
            document.querySelectorAll('#att_system input[type="checkbox"]:not(:disabled)').forEach(c => c.checked = false);
        }

        // Toggle all checkboxes in a department
        function toggleDept(btn) {
            var grid = btn.closest('div').nextElementSibling;
            var boxes = grid.querySelectorAll('input[type="checkbox"]:not(:disabled)');
            var allChecked = Array.from(boxes).every(c => c.checked);
            boxes.forEach(c => c.checked = !allChecked);
        }

        // Send WhatsApp to all attendees
        function sendWhatsAppAll() {
            var links = document.querySelectorAll('.att-table a[href*="wa.me"]');
            if (links.length === 0) { alert('لا يوجد أرقام واتساب للمدعوين'); return; }
            if (!confirm('سيتم فتح ' + links.length + ' محادثة واتساب. متابعة؟')) return;
            links.forEach(function(link, i) {
                setTimeout(function() { window.open(link.href, '_blank'); }, i * 1500);
            });
        }
    </script>
</body>

</html>
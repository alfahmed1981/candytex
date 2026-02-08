<?php
session_start();
require 'db.php';
require 'includes/auth.php';

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
$pdo->exec("CREATE TABLE IF NOT EXISTS `iso_documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `doc_number` VARCHAR(30) NOT NULL UNIQUE,
    `title_en` VARCHAR(255) NOT NULL,
    `title_ar` VARCHAR(255) DEFAULT NULL,
    `category` VARCHAR(50) DEFAULT 'SOP',
    `doc_type` VARCHAR(50) DEFAULT 'Quality',
    `department` VARCHAR(100) DEFAULT NULL,
    `current_revision` VARCHAR(10) DEFAULT '1.0',
    `status` VARCHAR(30) DEFAULT 'Draft',
    `owner` VARCHAR(100) DEFAULT NULL,
    `next_review_date` DATE DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `created_by` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `doc_revisions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `doc_id` INT NOT NULL,
    `revision` VARCHAR(10) NOT NULL,
    `change_description` TEXT DEFAULT NULL,
    `approved_by` VARCHAR(100) DEFAULT NULL,
    `effective_date` DATE DEFAULT NULL,
    `created_by` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ═══════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════
function doc_next_number($pdo)
{
    $year = date('Y');
    $pattern = "DOC-$year-%";
    $stmt = $pdo->prepare("SELECT doc_number FROM iso_documents WHERE doc_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $last = $stmt->fetchColumn();
    if ($last) {
        $num = intval(substr($last, -3)) + 1;
    } else {
        $num = 1;
    }
    return "DOC-$year-" . str_pad($num, 3, '0', STR_PAD_LEFT);
}

$msg = '';

// ═══════════════════════════════════════════════════
// POST ACTIONS
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // CREATE DOCUMENT
    if (isset($_POST['create_doc'])) {
        $doc_num = doc_next_number($pdo);
        $title_en = trim($_POST['title_en'] ?? '');
        $title_ar = trim($_POST['title_ar'] ?? '');

        if (empty($title_en)) {
            $msg = "⚠️ يرجى إدخال عنوان الوثيقة";
        } else {
            $stmt = $pdo->prepare("INSERT INTO iso_documents
                (doc_number, title_en, title_ar, category, doc_type, department,
                 current_revision, status, owner, next_review_date, description, created_by)
                VALUES (?, ?, ?, ?, ?, ?, '1.0', 'Draft', ?, ?, ?, ?)");
            $stmt->execute([
                $doc_num,
                $title_en,
                $title_ar ?: null,
                trim($_POST['category'] ?? 'SOP'),
                trim($_POST['doc_type'] ?? 'Quality'),
                trim($_POST['department'] ?? ''),
                trim($_POST['owner'] ?? ''),
                $_POST['next_review_date'] ?: null,
                trim($_POST['description'] ?? '') ?: null,
                $user_cin
            ]);

            // Create initial revision entry
            $doc_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO doc_revisions (doc_id, revision, change_description, approved_by, effective_date, created_by)
                VALUES (?, '1.0', 'Initial release / إصدار أولي', ?, CURDATE(), ?)")
                ->execute([$doc_id, $user_name, $user_cin]);

            $msg = "✅ تم إنشاء الوثيقة: $doc_num";
            audit_log($pdo, 'doc_create', "Created Document: $doc_num — $title_en");
        }
    }

    // UPDATE STATUS
    if (isset($_POST['update_doc_status'])) {
        $did = intval($_POST['doc_id']);
        $new_status = trim($_POST['new_status']);
        $pdo->prepare("UPDATE iso_documents SET status = ? WHERE id = ?")->execute([$new_status, $did]);
        $msg = "✅ تم تحديث الحالة إلى: $new_status";
        audit_log($pdo, 'doc_status', "Document #$did → $new_status");
    }

    // ADD REVISION
    if (isset($_POST['add_revision'])) {
        $did = intval($_POST['doc_id']);
        $new_rev = trim($_POST['new_revision']);
        $change_desc = trim($_POST['change_description'] ?? '');
        $approved_by = trim($_POST['approved_by'] ?? '');
        $eff_date = $_POST['effective_date'] ?: date('Y-m-d');

        $pdo->prepare("INSERT INTO doc_revisions (doc_id, revision, change_description, approved_by, effective_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$did, $new_rev, $change_desc, $approved_by, $eff_date, $user_cin]);

        $pdo->prepare("UPDATE iso_documents SET current_revision = ?, status = 'Active' WHERE id = ?")
            ->execute([$new_rev, $did]);

        $msg = "✅ تمت إضافة المراجعة: $new_rev";
        audit_log($pdo, 'doc_revision', "Document #$did revised to $new_rev");
    }

    // DELETE DOCUMENT
    if (isset($_POST['delete_doc']) && $is_admin) {
        $did = intval($_POST['doc_id']);
        $pdo->prepare("DELETE FROM doc_revisions WHERE doc_id = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM iso_documents WHERE id = ?")->execute([$did]);
        $msg = "🗑️ تم حذف الوثيقة";
        audit_log($pdo, 'doc_delete', "Deleted Document #$did");
    }
}

// ═══════════════════════════════════════════════════
// DATA FETCH
// ═══════════════════════════════════════════════════
$docs = $pdo->query("SELECT d.*, u.name as creator_name
    FROM iso_documents d
    LEFT JOIN users u ON d.created_by = u.cin
    ORDER BY d.doc_number DESC")->fetchAll();

$revisions_all = $pdo->query("SELECT * FROM doc_revisions ORDER BY created_at DESC")->fetchAll();

// Stats
$total = count($docs);
$active = count(array_filter($docs, fn($d) => $d['status'] === 'Active'));
$draft = count(array_filter($docs, fn($d) => $d['status'] === 'Draft'));
$review = count(array_filter($docs, fn($d) => $d['status'] === 'Under Review'));
$obsolete = count(array_filter($docs, fn($d) => $d['status'] === 'Obsolete'));

// Overdue for review (review date passed)
$today = date('Y-m-d');
$overdue_docs = array_filter($docs, function ($d) use ($today) {
    return $d['next_review_date'] && $d['next_review_date'] < $today
        && !in_array($d['status'], ['Obsolete']);
});

// Category distribution
$cat_counts = [];
foreach ($docs as $d) {
    $cat = $d['category'] ?? 'Other';
    $cat_counts[$cat] = ($cat_counts[$cat] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحكم بالوثائق — Document Control | CANDYTEX ISO 9001</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .doc-cards {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .doc-card {
            flex: 1;
            min-width: 140px;
            padding: 18px;
            border-radius: 12px;
            text-align: center;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .15);
        }

        .doc-card h2 {
            font-size: 2.2em;
            margin: 0;
        }

        .doc-card p {
            margin: 5px 0 0;
            font-size: .85em;
            opacity: .9;
        }

        .dc-total {
            background: linear-gradient(135deg, #2c3e50, #34495e);
        }

        .dc-active {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
        }

        .dc-review {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }

        .dc-obsolete {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }

        .filter-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            background: #f8f9fa;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .filter-bar select,
        .filter-bar input {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: .85em;
        }

        .iso-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85em;
        }

        .iso-table th {
            background: #1a237e;
            color: #fff;
            padding: 10px 8px;
            text-align: center;
            font-size: .8em;
        }

        .iso-table td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }

        .iso-table tr:hover {
            background: #f5f6fa;
        }

        .badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: .75em;
            font-weight: 600;
            display: inline-block;
        }

        .b-draft {
            background: #d5d8dc;
            color: #2c3e50;
        }

        .b-active {
            background: #27ae60;
            color: #fff;
        }

        .b-review {
            background: #f39c12;
            color: #fff;
        }

        .b-obsolete {
            background: #95a5a6;
            color: #fff;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, .5);
            z-index: 999;
            justify-content: center;
            align-items: flex-start;
            padding-top: 50px;
            overflow-y: auto;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: #fff;
            border-radius: 12px;
            padding: 30px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .3);
            margin-bottom: 50px;
        }

        .modal-box h2 {
            margin: 0 0 20px;
            color: #1a237e;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: .85em;
            color: #444;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: .9em;
        }

        .modal-btns {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 15px;
        }

        .btn-cancel {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background: #eee;
            color: #333;
        }

        .btn-submit {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background: #1a237e;
            color: #fff;
            font-weight: 600;
        }

        .btn-add {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            background: #1a237e;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: .9em;
        }

        .btn-save {
            padding: 3px 8px;
            border: none;
            border-radius: 4px;
            background: #27ae60;
            color: #fff;
            cursor: pointer;
            font-size: .8em;
        }

        .revision-box {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            margin: 5px 0;
            font-size: .8em;
            border-left: 3px solid #667eea;
        }

        .cat-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: .75em;
            font-weight: 600;
        }

        .cat-sop {
            background: #e8eaf6;
            color: #1a237e;
        }

        .cat-wi {
            background: #e3f2fd;
            color: #0d47a1;
        }

        .cat-policy {
            background: #fce4ec;
            color: #b71c1c;
        }

        .cat-form {
            background: #e8f5e9;
            color: #1b5e20;
        }

        .cat-record {
            background: #fff3e0;
            color: #e65100;
        }

        .cat-external {
            background: #f3e5f5;
            color: #4a148c;
        }

        .cat-manual {
            background: #efebe9;
            color: #3e2723;
        }

        @media print {
            .no-print,
            .top-nav,
            .sidebar,
            .filter-bar,
            .btn-add {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
            }

            .print-header {
                display: flex !important;
                justify-content: space-between;
                align-items: center;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }

            .print-footer {
                text-align: center;
                font-size: 8pt;
                color: #888;
                border-top: 1px solid #ccc;
                padding-top: 8px;
                margin-top: 20px;
            }
        }
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>
    <div class="main-content">
        <div class="header">
            <h2>📄 التحكم بالوثائق <span style="font-size:.55em;color:#666">Document Control — ISO 9001:2015 §7.5</span></h2>
        </div>
        <?php if ($msg): ?>
            <div
                style="background:#d4edda;color:#155724;padding:12px 18px;border-radius:8px;margin-bottom:15px;font-weight:600">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($overdue_docs)): ?>
        <div style="background:#fff3cd;color:#856404;padding:12px 18px;border-radius:8px;margin-bottom:15px;border-left:4px solid #ffc107">
            ⚠️ <strong><?= count($overdue_docs) ?> وثائق تجاوزت موعد المراجعة!</strong>
            <?php foreach ($overdue_docs as $od): ?>
                <span style="display:inline-block;background:#ffeeba;padding:3px 10px;border-radius:5px;margin:3px;font-size:.85em">
                    <strong><?= $od['doc_number'] ?></strong> — <?= date('d/m/Y', strtotime($od['next_review_date'])) ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Dashboard Cards -->
        <div class="doc-cards">
            <div class="doc-card dc-total">
                <h2><?= $total ?></h2>
                <p>إجمالي الوثائق</p>
            </div>
            <div class="doc-card dc-active">
                <h2><?= $active ?></h2>
                <p>نشطة Active</p>
            </div>
            <div class="doc-card dc-review">
                <h2><?= $review ?></h2>
                <p>قيد المراجعة</p>
            </div>
            <div class="doc-card dc-obsolete">
                <h2><?= $obsolete ?></h2>
                <p>ملغاة Obsolete</p>
            </div>
        </div>

        <!-- Action Bar -->
        <div
            style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px">
            <button class="btn-add" onclick="openModal('doc-modal')">➕ إضافة وثيقة جديدة</button>
            <button class="btn-add" style="background:#17a2b8" onclick="window.print()">🖨️ طباعة السجل</button>
            <span id="filter-count" style="font-weight:600;color:#555"></span>
        </div>

        <!-- Filters -->
        <div class="filter-bar no-print">
            <select id="f-category" onchange="filterDocs()">
                <option value="">كل الفئات</option>
                <option>SOP</option>
                <option>Work Instruction</option>
                <option>Policy</option>
                <option>Form/Template</option>
                <option>Record</option>
                <option>External</option>
                <option>Manual</option>
            </select>
            <select id="f-type" onchange="filterDocs()">
                <option value="">كل الأنواع</option>
                <option>Quality</option>
                <option>Production</option>
                <option>Maintenance</option>
                <option>Safety</option>
                <option>HR</option>
                <option>Purchasing</option>
                <option>General</option>
            </select>
            <select id="f-status" onchange="filterDocs()">
                <option value="">كل الحالات</option>
                <option>Draft</option>
                <option>Under Review</option>
                <option>Active</option>
                <option>Obsolete</option>
            </select>
            <input type="text" id="f-search" onkeyup="filterDocs()" placeholder="🔍 بحث...">
            <button onclick="resetFilters()" style="background:#dc3545;color:#fff;border:none;padding:6px 12px;border-radius:6px;cursor:pointer">🔄 إعادة</button>
        </div>

        <!-- Print Header -->
        <div class="print-header" style="display:none">
            <div class="print-logo">🏭 CANDYTEX S.A.R.L<br>
                <small style="font-size:10pt;font-weight:normal">Excellence in Textiles</small>
            </div>
            <div style="text-align:center">
                <h2 style="margin:0">DOCUMENT CONTROL REGISTER</h2>
                <p style="margin:5px 0">سجل التحكم بالوثائق</p>
                <b>Date: <?= date('Y-m-d') ?></b>
            </div>
            <div class="doc-info">
                <b>Ref:</b> OP-DOC-001<br>
                <b>Rev:</b> 1.0 (2026)<br>
                <b>Type:</b> Confidential
            </div>
        </div>

        <!-- Document Register Table -->
        <div style="overflow-x:auto">
            <table class="iso-table" id="doc-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الرقم</th>
                        <th>العنوان</th>
                        <th>الفئة</th>
                        <th>النوع</th>
                        <th>القسم</th>
                        <th>المراجعة</th>
                        <th>الحالة</th>
                        <th>المالك</th>
                        <th>موعد المراجعة</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($docs)): ?>
                        <tr>
                            <td colspan="11" style="padding:30px;color:#888;font-size:1.1em">لا توجد وثائق مسجلة — اضغط ➕
                                لإضافة أول وثيقة</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($docs as $i => $d):
                            $st_cls = match ($d['status']) { 'Draft' => 'b-draft', 'Under Review' => 'b-review', 'Active' => 'b-active', 'Obsolete' => 'b-obsolete', default => 'b-draft'};
                            $cat_cls = match ($d['category']) { 'SOP' => 'cat-sop', 'Work Instruction' => 'cat-wi', 'Policy' => 'cat-policy', 'Form/Template' => 'cat-form', 'Record' => 'cat-record', 'External' => 'cat-external', 'Manual' => 'cat-manual', default => 'cat-sop'};
                            $doc_revisions = array_filter($revisions_all, fn($rv) => $rv['doc_id'] == $d['id']);
                            $is_overdue = $d['next_review_date'] && $d['next_review_date'] < $today && $d['status'] !== 'Obsolete';
                            ?>
                            <tr data-category="<?= htmlspecialchars($d['category']) ?>"
                                data-type="<?= htmlspecialchars($d['doc_type']) ?>"
                                data-status="<?= $d['status'] ?>">
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($d['doc_number']) ?></strong></td>
                                <td style="text-align:left;max-width:200px">
                                    <?= htmlspecialchars($d['title_en'] ?: '-') ?>
                                    <?php if ($d['title_ar']): ?>
                                        <br><small style="color:#888"><?= htmlspecialchars($d['title_ar']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="cat-badge <?= $cat_cls ?>"><?= $d['category'] ?></span></td>
                                <td style="font-size:.8em"><?= htmlspecialchars($d['doc_type']) ?></td>
                                <td style="font-size:.8em"><?= htmlspecialchars($d['department'] ?: '-') ?></td>
                                <td><strong>Rev <?= $d['current_revision'] ?></strong></td>
                                <td><span class="badge <?= $st_cls ?>"><?= $d['status'] ?></span></td>
                                <td style="font-size:.75em"><?= htmlspecialchars($d['owner'] ?: '-') ?></td>
                                <td style="font-size:.8em">
                                    <?php if ($d['next_review_date']): ?>
                                        <span style="<?= $is_overdue ? 'color:#e74c3c;font-weight:700' : '' ?>">
                                            <?= date('d/m/Y', strtotime($d['next_review_date'])) ?>
                                            <?= $is_overdue ? '⚠️' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                        <?php if ($d['status'] !== 'Obsolete'): ?>
                                            <select name="new_status" style="font-size:.75em;padding:3px">
                                                <?php foreach (['Draft', 'Under Review', 'Active', 'Obsolete'] as $st): ?>
                                                    <option value="<?= $st ?>" <?= $d['status'] === $st ? 'selected' : '' ?>><?= $st ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="update_doc_status" class="btn-save" title="حفظ">💾</button>
                                        <?php else: ?>
                                            <small style="color:#999">ملغاة</small>
                                        <?php endif; ?>
                                    </form>
                                    <button onclick="openRevision(<?= $d['id'] ?>, '<?= htmlspecialchars($d['doc_number']) ?>', '<?= $d['current_revision'] ?>')"
                                        class="btn-save" style="background:#667eea;margin-left:4px" title="مراجعة جديدة">📝</button>
                                    <?php if ($is_admin): ?>
                                        <form method="POST" style="display:inline"
                                            onsubmit="return confirm('حذف هذه الوثيقة نهائياً؟')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                            <button type="submit" name="delete_doc" class="btn-save"
                                                style="background:#e74c3c" title="حذف">🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($doc_revisions)): ?>
                                <tr class="revision-row" data-category="<?= htmlspecialchars($d['category']) ?>"
                                    data-type="<?= htmlspecialchars($d['doc_type']) ?>" data-status="<?= $d['status'] ?>">
                                    <td colspan="11" style="text-align:left;padding:8px 15px;background:#fafbfc">
                                        <strong style="font-size:.8em">📝 سجل المراجعات:</strong>
                                        <?php foreach ($doc_revisions as $rv): ?>
                                            <div class="revision-box">
                                                <strong>Rev <?= $rv['revision'] ?></strong> —
                                                <?= htmlspecialchars($rv['change_description'] ?: '-') ?>
                                                <br><small>✅ <?= htmlspecialchars($rv['approved_by'] ?: '-') ?> |
                                                    📅 <?= $rv['effective_date'] ? date('d/m/Y', strtotime($rv['effective_date'])) : '-' ?></small>
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

        <!-- Print Footer -->
        <div class="print-footer" style="display:none">
            <div>Generé par système le: <?= date('Y-m-d H:i') ?> | User: <?= $user_name ?></div>
            <div>
                Document Controller: ____________________ &nbsp;&nbsp;|&nbsp;&nbsp;
                Quality Manager: ____________________
            </div>
        </div>

        <!-- ═══════ CREATE DOCUMENT MODAL ═══════ -->
        <div class="modal-overlay" id="doc-modal">
            <div class="modal-box">
                <h2>📄 إضافة وثيقة جديدة <small style="font-weight:400">New Document</small></h2>
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>العنوان بالإنجليزية <small>/ Title (EN) *</small></label>
                            <input type="text" name="title_en" required placeholder="e.g. Sewing Quality Procedure">
                        </div>
                        <div class="form-group">
                            <label>العنوان بالعربية <small>/ Title (AR)</small></label>
                            <input type="text" name="title_ar" placeholder="مثال: إجراء جودة الخياطة">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>الفئة <small>/ Category</small></label>
                            <select name="category">
                                <option value="SOP">📋 SOP — إجراء عمل قياسي</option>
                                <option value="Work Instruction">📝 Work Instruction — تعليمات العمل</option>
                                <option value="Policy">📜 Policy — سياسة</option>
                                <option value="Form/Template">📄 Form/Template — نموذج</option>
                                <option value="Record">📁 Record — سجل</option>
                                <option value="External">🌐 External — وثيقة خارجية</option>
                                <option value="Manual">📖 Manual — دليل</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>النوع <small>/ Type</small></label>
                            <select name="doc_type">
                                <option value="Quality">🔍 Quality — الجودة</option>
                                <option value="Production">🏭 Production — الإنتاج</option>
                                <option value="Maintenance">🔧 Maintenance — الصيانة</option>
                                <option value="Safety">⛑️ Safety — السلامة</option>
                                <option value="HR">👥 HR — الموارد البشرية</option>
                                <option value="Purchasing">📦 Purchasing — المشتريات</option>
                                <option value="General">📌 General — عام</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>القسم <small>/ Department</small></label>
                            <select name="department">
                                <option value="">-- اختر القسم --</option>
                                <option>Cutting / القص</option>
                                <option>Sewing / الخياطة</option>
                                <option>Finishing / التوضيب</option>
                                <option>Quality / الجودة</option>
                                <option>Warehouse / المخزن</option>
                                <option>Maintenance / الصيانة</option>
                                <option>HR / الموارد البشرية</option>
                                <option>Admin / الإدارة</option>
                                <option>All / الكل</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>المالك <small>/ Document Owner</small></label>
                            <select name="owner">
                                <option value="">-- اختر المالك --</option>
                                <optgroup label="🏭 الإنتاج">
                                    <option>Production Manager | مدير الإنتاج</option>
                                    <option>Floor Manager | رئيس الورشة</option>
                                </optgroup>
                                <optgroup label="🔍 الجودة">
                                    <option>Quality Manager | مدير الجودة</option>
                                    <option>Quality Controller | مراقب الجودة</option>
                                </optgroup>
                                <optgroup label="🔧 الصيانة">
                                    <option>Maintenance Manager | مدير الصيانة</option>
                                </optgroup>
                                <optgroup label="💼 الإدارة">
                                    <option>HR Manager | مدير الموارد البشرية</option>
                                    <option>HSE Officer | مسؤول السلامة</option>
                                    <option>Factory Director | مدير المصنع</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>موعد المراجعة القادمة <small>/ Next Review</small></label>
                            <input type="date" name="next_review_date">
                        </div>
                    </div>
                    <div class="form-row" style="grid-template-columns:1fr">
                        <div class="form-group">
                            <label>الوصف <small>/ Description (optional)</small></label>
                            <textarea name="description" rows="2" placeholder="وصف مختصر للوثيقة..."></textarea>
                        </div>
                    </div>
                    <div class="modal-btns">
                        <button type="button" class="btn-cancel" onclick="closeModal('doc-modal')">إلغاء</button>
                        <button type="submit" name="create_doc" class="btn-submit">📄 إنشاء الوثيقة</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══════ REVISION MODAL ═══════ -->
        <div class="modal-overlay" id="revision-modal">
            <div class="modal-box">
                <h2>📝 مراجعة جديدة <small style="font-weight:400" id="rev-doc-ref"></small></h2>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="doc_id" id="rev-doc-id">
                    <div class="form-row">
                        <div class="form-group">
                            <label>رقم المراجعة الجديد <small>/ New Revision</small></label>
                            <input type="text" name="new_revision" id="rev-new-num" required>
                        </div>
                        <div class="form-group">
                            <label>تاريخ النفاذ <small>/ Effective Date</small></label>
                            <input type="date" name="effective_date" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="form-row" style="grid-template-columns:1fr">
                        <div class="form-group">
                            <label>وصف التغيير <small>/ Change Description</small></label>
                            <textarea name="change_description" rows="2" required placeholder="ما الذي تغير في هذه المراجعة؟"></textarea>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>اعتمد بواسطة <small>/ Approved By</small></label>
                            <input type="text" name="approved_by" value="<?= htmlspecialchars($user_name) ?>">
                        </div>
                    </div>
                    <div class="modal-btns">
                        <button type="button" class="btn-cancel" onclick="closeModal('revision-modal')">إلغاء</button>
                        <button type="submit" name="add_revision" class="btn-submit">📝 حفظ المراجعة</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openModal(id) { document.getElementById(id).classList.add('active'); }
            function closeModal(id) { document.getElementById(id).classList.remove('active'); }

            function openRevision(docId, docNum, currentRev) {
                document.getElementById('rev-doc-id').value = docId;
                document.getElementById('rev-doc-ref').textContent = docNum;
                // Auto-increment revision
                const parts = currentRev.split('.');
                if (parts.length === 2) {
                    const minor = parseInt(parts[1]) + 1;
                    document.getElementById('rev-new-num').value = parts[0] + '.' + minor;
                } else {
                    document.getElementById('rev-new-num').value = currentRev + '.1';
                }
                openModal('revision-modal');
            }

            // --- Filters ---
            function filterDocs() {
                const fCat = document.getElementById('f-category').value;
                const fType = document.getElementById('f-type').value;
                const fStat = document.getElementById('f-status').value;
                const fSearch = document.getElementById('f-search').value.toLowerCase();
                const rows = document.querySelectorAll('#doc-table tbody tr');
                let shown = 0;
                rows.forEach(row => {
                    const cat = row.dataset.category || '';
                    const type = row.dataset.type || '';
                    const stat = row.dataset.status || '';
                    const text = row.textContent.toLowerCase();
                    const match = (!fCat || cat === fCat) && (!fType || type === fType) && (!fStat || stat === fStat) && (!fSearch || text.includes(fSearch));
                    row.style.display = match ? '' : 'none';
                    if (match && !row.classList.contains('revision-row')) shown++;
                });
                document.getElementById('filter-count').textContent = (fCat || fType || fStat || fSearch) ? `عرض ${shown} من ${<?= $total ?>}` : '';
            }

            function resetFilters() {
                document.getElementById('f-category').value = '';
                document.getElementById('f-type').value = '';
                document.getElementById('f-status').value = '';
                document.getElementById('f-search').value = '';
                filterDocs();
            }
        </script>
    </div>
</body>

</html>

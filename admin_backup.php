<?php
session_start();
require 'db.php';
require 'includes/auth.php';
require_admin();

$project_dir = __DIR__;
$backup_dir  = $project_dir . '/backups';

// Create backups directory if missing
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
    // Protect backups folder from web access
    file_put_contents($backup_dir . '/.htaccess', "Deny from all\n");
}

$msg = '';
$msg_type = '';

// ═══════════════════════════════════════════════════
// HANDLE ACTIONS
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // ── BACKUP DATABASE ──
    if (isset($_POST['backup_db'])) {
        $timestamp = date('Y-m-d_H-i-s');
        $filename  = "db_backup_{$timestamp}.sql";
        $filepath  = $backup_dir . '/' . $filename;

        $db_host = getenv('DB_HOST') ?: 'localhost';
        $db_name = getenv('DB_NAME') ?: 'candytex_dash';
        $db_user = getenv('DB_USER') ?: 'candytex_user';
        $db_pass = getenv('DB_PASS') ?: '';

        // Try mysqldump first
        $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (!file_exists($mysqldump)) {
            $mysqldump = 'mysqldump'; // fallback to PATH
        }

        $cmd = sprintf(
            '"%s" --host=%s --user=%s --password=%s --single-transaction --routines --triggers --add-drop-table %s > "%s" 2>&1',
            $mysqldump,
            escapeshellarg($db_host),
            escapeshellarg($db_user),
            escapeshellarg($db_pass),
            escapeshellarg($db_name),
            $filepath
        );

        exec($cmd, $output, $return_code);

        // If mysqldump failed, use PHP-based export
        if ($return_code !== 0 || !file_exists($filepath) || filesize($filepath) < 100) {
            $sql_content = php_db_export($pdo, $db_name);
            if ($sql_content) {
                file_put_contents($filepath, $sql_content);
                $msg = "✅ تم حفظ قاعدة البيانات بنجاح (PHP Export) — Database backed up: $filename";
                $msg_type = 'success';
            } else {
                $msg = "❌ فشل حفظ قاعدة البيانات — Database backup failed";
                $msg_type = 'error';
            }
        } else {
            $msg = "✅ تم حفظ قاعدة البيانات بنجاح — Database backed up: $filename";
            $msg_type = 'success';
        }
    }

    // ── BACKUP FILES ──
    if (isset($_POST['backup_files'])) {
        $timestamp = date('Y-m-d_H-i-s');
        $filename  = "files_backup_{$timestamp}.zip";
        $filepath  = $backup_dir . '/' . $filename;

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $exclude = ['backups', '.git', 'vendor', 'node_modules', '.env'];
                add_dir_to_zip($zip, $project_dir, $project_dir, $exclude);
                $zip->close();
                $size = format_size(filesize($filepath));
                $msg = "✅ تم حفظ ملفات المشروع بنجاح ($size) — Files backed up: $filename";
                $msg_type = 'success';
            } else {
                $msg = "❌ فشل إنشاء ملف ZIP — Failed to create ZIP archive";
                $msg_type = 'error';
            }
        } else {
            $msg = "❌ ZipArchive غير متوفر — ZipArchive extension not available";
            $msg_type = 'error';
        }
    }

    // ── FULL BACKUP (DB + FILES) ──
    if (isset($_POST['backup_full'])) {
        $timestamp = date('Y-m-d_H-i-s');
        $sql_file  = "db_backup_{$timestamp}.sql";
        $zip_file  = "full_backup_{$timestamp}.zip";
        $sql_path  = $backup_dir . '/' . $sql_file;
        $zip_path  = $backup_dir . '/' . $zip_file;

        // 1. Export database
        $sql_content = php_db_export($pdo, getenv('DB_NAME') ?: 'candytex_dash');
        file_put_contents($sql_path, $sql_content);

        // 2. Create ZIP with files + SQL
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $exclude = ['backups', '.git', 'vendor', 'node_modules', '.env'];
                add_dir_to_zip($zip, $project_dir, $project_dir, $exclude);
                $zip->addFile($sql_path, 'database/' . $sql_file);
                $zip->close();
                // Remove standalone SQL
                @unlink($sql_path);
                $size = format_size(filesize($zip_path));
                $msg = "✅ تم عمل نسخة احتياطية كاملة ($size) — Full backup: $zip_file";
                $msg_type = 'success';
            } else {
                $msg = "❌ فشل إنشاء النسخة الاحتياطية — Backup failed";
                $msg_type = 'error';
            }
        }
    }

    // ── DELETE BACKUP ──
    if (isset($_POST['delete_backup']) && isset($_POST['file'])) {
        $target = basename($_POST['file']); // prevent path traversal
        $target_path = $backup_dir . '/' . $target;
        if (file_exists($target_path) && strpos($target_path, $backup_dir) === 0) {
            unlink($target_path);
            $msg = "🗑️ تم حذف النسخة: $target";
            $msg_type = 'success';
        }
    }

    // ── DOWNLOAD BACKUP ──
    if (isset($_POST['download_backup']) && isset($_POST['file'])) {
        $target = basename($_POST['file']);
        $target_path = $backup_dir . '/' . $target;
        if (file_exists($target_path) && strpos($target_path, $backup_dir) === 0) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $target . '"');
            header('Content-Length: ' . filesize($target_path));
            header('Cache-Control: no-cache');
            readfile($target_path);
            exit;
        }
    }
}

// ═══════════════════════════════════════════════════
// LIST EXISTING BACKUPS
// ═══════════════════════════════════════════════════
$backups = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
        $path = $backup_dir . '/' . $f;
        $backups[] = [
            'name'  => $f,
            'size'  => filesize($path),
            'date'  => filemtime($path),
            'type'  => get_backup_type($f),
        ];
    }
}

// Calculate total backup size
$total_size = array_sum(array_column($backups, 'size'));

// Disk info
$disk_free = @disk_free_space($project_dir);
$disk_total = @disk_total_space($project_dir);

// ═══════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════
function add_dir_to_zip($zip, $dir, $base_dir, $exclude = []) {
    $iterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    foreach ($iterator as $item) {
        $rel = str_replace($base_dir . DIRECTORY_SEPARATOR, '', $item->getPathname());
        $first_part = explode(DIRECTORY_SEPARATOR, $rel)[0];

        if (in_array($first_part, $exclude)) continue;

        if ($item->isDir()) {
            add_dir_to_zip($zip, $item->getPathname(), $base_dir, $exclude);
        } else {
            // Skip very large files (>50MB)
            if ($item->getSize() > 50 * 1024 * 1024) continue;
            $zip->addFile($item->getPathname(), $rel);
        }
    }
}

function php_db_export($pdo, $db_name) {
    $sql = "-- CANDYTEX Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database: $db_name\n";
    $sql .= "-- ============================================\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            // Drop + Create
            $sql .= "-- ───────────────────────────────────\n";
            $sql .= "-- Table: $table\n";
            $sql .= "-- ───────────────────────────────────\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
            $sql .= $create['Create Table'] . ";\n\n";

            // Data
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = array_keys($rows[0]);
                $col_list = '`' . implode('`, `', $cols) . '`';
                $sql .= "INSERT INTO `$table` ($col_list) VALUES\n";
                $vals = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($v) use ($pdo) {
                        return $v === null ? 'NULL' : $pdo->quote($v);
                    }, array_values($row));
                    $vals[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $vals) . ";\n\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return $sql;
    } catch (Exception $e) {
        return false;
    }
}

function format_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function get_backup_type($name) {
    if (strpos($name, 'full_backup') !== false) return 'full';
    if (strpos($name, 'db_backup') !== false) return 'database';
    if (strpos($name, 'files_backup') !== false) return 'files';
    return 'other';
}

$user_name = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💾 نسخ احتياطي — CANDYTEX Backup</title>
    <style>
        :root {
            --bg: #0f1923;
            --card: #1a2634;
            --card2: #243447;
            --accent: #667eea;
            --accent2: #764ba2;
            --green: #00c853;
            --orange: #ff9800;
            --red: #ff5252;
            --text: #e0e6ed;
            --muted: #8899aa;
            --border: #2d3f52;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            direction: rtl;
        }

        .top-bar {
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            padding: 15px 20px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px;
        }
        .top-bar h1 { font-size: 1.3em; display: flex; align-items: center; gap: 8px; }
        .top-bar a { color: rgba(255,255,255,0.85); text-decoration: none; padding: 6px 14px;
                     background: rgba(255,255,255,0.15); border-radius: 6px; font-size: 0.85em; }
        .top-bar a:hover { background: rgba(255,255,255,0.25); }

        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }

        .alert {
            padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
            font-weight: 500; display: flex; align-items: center; gap: 8px;
        }
        .alert.success { background: rgba(0,200,83,0.15); border: 1px solid rgba(0,200,83,0.3); color: #69f0ae; }
        .alert.error { background: rgba(255,82,82,0.15); border: 1px solid rgba(255,82,82,0.3); color: #ff8a80; }

        /* ─── Stats Cards ─── */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card {
            background: var(--card); border-radius: 14px; padding: 20px;
            border: 1px solid var(--border); text-align: center;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-icon { font-size: 2em; margin-bottom: 8px; }
        .stat-value { font-size: 1.5em; font-weight: 700; margin-bottom: 4px; }
        .stat-label { font-size: 0.8em; color: var(--muted); }

        /* ─── Action Buttons ─── */
        .actions {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px; margin-bottom: 30px;
        }
        .action-card {
            background: var(--card); border-radius: 14px; padding: 25px;
            border: 1px solid var(--border); text-align: center;
            transition: all 0.3s;
        }
        .action-card:hover { border-color: var(--accent); box-shadow: 0 4px 20px rgba(102,126,234,0.2); }
        .action-icon { font-size: 2.5em; margin-bottom: 10px; }
        .action-title { font-size: 1.1em; font-weight: 700; margin-bottom: 6px; }
        .action-desc { font-size: 0.8em; color: var(--muted); margin-bottom: 15px; line-height: 1.5; }
        .btn-backup {
            width: 100%; padding: 12px; border: none; border-radius: 10px;
            font-size: 1em; font-weight: 600; cursor: pointer; color: #fff;
            transition: all 0.3s; display: flex; align-items: center;
            justify-content: center; gap: 8px;
        }
        .btn-backup:hover { transform: scale(1.02); filter: brightness(1.1); }
        .btn-backup:disabled { opacity: 0.6; cursor: wait; }
        .btn-db { background: linear-gradient(135deg, #00c853, #009624); }
        .btn-files { background: linear-gradient(135deg, #2979ff, #1565c0); }
        .btn-full { background: linear-gradient(135deg, var(--accent), var(--accent2)); }

        /* ─── Backup Table ─── */
        .section-title {
            font-size: 1.1em; margin-bottom: 15px; display: flex;
            align-items: center; gap: 8px; color: var(--accent);
        }
        .backup-table {
            width: 100%; border-collapse: separate; border-spacing: 0;
            background: var(--card); border-radius: 14px; overflow: hidden;
            border: 1px solid var(--border);
        }
        .backup-table th {
            background: var(--card2); padding: 12px 15px; font-size: 0.85em;
            color: var(--muted); text-align: right; font-weight: 600;
        }
        .backup-table td { padding: 12px 15px; border-bottom: 1px solid var(--border); }
        .backup-table tr:last-child td { border-bottom: none; }
        .backup-table tr:hover td { background: rgba(102,126,234,0.05); }

        .type-badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: 0.75em; font-weight: 600;
        }
        .type-full { background: rgba(102,126,234,0.2); color: #a5b4fc; }
        .type-database { background: rgba(0,200,83,0.2); color: #69f0ae; }
        .type-files { background: rgba(41,121,255,0.2); color: #82b1ff; }

        .btn-sm {
            padding: 5px 12px; border: none; border-radius: 6px;
            font-size: 0.78em; cursor: pointer; color: #fff;
            margin-left: 4px; transition: all 0.2s;
        }
        .btn-dl { background: #2979ff; }
        .btn-dl:hover { background: #448aff; }
        .btn-del { background: #e53935; }
        .btn-del:hover { background: #ef5350; }

        .empty-state {
            text-align: center; padding: 50px; color: var(--muted);
        }
        .empty-state .icon { font-size: 3em; margin-bottom: 10px; opacity: 0.5; }

        /* ─── Disk Usage Bar ─── */
        .disk-bar {
            background: var(--card2); border-radius: 10px; height: 20px;
            overflow: hidden; margin: 10px 0;
        }
        .disk-fill {
            height: 100%; border-radius: 10px;
            background: linear-gradient(90deg, var(--green), var(--accent));
            transition: width 0.5s;
        }

        /* ─── Loading Overlay ─── */
        .loading-overlay {
            display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.7); z-index: 9999;
            justify-content: center; align-items: center; flex-direction: column; gap: 15px;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 50px; height: 50px; border: 4px solid rgba(255,255,255,0.2);
            border-top-color: var(--accent); border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { color: #fff; font-size: 1.1em; }

        /* ─── Warning Box ─── */
        .warning-box {
            background: rgba(255,152,0,0.1); border: 1px solid rgba(255,152,0,0.3);
            border-radius: 10px; padding: 14px 18px; margin-bottom: 20px;
            font-size: 0.85em; color: #ffcc80; display: flex; gap: 10px; line-height: 1.6;
        }

        @media (max-width: 600px) {
            .actions { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .backup-table { font-size: 0.85em; }
        }
    </style>
</head>

<body>

<div class="top-bar">
    <h1>💾 نسخ احتياطي <small style="font-weight:400;opacity:0.85">Backup Manager</small></h1>
    <div>
        <a href="admin.php">⚙️ الإدارة</a>
        <a href="index.php">📊 لوحة القيادة</a>
    </div>
</div>

<div class="container">

    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <div class="warning-box">
        <span style="font-size:1.5em">⚠️</span>
        <div>
            <strong>تنبيه مهم:</strong> النسخ الاحتياطية تُحفظ في مجلد <code>backups/</code> داخل المشروع ومحمية من الوصول عبر الإنترنت.<br>
            <strong>Important:</strong> Backups are saved in the <code>backups/</code> folder and protected by .htaccess. For maximum safety, download backups to an external drive.
        </div>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-value"><?= count($backups) ?></div>
            <div class="stat-label">عدد النسخ / Total Backups</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">💿</div>
            <div class="stat-value"><?= format_size($total_size) ?></div>
            <div class="stat-label">حجم النسخ / Backup Size</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🗄️</div>
            <div class="stat-value"><?= $disk_free ? format_size($disk_free) : 'N/A' ?></div>
            <div class="stat-label">المساحة المتاحة / Free Disk</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📅</div>
            <div class="stat-value">
                <?= !empty($backups) ? date('d/m', $backups[0]['date']) : '—' ?>
            </div>
            <div class="stat-label">آخر نسخة / Last Backup</div>
        </div>
    </div>

    <?php if ($disk_total && $disk_free): ?>
        <div style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;font-size:0.8em;color:var(--muted);margin-bottom:5px">
                <span>مساحة القرص المستخدمة</span>
                <span><?= format_size($disk_total - $disk_free) ?> / <?= format_size($disk_total) ?></span>
            </div>
            <div class="disk-bar">
                <div class="disk-fill" style="width:<?= round(($disk_total - $disk_free) / $disk_total * 100) ?>%"></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="actions">
        <div class="action-card">
            <div class="action-icon">🗄️</div>
            <div class="action-title">قاعدة البيانات فقط</div>
            <div class="action-desc">
                حفظ جميع الجداول والبيانات في ملف SQL<br>
                <small>Database Only — All tables & data</small>
            </div>
            <form method="POST" onsubmit="showLoading('جاري حفظ قاعدة البيانات...')">
                <?= csrf_field() ?>
                <button type="submit" name="backup_db" class="btn-backup btn-db">
                    🗄️ حفظ قاعدة البيانات
                </button>
            </form>
        </div>

        <div class="action-card">
            <div class="action-icon">📁</div>
            <div class="action-title">ملفات المشروع فقط</div>
            <div class="action-desc">
                حفظ جميع ملفات PHP, CSS, JS في ملف ZIP<br>
                <small>Project Files — All PHP, CSS, JS files</small>
            </div>
            <form method="POST" onsubmit="showLoading('جاري ضغط الملفات...')">
                <?= csrf_field() ?>
                <button type="submit" name="backup_files" class="btn-backup btn-files">
                    📁 حفظ الملفات
                </button>
            </form>
        </div>

        <div class="action-card">
            <div class="action-icon">🛡️</div>
            <div class="action-title">نسخة كاملة</div>
            <div class="action-desc">
                حفظ الملفات + قاعدة البيانات معاً في ZIP واحد<br>
                <small>Full Backup — Files + Database in one ZIP</small>
            </div>
            <form method="POST" onsubmit="showLoading('جاري إنشاء نسخة كاملة... قد يستغرق دقيقة')">
                <?= csrf_field() ?>
                <button type="submit" name="backup_full" class="btn-backup btn-full">
                    🛡️ نسخة احتياطية كاملة
                </button>
            </form>
        </div>
    </div>

    <!-- Backup List -->
    <div class="section-title">📋 النسخ الاحتياطية المحفوظة <small style="color:var(--muted);font-weight:400">(Saved Backups)</small></div>

    <?php if (empty($backups)): ?>
        <div class="empty-state">
            <div class="icon">📭</div>
            <p>لا توجد نسخ احتياطية بعد — No backups yet</p>
            <p style="font-size:0.85em;margin-top:8px">اضغط أحد الأزرار أعلاه لإنشاء أول نسخة احتياطية</p>
        </div>
    <?php else: ?>
        <table class="backup-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الملف / Filename</th>
                    <th>النوع / Type</th>
                    <th>الحجم / Size</th>
                    <th>التاريخ / Date</th>
                    <th>إجراءات / Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $i => $bk): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td style="font-family:monospace;font-size:0.85em"><?= htmlspecialchars($bk['name']) ?></td>
                        <td>
                            <span class="type-badge type-<?= $bk['type'] ?>">
                                <?php
                                    echo match ($bk['type']) {
                                        'full' => '🛡️ كاملة',
                                        'database' => '🗄️ قاعدة بيانات',
                                        'files' => '📁 ملفات',
                                        default => '📄 أخرى'
                                    };
                                ?>
                            </span>
                        </td>
                        <td><?= format_size($bk['size']) ?></td>
                        <td style="font-size:0.85em"><?= date('Y-m-d H:i', $bk['date']) ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="file" value="<?= htmlspecialchars($bk['name']) ?>">
                                <button type="submit" name="download_backup" class="btn-sm btn-dl" title="تحميل">⬇️ تحميل</button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذه النسخة نهائياً؟')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="file" value="<?= htmlspecialchars($bk['name']) ?>">
                                <button type="submit" name="delete_backup" class="btn-sm btn-del" title="حذف">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loading">
    <div class="spinner"></div>
    <div class="loading-text" id="loading-text">جاري النسخ الاحتياطي...</div>
</div>

<script>
function showLoading(msg) {
    document.getElementById('loading-text').textContent = msg || 'جاري النسخ الاحتياطي...';
    document.getElementById('loading').classList.add('active');
    // Disable all backup buttons
    document.querySelectorAll('.btn-backup').forEach(b => b.disabled = true);
}
</script>

</body>
</html>

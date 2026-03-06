<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Inline fallback
if (!function_exists('is_hr_admin')) {
    function is_hr_admin() { return isset($_SESSION['role']) && $_SESSION['role'] === 'hr_admin'; }
}

require_hr_or_admin();

$is_admin_user = is_admin();
$is_hr_user = is_hr();
$is_hr_admin_u = is_hr_admin();
$is_restricted = $is_hr_user || $is_hr_admin_u;

// Location restriction for HR/HR_Admin
$hr_location_id = null;
if ($is_restricted) {
    $stmt = $pdo->prepare("SELECT l.id FROM users u JOIN locations l ON u.location COLLATE utf8mb4_unicode_ci = l.name COLLATE utf8mb4_unicode_ci WHERE u.cin = ?");
    $stmt->execute([$_SESSION['user_cin']]);
    $hr_location_id = $stmt->fetchColumn();
    if (!$hr_location_id) {
        die("⛔ Your account is not properly assigned to a factory location.");
    }
}

// Report types
$report_types = [
    'ABS' => ['label' => 'تقرير الغيابات / Rapport d\'Absences', 'types' => ['M'], 'icon' => '🏥', 'color' => '#e74c3c'],
    'MAT' => ['label' => 'تقرير الأمومة / Rapport de Maternité', 'types' => ['MAT'], 'icon' => '🤱', 'color' => '#e91e63'],
    'ACC' => ['label' => 'تقرير حوادث الشغل / Rapport d\'Accidents', 'types' => ['ACC'], 'icon' => '⚠️', 'color' => '#ff9800'],
    'CP'  => ['label' => 'تقرير الإجازات / Rapport de Congés', 'types' => ['CP'], 'icon' => '🏖️', 'color' => '#2196f3'],
    'MP'  => ['label' => 'تقرير الإيقاف / Rapport de Mise à Pied', 'types' => ['MP'], 'icon' => '🚫', 'color' => '#795548'],
    'ALL' => ['label' => 'تقرير شامل / Rapport Complet', 'types' => ['M','MAT','ACC','CP','MP','AUT'], 'icon' => '📋', 'color' => '#0b3c5d'],
];

// Filters
$report_type = $_GET['report'] ?? '';
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to = $_GET['to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$location_filter = $_GET['location_id'] ?? '';
$print_mode = isset($_GET['print']);

// Fetch locations for filter
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// If report is selected, fetch data
$records = [];
if ($report_type && isset($report_types[$report_type])) {
    $rt = $report_types[$report_type];
    $placeholders = implode(',', array_fill(0, count($rt['types']), '?'));
    
    $query = "SELECT a.*, e.matricule, e.full_name, e.function_title, e.department, e.cin, 
                     l.name as location_name, e.phone_number
              FROM hr_absences a
              JOIN hr_employees e ON a.employee_id = e.id
              LEFT JOIN locations l ON e.location_id = l.id
              WHERE a.absence_type IN ($placeholders)
              AND (
                  (a.start_date BETWEEN ? AND ?) OR 
                  (a.end_date BETWEEN ? AND ?) OR
                  (a.start_date <= ? AND a.end_date >= ?)
              )";
    $params = array_merge($rt['types'], [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to]);
    
    // Location restriction
    if ($is_restricted && $hr_location_id) {
        $query .= " AND e.location_id = ?";
        $params[] = $hr_location_id;
    } elseif ($location_filter) {
        $query .= " AND e.location_id = ?";
        $params[] = $location_filter;
    }
    
    // Search
    if ($search) {
        $query .= " AND (e.full_name LIKE ? OR e.matricule LIKE ? OR e.cin LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $query .= " ORDER BY a.start_date DESC, e.full_name ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Absence type labels
$type_labels = [
    'M' => 'Maladie / مرض',
    'MAT' => 'Maternité / أمومة',
    'ACC' => 'Accident / حادث شغل',
    'CP' => 'Congé Payé / إجازة',
    'MP' => 'Mise à Pied / إيقاف',
    'AUT' => 'Autorisation / إذن',
    'R' => 'Retard / تأخير',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>HR Reports / تقارير الموارد البشرية</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Screen styles */
        .report-container { max-width: 1200px; margin: 0 auto; }
        .report-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .report-card {
            background: white; border-radius: 12px; padding: 20px; text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08); border-top: 4px solid #ccc;
            cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none; color: inherit;
        }
        .report-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.15); }
        .report-card .icon { font-size: 2.5em; margin-bottom: 10px; }
        .report-card h3 { margin: 0; font-size: 1em; }
        .report-card .count { font-size: 2em; font-weight: bold; margin: 10px 0; }
        
        .filter-box {
            background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-row .form-group { margin: 0; }
        .filter-row label { display: block; font-size: 0.85em; font-weight: 600; margin-bottom: 4px; }
        .filter-row input, .filter-row select {
            padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em;
        }
        .btn-print {
            background: #0b3c5d; color: white; border: none; padding: 10px 25px;
            border-radius: 6px; cursor: pointer; font-size: 0.9em; font-weight: 600;
        }
        .btn-print:hover { background: #0a2d44; }
        
        .results-table {
            width: 100%; border-collapse: collapse; background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden;
        }
        .results-table th {
            background: #0b3c5d; color: white; padding: 12px 10px; text-align: right;
            font-size: 0.85em; white-space: nowrap;
        }
        .results-table td {
            padding: 10px; border-bottom: 1px solid #eee; font-size: 0.85em;
        }
        .results-table tr:hover { background: #f8f9fa; }
        .badge-type {
            display: inline-block; padding: 3px 8px; border-radius: 4px;
            font-size: 0.8em; font-weight: 600; color: white;
        }
        .summary-bar {
            display: flex; gap: 20px; margin-bottom: 15px; flex-wrap: wrap;
        }
        .summary-item {
            background: white; padding: 12px 20px; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;
        }
        .summary-item .num { font-size: 1.5em; font-weight: bold; }
        .summary-item .lbl { font-size: 0.8em; color: #666; }

        /* Print A4 Styles */
        @media print {
            @page {
                size: A4 landscape;
                margin: 15mm;
            }
            body { margin: 0; padding: 0; background: white; font-size: 9pt; direction: rtl; }
            .top-nav, .sidebar, .no-print, .filter-box, .report-cards, .summary-bar { display: none !important; }
            .main-content { padding: 0 !important; max-width: 100% !important; }
            
            .print-header { display: flex !important; justify-content: space-between; align-items: center; border-bottom: 2px solid #0b3c5d; padding-bottom: 10px; margin-bottom: 15px; }
            .print-header .logo { font-size: 14pt; font-weight: bold; color: #0b3c5d; }
            .print-header .doc-info { text-align: left; font-size: 8pt; border: 1px solid #333; padding: 5px 10px; }
            
            .results-table { box-shadow: none; border: 1px solid #333; }
            .results-table th { background: #e0e0e0 !important; color: #333 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .results-table td { border-bottom: 1px solid #ccc; padding: 6px 8px; font-size: 8pt; }
            
            .print-footer { display: block !important; position: fixed; bottom: 0; left: 0; width: 100%; border-top: 1px solid #333; padding-top: 5px; font-size: 7pt; text-align: center; }
            
            /* Page number via CSS counter */
            .page-number::after { content: counter(page) " / " counter(pages); }
        }
        
        .print-header, .print-footer { display: none; }
    </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>

<div class="main-content">
    <div class="report-container">

        <!-- Print Header (only shows when printing) -->
        <div class="print-header">
            <div class="logo">🏭 CandyTex - <?= $report_type ? htmlspecialchars($report_types[$report_type]['label'] ?? 'Report') : 'HR Report' ?></div>
            <div class="doc-info">
                <strong>Date:</strong> <?= date('d/m/Y H:i') ?><br>
                <strong>Période:</strong> <?= date('d/m/Y', strtotime($date_from)) ?> → <?= date('d/m/Y', strtotime($date_to)) ?><br>
                <strong>Par:</strong> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
            </div>
        </div>

        <?php if (!$report_type): ?>
            <!-- Report Selection -->
            <h2 style="text-align: center; margin-bottom: 25px;">📊 تقارير الموارد البشرية / Rapports RH</h2>
            
            <div class="filter-box no-print">
                <form method="GET" class="filter-row">
                    <div class="form-group">
                        <label>من تاريخ / Du</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="form-group">
                        <label>إلى تاريخ / Au</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <?php if ($is_admin_user): ?>
                    <div class="form-group">
                        <label>المصنع / Factory</label>
                        <select name="location_id">
                            <option value="">-- All --</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>" <?= $location_filter == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="report-cards">
                <?php foreach ($report_types as $key => $rt): 
                    // Count records for this type
                    $placeholders = implode(',', array_fill(0, count($rt['types']), '?'));
                    $count_q = "SELECT COUNT(*) FROM hr_absences a JOIN hr_employees e ON a.employee_id = e.id WHERE a.absence_type IN ($placeholders) AND (a.start_date BETWEEN ? AND ? OR a.end_date BETWEEN ? AND ? OR (a.start_date <= ? AND a.end_date >= ?))";
                    $count_p = array_merge($rt['types'], [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to]);
                    if ($is_restricted && $hr_location_id) {
                        $count_q .= " AND e.location_id = ?";
                        $count_p[] = $hr_location_id;
                    } elseif ($location_filter) {
                        $count_q .= " AND e.location_id = ?";
                        $count_p[] = $location_filter;
                    }
                    $count_stmt = $pdo->prepare($count_q);
                    $count_stmt->execute($count_p);
                    $count = $count_stmt->fetchColumn();
                ?>
                <a href="?report=<?= $key ?>&from=<?= $date_from ?>&to=<?= $date_to ?>&location_id=<?= $location_filter ?>" 
                   class="report-card" style="border-top-color: <?= $rt['color'] ?>;">
                    <div class="icon"><?= $rt['icon'] ?></div>
                    <h3><?= $rt['label'] ?></h3>
                    <div class="count" style="color: <?= $rt['color'] ?>;"><?= $count ?></div>
                    <div style="font-size:0.8em; color:#888;">سجل في الفترة المحددة</div>
                </a>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <!-- Report Results -->
            <?php $rt = $report_types[$report_type]; ?>
            
            <div class="no-print" style="margin-bottom: 15px;">
                <a href="hr_reports.php" style="color: #0984e3; text-decoration: none; font-size: 0.9em;">← العودة للتقارير</a>
            </div>

            <h2 class="no-print" style="margin-bottom: 15px;">
                <?= $rt['icon'] ?> <?= $rt['label'] ?>
                <span style="font-size: 0.6em; color: #888;">(<?= date('d/m/Y', strtotime($date_from)) ?> → <?= date('d/m/Y', strtotime($date_to)) ?>)</span>
            </h2>

            <!-- Filters -->
            <div class="filter-box no-print">
                <form method="GET" class="filter-row">
                    <input type="hidden" name="report" value="<?= htmlspecialchars($report_type) ?>">
                    <div class="form-group" style="flex:1; min-width:180px;">
                        <label>بحث / Recherche</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="اسم، رقم، CIN..." style="width:100%;">
                    </div>
                    <div class="form-group">
                        <label>من تاريخ / Du</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="form-group">
                        <label>إلى تاريخ / Au</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <?php if ($is_admin_user): ?>
                    <div class="form-group">
                        <label>المصنع / Factory</label>
                        <select name="location_id">
                            <option value="">-- All --</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>" <?= $location_filter == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn-print">🔍 Filter</button>
                    <button type="button" class="btn-print" style="background:#28a745;" onclick="window.print()">🖨️ طباعة A4</button>
                </form>
            </div>

            <!-- Summary -->
            <div class="summary-bar no-print">
                <div class="summary-item">
                    <div class="num" style="color:<?= $rt['color'] ?>;"><?= count($records) ?></div>
                    <div class="lbl">إجمالي السجلات</div>
                </div>
                <?php
                $unique_emps = count(array_unique(array_column($records, 'employee_id')));
                $total_days = 0;
                foreach ($records as $r) {
                    $d1 = max(strtotime($date_from), strtotime($r['start_date']));
                    $d2 = min(strtotime($date_to), strtotime($r['end_date']));
                    $total_days += max(0, ($d2 - $d1) / 86400 + 1);
                }
                ?>
                <div class="summary-item">
                    <div class="num" style="color:#0984e3;"><?= $unique_emps ?></div>
                    <div class="lbl">عامل / Employés</div>
                </div>
                <div class="summary-item">
                    <div class="num" style="color:#e67e22;"><?= intval($total_days) ?></div>
                    <div class="lbl">أيام إجمالية / Jours</div>
                </div>
            </div>

            <!-- Results Table -->
            <?php if (empty($records)): ?>
                <div style="text-align:center; padding:40px; background:white; border-radius:8px; color:#888;">
                    لا توجد سجلات في الفترة المحددة
                </div>
            <?php else: ?>
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الاسم / Nom</th>
                            <th>الرقم / Mat.</th>
                            <th>CIN</th>
                            <th>الوظيفة / Fonction</th>
                            <th>الموقع / Site</th>
                            <th>النوع / Type</th>
                            <th>من / Du</th>
                            <th>إلى / Au</th>
                            <th>أيام / Jours</th>
                            <th>رقم الشهادة / N° Cert.</th>
                            <th>ملاحظات / Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $row_num = 0;
                        foreach ($records as $r): 
                            $row_num++;
                            $days = (strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400 + 1;
                            $type_color = '#666';
                            if ($r['absence_type'] === 'M') $type_color = '#e74c3c';
                            elseif ($r['absence_type'] === 'MAT') $type_color = '#e91e63';
                            elseif ($r['absence_type'] === 'ACC') $type_color = '#ff9800';
                            elseif ($r['absence_type'] === 'CP') $type_color = '#2196f3';
                            elseif ($r['absence_type'] === 'MP') $type_color = '#795548';
                        ?>
                        <tr>
                            <td><?= $row_num ?></td>
                            <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                            <td><?= htmlspecialchars($r['matricule']) ?></td>
                            <td><?= htmlspecialchars($r['cin'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['function_title'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['location_name'] ?? '-') ?></td>
                            <td><span class="badge-type" style="background:<?= $type_color ?>;"><?= $type_labels[$r['absence_type']] ?? $r['absence_type'] ?></span></td>
                            <td><?= date('d/m/Y', strtotime($r['start_date'])) ?></td>
                            <td><?= date('d/m/Y', strtotime($r['end_date'])) ?></td>
                            <td style="text-align:center; font-weight:600;"><?= intval($days) ?></td>
                            <td><?= htmlspecialchars($r['certificate_number'] ?? '-') ?></td>
                            <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($r['comments'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f0f0f0; font-weight:bold;">
                            <td colspan="9" style="text-align:right;">المجموع / Total</td>
                            <td style="text-align:center;"><?= intval($total_days) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>

                <div style="text-align:center; margin-top:15px; font-size:0.85em; color:#888;" class="no-print">
                    <?= count($records) ?> سجل — <?= $unique_emps ?> عامل — <?= intval($total_days) ?> يوم
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- Print Footer -->
        <div class="print-footer">
            CandyTex — <?= $report_type ? htmlspecialchars($report_types[$report_type]['label'] ?? '') : '' ?> — 
            Période: <?= date('d/m/Y', strtotime($date_from)) ?> → <?= date('d/m/Y', strtotime($date_to)) ?> —
            Imprimé le: <?= date('d/m/Y H:i') ?> —
            <span class="page-number"></span>
        </div>
    </div>
</div>

</body>
</html>

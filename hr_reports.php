<?php
session_start();
require 'db.php';
require 'includes/auth.php';

if (!function_exists('is_hr_admin')) {
    function is_hr_admin() { return isset($_SESSION['role']) && $_SESSION['role'] === 'hr_admin'; }
}
require_hr_or_admin();

$is_admin_user = is_admin();
$is_hr_user = is_hr();
$is_hr_admin_u = is_hr_admin();
$is_restricted = $is_hr_user || $is_hr_admin_u;

$hr_location_id = null;
if ($is_restricted) {
    $stmt = $pdo->prepare("SELECT l.id FROM users u JOIN locations l ON u.location COLLATE utf8mb4_unicode_ci = l.name COLLATE utf8mb4_unicode_ci WHERE u.cin = ?");
    $stmt->execute([$_SESSION['user_cin']]);
    $hr_location_id = $stmt->fetchColumn();
    if (!$hr_location_id) { die("⛔ Location not assigned."); }
}

$report_types = [
    'NEW' => ['label' => 'تقرير العمال الجدد / Nouvelles Recrues', 'types' => [], 'icon' => '🆕', 'color' => '#4caf50'],
    'ABS' => ['label' => 'الغياب غير المبرر / Absences Injustifiées', 'types' => ['A'], 'icon' => '❓', 'color' => '#8e44ad'],
    'MAL' => ['label' => 'تقرير المرض / Maladie', 'types' => ['M'], 'icon' => '🏥', 'color' => '#e74c3c'],
    'MAT' => ['label' => 'تقرير الأمومة / Maternité', 'types' => ['MAT'], 'icon' => '🤱', 'color' => '#e91e63'],
    'ACC' => ['label' => 'تقرير حوادث الشغل / Accidents', 'types' => ['ACC'], 'icon' => '⚠️', 'color' => '#ff9800'],
    'CP'  => ['label' => 'تقرير الإجازات / Congés', 'types' => ['CP'], 'icon' => '🏖️', 'color' => '#2196f3'],
    'MP'  => ['label' => 'تقرير الإيقاف / Mise à Pied', 'types' => ['MP'], 'icon' => '🚫', 'color' => '#795548'],
    'ALL' => ['label' => 'تقرير شامل / Complet', 'types' => ['A','M','MAT','ACC','CP','MP','AUT'], 'icon' => '📋', 'color' => '#0b3c5d'],
];

$report_type = $_GET['report'] ?? '';
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to = $_GET['to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$location_filter = $_GET['location_id'] ?? '';
$is_newhires = ($report_type === 'NEW');

$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$type_labels = [
    'A' => 'Absence / غياب', 'M' => 'Maladie / مرض', 'MAT' => 'Maternité / أمومة', 'ACC' => 'Accident / حادث',
    'CP' => 'Congé / إجازة', 'MP' => 'Mise à Pied / إيقاف', 'AUT' => 'Autorisation / إذن',
];

// Fetch data
$records = [];
if ($report_type && isset($report_types[$report_type])) {
    if ($is_newhires) {
        $query = "SELECT e.*, l.name as location_name FROM hr_employees e LEFT JOIN locations l ON e.location_id = l.id WHERE e.hire_date BETWEEN ? AND ?";
        $params = [$date_from, $date_to];
    } else {
        $rt = $report_types[$report_type];
        $ph = implode(',', array_fill(0, count($rt['types']), '?'));
        $query = "SELECT a.*, e.matricule, e.full_name, e.function_title, e.department, e.cin, l.name as location_name
                  FROM hr_absences a JOIN hr_employees e ON a.employee_id = e.id LEFT JOIN locations l ON e.location_id = l.id
                  WHERE a.absence_type IN ($ph) AND (a.start_date BETWEEN ? AND ? OR a.end_date BETWEEN ? AND ? OR (a.start_date <= ? AND a.end_date >= ?))";
        $params = array_merge($rt['types'], [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to]);
    }
    if ($is_restricted && $hr_location_id) { $query .= " AND e.location_id = ?"; $params[] = $hr_location_id; }
    elseif ($location_filter) { $query .= " AND e.location_id = ?"; $params[] = $location_filter; }
    if ($search) { $query .= " AND (e.full_name LIKE ? OR e.matricule LIKE ? OR e.cin LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
    $query .= $is_newhires ? " ORDER BY e.hire_date DESC" : " ORDER BY a.start_date DESC";
    $stmt = $pdo->prepare($query); $stmt->execute($params); $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>HR Reports / تقارير الموارد البشرية</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .report-container { max-width: 1200px; margin: 0 auto; }
        .report-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .report-card {
            background: white; border-radius: 12px; padding: 20px; text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08); border-top: 4px solid #ccc;
            cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; text-decoration: none; color: inherit;
        }
        .report-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.15); }
        .report-card .icon { font-size: 2.5em; margin-bottom: 10px; }
        .report-card h3 { margin: 0; font-size: 0.95em; }
        .report-card .count { font-size: 2em; font-weight: bold; margin: 10px 0; }
        .filter-box { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-row .form-group { margin: 0; }
        .filter-row label { display: block; font-size: 0.85em; font-weight: 600; margin-bottom: 4px; }
        .filter-row input, .filter-row select { padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9em; }
        .btn-print { background: #0b3c5d; color: white; border: none; padding: 10px 25px; border-radius: 6px; cursor: pointer; font-size: 0.9em; font-weight: 600; }
        .btn-print:hover { background: #0a2d44; }
        .results-table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; }
        .results-table th { background: #0b3c5d; color: white; padding: 12px 10px; text-align: right; font-size: 0.85em; white-space: nowrap; }
        .results-table td { padding: 10px; border-bottom: 1px solid #eee; font-size: 0.85em; }
        .results-table tr:hover { background: #f8f9fa; }
        .badge-type { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 0.8em; font-weight: 600; color: white; }
        .summary-bar { display: flex; gap: 20px; margin-bottom: 15px; flex-wrap: wrap; }
        .summary-item { background: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
        .summary-item .num { font-size: 1.5em; font-weight: bold; }
        .summary-item .lbl { font-size: 0.8em; color: #666; }
        @media print {
            @page { size: A4 landscape; margin: 15mm; }
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
            .page-number::after { content: counter(page) " / " counter(pages); }
        }
        .print-header, .print-footer { display: none; }
    </style>
</head>
<body>
<?php include 'includes/nav.php'; ?>
<div class="main-content">
<div class="report-container">

    <!-- Print Header -->
    <div class="print-header">
        <div class="logo">🏭 CandyTex - <?= $report_type ? htmlspecialchars($report_types[$report_type]['label'] ?? 'Report') : 'HR Report' ?></div>
        <div class="doc-info">
            <strong>Date:</strong> <?= date('d/m/Y H:i') ?><br>
            <strong>Période:</strong> <?= date('d/m/Y', strtotime($date_from)) ?> → <?= date('d/m/Y', strtotime($date_to)) ?><br>
            <strong>Par:</strong> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?>
        </div>
    </div>

<?php if (!$report_type): ?>
    <!-- ========== REPORT SELECTION ========== -->
    <h2 style="text-align: center; margin-bottom: 25px;">📊 تقارير الموارد البشرية / Rapports RH</h2>
    
    <div class="filter-box no-print">
        <form method="GET" class="filter-row">
            <div class="form-group"><label>من تاريخ / Du</label><input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>"></div>
            <div class="form-group"><label>إلى تاريخ / Au</label><input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>"></div>
            <?php if ($is_admin_user): ?>
            <div class="form-group"><label>المصنع / Factory</label>
                <select name="location_id"><option value="">-- All --</option>
                <?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>" <?= $location_filter == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn-print">🔄 تحديث</button>
        </form>
    </div>

    <div class="report-cards">
        <?php foreach ($report_types as $key => $rt):
            if ($key === 'NEW') {
                $cq = "SELECT COUNT(*) FROM hr_employees e WHERE e.hire_date BETWEEN ? AND ?";
                $cp = [$date_from, $date_to];
            } else {
                $ph = implode(',', array_fill(0, count($rt['types']), '?'));
                $cq = "SELECT COUNT(*) FROM hr_absences a JOIN hr_employees e ON a.employee_id = e.id WHERE a.absence_type IN ($ph) AND (a.start_date BETWEEN ? AND ? OR a.end_date BETWEEN ? AND ? OR (a.start_date <= ? AND a.end_date >= ?))";
                $cp = array_merge($rt['types'], [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to]);
            }
            if ($is_restricted && $hr_location_id) { $cq .= " AND e.location_id = ?"; $cp[] = $hr_location_id; }
            elseif ($location_filter) { $cq .= " AND e.location_id = ?"; $cp[] = $location_filter; }
            $cs = $pdo->prepare($cq); $cs->execute($cp); $count = $cs->fetchColumn();
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

<?php elseif ($is_newhires): ?>
    <!-- ========== NEW HIRES REPORT ========== -->
    <?php $rt = $report_types['NEW']; ?>
    <div class="no-print" style="margin-bottom: 15px;"><a href="hr_reports.php" style="color: #0984e3; text-decoration: none;">← العودة للتقارير</a></div>
    <h2 class="no-print" style="margin-bottom: 15px;"><?= $rt['icon'] ?> <?= $rt['label'] ?> <span style="font-size:0.6em;color:#888;">(<?= date('d/m/Y', strtotime($date_from)) ?> → <?= date('d/m/Y', strtotime($date_to)) ?>)</span></h2>

    <div class="filter-box no-print">
        <form method="GET" class="filter-row">
            <input type="hidden" name="report" value="NEW">
            <div class="form-group" style="flex:1;min-width:180px;"><label>بحث</label><input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="اسم، رقم، CIN..." style="width:100%;"></div>
            <div class="form-group"><label>من</label><input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>"></div>
            <div class="form-group"><label>إلى</label><input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>"></div>
            <?php if ($is_admin_user): ?>
            <div class="form-group"><label>المصنع</label><select name="location_id"><option value="">-- All --</option><?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>" <?= $location_filter == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <button type="submit" class="btn-print">🔍 Filter</button>
            <button type="button" class="btn-print" style="background:#28a745;" onclick="window.print()">🖨️ طباعة A4</button>
        </form>
    </div>

    <div class="summary-bar no-print">
        <div class="summary-item"><div class="num" style="color:#4caf50;"><?= count($records) ?></div><div class="lbl">عمال جدد</div></div>
    </div>

    <?php if (empty($records)): ?>
        <div style="text-align:center;padding:40px;background:white;border-radius:8px;color:#888;">لا يوجد عمال جدد في الفترة المحددة</div>
    <?php else: ?>
        <table class="results-table">
            <thead><tr>
                <th>#</th><th>الاسم / Nom</th><th>الرقم</th><th>CIN</th><th>الوظيفة</th><th>القسم</th><th>الموقع</th>
                <th>تاريخ التعيين</th><th>العقد</th><th>الدفع</th><th>الأجر</th><th>الحالة</th>
            </tr></thead>
            <tbody>
                <?php $n=0; foreach ($records as $r): $n++; ?>
                <tr>
                    <td><?= $n ?></td>
                    <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                    <td><?= htmlspecialchars($r['matricule']) ?></td>
                    <td><?= htmlspecialchars($r['cin'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['function_title'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['department'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['location_name'] ?? '-') ?></td>
                    <td><?= $r['hire_date'] ? date('d/m/Y', strtotime($r['hire_date'])) : '-' ?></td>
                    <td><?= htmlspecialchars($r['contract_type'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['payment_type'] ?? '-') ?></td>
                    <td style="text-align:center;font-weight:600;"><?= number_format($r['hourly_rate'] ?? 0, 2) ?> <?= ($r['payment_type'] ?? '') === 'Monthly' ? 'MAD/m' : 'MAD/h' ?></td>
                    <td><span class="badge-type" style="background:<?= ($r['status'] ?? '') === 'Active' ? '#4caf50' : '#f44336' ?>;"><?= htmlspecialchars($r['status'] ?? 'Active') ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="background:#f0f0f0;font-weight:bold;"><td colspan="12" style="text-align:center;">المجموع: <?= count($records) ?> عامل جديد</td></tr></tfoot>
        </table>
    <?php endif; ?>

<?php else: ?>
    <!-- ========== ABSENCE REPORTS ========== -->
    <?php $rt = $report_types[$report_type]; ?>
    <div class="no-print" style="margin-bottom: 15px;"><a href="hr_reports.php" style="color: #0984e3; text-decoration: none;">← العودة للتقارير</a></div>
    <h2 class="no-print" style="margin-bottom: 15px;"><?= $rt['icon'] ?> <?= $rt['label'] ?> <span style="font-size:0.6em;color:#888;">(<?= date('d/m/Y', strtotime($date_from)) ?> → <?= date('d/m/Y', strtotime($date_to)) ?>)</span></h2>

    <div class="filter-box no-print">
        <form method="GET" class="filter-row">
            <input type="hidden" name="report" value="<?= htmlspecialchars($report_type) ?>">
            <div class="form-group" style="flex:1;min-width:180px;"><label>بحث</label><input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="اسم، رقم، CIN..." style="width:100%;"></div>
            <div class="form-group"><label>من</label><input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>"></div>
            <div class="form-group"><label>إلى</label><input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>"></div>
            <?php if ($is_admin_user): ?>
            <div class="form-group"><label>المصنع</label><select name="location_id"><option value="">-- All --</option><?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>" <?= $location_filter == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <button type="submit" class="btn-print">🔍 Filter</button>
            <button type="button" class="btn-print" style="background:#28a745;" onclick="window.print()">🖨️ طباعة A4</button>
        </form>
    </div>

    <div class="summary-bar no-print">
        <div class="summary-item"><div class="num" style="color:<?= $rt['color'] ?>;"><?= count($records) ?></div><div class="lbl">إجمالي السجلات</div></div>
        <?php
        $emp_total_days = [];
        $unique_emps = 0;
        $total_days = 0;
        foreach ($records as $r) {
            $eid = $r['employee_id'];
            if (!isset($emp_total_days[$eid])) {
                $emp_total_days[$eid] = 0;
                $unique_emps++;
            }
            $d1 = max(strtotime($date_from), strtotime($r['start_date']));
            $d2 = min(strtotime($date_to), strtotime($r['end_date']));
            $dys = max(0, ($d2 - $d1) / 86400 + 1);
            $emp_total_days[$eid] += $dys;
            $total_days += $dys;
        }
        ?>
        <div class="summary-item"><div class="num" style="color:#0984e3;"><?= $unique_emps ?></div><div class="lbl">عامل</div></div>
        <div class="summary-item"><div class="num" style="color:#e67e22;"><?= intval($total_days) ?></div><div class="lbl">أيام إجمالية</div></div>
    </div>

    <?php if (empty($records)): ?>
        <div style="text-align:center;padding:40px;background:white;border-radius:8px;color:#888;">لا توجد سجلات في الفترة المحددة</div>
    <?php else: ?>
        <table class="results-table">
            <thead><tr>
                <th onclick="sortTable(0)" style="cursor:pointer;" title="Sort"># ↕</th>
                <th onclick="sortTable(1)" style="cursor:pointer;" title="Sort">الاسم ↕</th>
                <th onclick="sortTable(2)" style="cursor:pointer;" title="Sort">الرقم ↕</th>
                <th onclick="sortTable(3)" style="cursor:pointer;" title="Sort">CIN ↕</th>
                <th onclick="sortTable(4)" style="cursor:pointer;" title="Sort">الوظيفة ↕</th>
                <th onclick="sortTable(5)" style="cursor:pointer;" title="Sort">الموقع ↕</th>
                <th onclick="sortTable(6)" style="cursor:pointer;" title="Sort">النوع ↕</th>
                <th onclick="sortTable(7)" style="cursor:pointer;" title="Sort">من ↕</th>
                <th onclick="sortTable(8)" style="cursor:pointer;" title="Sort">إلى ↕</th>
                <th onclick="sortTable(9)" style="cursor:pointer;" title="Sort">أيام ↕</th>
                <th>رقم الشهادة</th><th>ملاحظات</th>
            </tr></thead>
            <tbody>
                <?php $n=0; foreach ($records as $r): $n++;
                    $days = (strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400 + 1;
                    $tc = '#666';
                    if ($r['absence_type']==='M') $tc='#e74c3c'; elseif ($r['absence_type']==='MAT') $tc='#e91e63';
                    elseif ($r['absence_type']==='ACC') $tc='#ff9800'; elseif ($r['absence_type']==='CP') $tc='#2196f3';
                    elseif ($r['absence_type']==='MP') $tc='#795548'; elseif ($r['absence_type']==='A') $tc='#8e44ad';
                    
                    $tot_abs = $emp_total_days[$r['employee_id']];
                    $row_bg = '';
                    if (in_array($report_type, ['ABS', 'MAL', 'ALL'])) {
                        if ($tot_abs >= 5) $row_bg = 'background-color: #ffebee;'; // Red / Severe
                        elseif ($tot_abs >= 3) $row_bg = 'background-color: #fff3e0;'; // Orange / Warning
                        elseif ($tot_abs >= 2) $row_bg = 'background-color: #fffde7;'; // Yellow / Notice
                    }
                ?>
                <tr style="<?= $row_bg ?>">
                    <td><?= $n ?></td>
                    <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                    <td><?= htmlspecialchars($r['matricule']) ?></td>
                    <td><?= htmlspecialchars($r['cin'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['function_title'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['location_name'] ?? '-') ?></td>
                    <td><span class="badge-type" style="background:<?= $tc ?>;"><?= $type_labels[$r['absence_type']] ?? $r['absence_type'] ?></span></td>
                    <td><?= date('d/m/Y', strtotime($r['start_date'])) ?></td>
                    <td><?= date('d/m/Y', strtotime($r['end_date'])) ?></td>
                    <td style="text-align:center;font-weight:600;"><?= intval($days) ?></td>
                    <td><?= htmlspecialchars($r['certificate_number'] ?? '-') ?></td>
                    <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($r['comments'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="background:#f0f0f0;font-weight:bold;"><td colspan="9" style="text-align:right;">المجموع</td><td style="text-align:center;"><?= intval($total_days) ?></td><td colspan="2"></td></tr></tfoot>
        </table>
    <?php endif; ?>

<?php endif; ?>

    <div class="print-footer">
        CandyTex — <?= $report_type ? htmlspecialchars($report_types[$report_type]['label'] ?? '') : '' ?> — 
        <?= date('d/m/Y', strtotime($date_from)) ?> → <?= date('d/m/Y', strtotime($date_to)) ?> —
        <?= date('d/m/Y H:i') ?> — <span class="page-number"></span>
    </div>
</div>
</div>
<script>
function sortTable(n) {
  var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
  table = document.querySelector(".results-table");
  if (!table) return;
  switching = true;
  dir = "asc"; 
  while (switching) {
    switching = false;
    rows = table.rows;
    // skip header row and footer row
    for (i = 1; i < (rows.length - 2); i++) {
      shouldSwitch = false;
      x = rows[i].getElementsByTagName("TD")[n];
      y = rows[i + 1].getElementsByTagName("TD")[n];
      if (!x || !y) continue;
      
      let xContent = x.innerHTML.replace(/<[^>]*>?/gm, '').trim();
      let yContent = y.innerHTML.replace(/<[^>]*>?/gm, '').trim();
      
      let xNum = parseFloat(xContent);
      let yNum = parseFloat(yContent);
      let isNum = !isNaN(xNum) && !isNaN(yNum) && xContent.match(/^[0-9.]+$/) && yContent.match(/^[0-9.]+$/);
      
      if (dir == "asc") {
        if (isNum ? (xNum > yNum) : (xContent.toLowerCase() > yContent.toLowerCase())) {
          shouldSwitch = true;
          break;
        }
      } else if (dir == "desc") {
        if (isNum ? (xNum < yNum) : (xContent.toLowerCase() < yContent.toLowerCase())) {
          shouldSwitch = true;
          break;
        }
      }
    }
    if (shouldSwitch) {
      rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
      switching = true;
      switchcount ++;      
    } else {
      if (switchcount == 0 && dir == "asc") {
        dir = "desc";
        switching = true;
      }
    }
  }
}
</script>
</body>
</html>
